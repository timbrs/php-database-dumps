<?php

namespace Timbrs\DatabaseDumps\Service\Validation\Rule;

use Timbrs\DatabaseDumps\Config\TableConfig;
use Timbrs\DatabaseDumps\Service\Analysis\CriteriaValidator;
use Timbrs\DatabaseDumps\Service\Validation\AuditContext;
use Timbrs\DatabaseDumps\Service\Validation\Finding;

/**
 * Существование колонок: каждое имя колонки из конфига сверяется со слепком схемы.
 *
 *  L-1 order_by, L-2 where, L-3 cascade_from.fk_column / parent_column,
 *  L-4 sample.criteria[].where, L-5 sample.stratify_by, L-6 ключ faker,
 *  L-7 deferred_columns.
 *
 * Таблицы, которых в слепке нет (находка C-2), пропускаются: сверять не с чем, и повторный
 * шум только мешает. Выражения в order_by (функции, приведения) не разбираются — проверяются
 * только простые идентификаторы.
 */
class ColumnExistenceRule implements RuleInterface
{
    /** Приписка к находке, когда имена разрешались внутри подзапроса. */
    private const SUBQUERY_NOTE = ' (внутри условия есть подзапрос — имена разрешаются приблизительно, проверьте вручную)';

    /** @var CriteriaValidator */
    private $criteriaValidator;

    public function __construct(?CriteriaValidator $criteriaValidator = null)
    {
        $this->criteriaValidator = $criteriaValidator ?? new CriteriaValidator();
    }

    public function name(): string
    {
        return 'колонки';
    }

    public function apply(AuditContext $context): array
    {
        $findings = [];
        $inventory = $context->inventory();

        foreach ($context->scopedSchemas() as $schema) {
            foreach ($context->config()->getPartialExport($schema) as $table => $_conf) {
                $table = (string) $table;
                $config = $context->tableConfig($schema, $table);
                if ($config === null || !$inventory->hasTable($schema, $table)) {
                    continue;
                }
                $columns = $inventory->columns($schema, $table);

                $findings = array_merge(
                    $findings,
                    $this->checkOrderBy($config, $schema, $table, $columns),
                    $this->checkWhere($context, $config, $schema, $table, $columns),
                    $this->checkCascade($context, $config, $schema, $table, $columns),
                    $this->checkSample($context, $config, $schema, $table, $columns),
                    $this->checkDeferred($config, $schema, $table, $columns)
                );
            }

            $findings = array_merge($findings, $this->checkFaker($context, $schema));
        }

        return $findings;
    }

    /**
     * @param array<int, string> $columns
     * @return array<int, Finding>
     */
    private function checkOrderBy(TableConfig $config, string $schema, string $table, array $columns): array
    {
        $orderBy = $config->getOrderBy();
        if ($orderBy === null || $orderBy === '') {
            return [];
        }

        $known = $this->lowerSet($columns);
        $findings = [];
        foreach (explode(',', $orderBy) as $part) {
            $expression = trim($part);
            if ($expression === '') {
                continue;
            }
            // Отрезаем направление и NULLS FIRST/LAST — остаётся выражение сортировки.
            $expression = preg_replace('/\s+(asc|desc)\b/i', '', $expression);
            $expression = $expression === null ? '' : $expression;
            $expression = preg_replace('/\s+nulls\s+(first|last)\b/i', '', $expression);
            $expression = $expression === null ? '' : trim($expression);

            // Не простой идентификатор (функция, приведение, точечная ссылка) — не наше дело.
            if ($expression === '' || preg_match('/^[\p{L}_][\p{L}\p{N}_$]*$/u', $expression) !== 1) {
                continue;
            }
            if (isset($known[strtolower($expression)])) {
                continue;
            }
            $findings[] = Finding::error(
                'L-1',
                sprintf('order_by ссылается на несуществующую колонку «%s» — экспорт таблицы упадёт', $expression),
                $schema,
                $table,
                $expression,
                false,
                ['hint' => 'исправить имя колонки или убрать order_by']
            );
        }

        return $findings;
    }

    /**
     * @param array<int, string> $columns
     * @return array<int, Finding>
     */
    private function checkWhere(
        AuditContext $context,
        TableConfig $config,
        string $schema,
        string $table,
        array $columns
    ): array {
        $where = $config->getWhere();
        if ($where === null || $where === '') {
            return [];
        }
        $unknown = $this->criteriaValidator->unknownColumns($where, $this->visibleNames($context, $schema, $table, $columns, $where));
        if (empty($unknown)) {
            return [];
        }

        $message = sprintf(
            'where ссылается на идентификаторы, которых нет среди колонок таблицы: %s',
            implode(', ', $unknown)
        );
        $suggestion = ['unknown' => $unknown, 'where' => $where];

        return [$this->hasSubquery($where)
            ? Finding::warning('L-2', $message . self::SUBQUERY_NOTE, $schema, $table, null, false, $suggestion)
            : Finding::error('L-2', $message, $schema, $table, null, false, $suggestion)];
    }

    /**
     * @param array<int, string> $columns
     * @return array<int, Finding>
     */
    private function checkCascade(
        AuditContext $context,
        TableConfig $config,
        string $schema,
        string $table,
        array $columns
    ): array {
        $cascade = $config->getCascadeFrom();
        if (empty($cascade)) {
            return [];
        }

        $known = $this->lowerSet($columns);
        $inventory = $context->inventory();
        $findings = [];

        foreach ($cascade as $index => $entry) {
            $index = (int) $index;
            $dead = [];

            if (!isset($known[strtolower($entry['fk_column'])])) {
                $dead[] = sprintf('fk_column «%s» нет у %s.%s', $entry['fk_column'], $schema, $table);
            }

            $parentParts = explode('.', $entry['parent'], 2);
            if (count($parentParts) === 2 && $inventory->hasTable($parentParts[0], $parentParts[1])) {
                if (!$inventory->hasColumn($parentParts[0], $parentParts[1], $entry['parent_column'])) {
                    $dead[] = sprintf(
                        'parent_column «%s» нет у %s',
                        $entry['parent_column'],
                        $entry['parent']
                    );
                }
            }

            if (empty($dead)) {
                continue;
            }

            $findings[] = Finding::error(
                'L-3',
                sprintf(
                    'cascade_from[%d] ссылается на несуществующую колонку (%s) — CascadeWhereResolver '
                    . 'подставит её в WHERE как есть, и export упадёт на этой таблице',
                    $index,
                    implode('; ', $dead)
                ),
                $schema,
                $table,
                null,
                true,
                [
                    'fix' => 'remove_cascade_entry',
                    'index' => $index,
                    'entry' => $entry,
                    'hint' => 'убрать мёртвую запись cascade_from или исправить имена колонок',
                ]
            );
        }

        return $findings;
    }

    /**
     * @param array<int, string> $columns
     * @return array<int, Finding>
     */
    private function checkSample(
        AuditContext $context,
        TableConfig $config,
        string $schema,
        string $table,
        array $columns
    ): array {
        $sample = $config->getSample();
        if ($sample === null) {
            return [];
        }

        $findings = [];

        $criteria = isset($sample[TableConfig::SAMPLE_KEY_CRITERIA]) ? $sample[TableConfig::SAMPLE_KEY_CRITERIA] : [];
        if (is_array($criteria)) {
            foreach ($criteria as $index => $criterion) {
                if (!is_array($criterion) || !isset($criterion[TableConfig::CRITERION_KEY_WHERE])) {
                    continue;
                }
                $where = (string) $criterion[TableConfig::CRITERION_KEY_WHERE];
                $unknown = $this->criteriaValidator->unknownColumns(
                    $where,
                    $this->visibleNames($context, $schema, $table, $columns, $where)
                );
                if (empty($unknown)) {
                    continue;
                }
                $name = isset($criterion[TableConfig::CRITERION_KEY_NAME])
                    ? (string) $criterion[TableConfig::CRITERION_KEY_NAME]
                    : (string) $index;
                $message = sprintf(
                    'критерий «%s» ссылается на идентификаторы, которых нет среди колонок: %s',
                    $name,
                    implode(', ', $unknown)
                );
                $suggestion = ['criterion' => $name, 'unknown' => $unknown, 'where' => $where];
                $findings[] = $this->hasSubquery($where)
                    ? Finding::warning('L-4', $message . self::SUBQUERY_NOTE, $schema, $table, null, false, $suggestion)
                    : Finding::error('L-4', $message, $schema, $table, null, false, $suggestion);
            }
        }

        $known = $this->lowerSet($columns);
        foreach (TableConfig::stratifyColumns($sample) as $stratifyBy) {
            if (!isset($known[strtolower($stratifyBy)])) {
                $findings[] = Finding::error(
                    'L-5',
                    sprintf('stratify_by ссылается на несуществующую колонку «%s» — выборка упадёт', $stratifyBy),
                    $schema,
                    $table,
                    $stratifyBy
                );
            }
        }

        foreach (TableConfig::stratifySpecs($sample) as $spec) {
            $then = $spec['then'];
            if ($then !== null && !isset($known[strtolower($then['column'])])) {
                $findings[] = Finding::error(
                    'L-5',
                    sprintf('stratify[].then ссылается на несуществующую колонку «%s» — выборка упадёт', $then['column']),
                    $schema,
                    $table,
                    $then['column']
                );
            }
        }

        foreach (TableConfig::stratifyVia($sample) as $via) {
            foreach ($via['join'] as $viaColumn => $localColumn) {
                if (!isset($known[strtolower($localColumn)])) {
                    $findings[] = Finding::error(
                        'L-5',
                        sprintf('stratify_via[%s].join ссылается на несуществующую колонку «%s» этой таблицы — выборка упадёт', $via['table'], $localColumn),
                        $schema,
                        $table,
                        $localColumn
                    );
                }
            }
            $parts = explode('.', $via['table'], 2);
            if (count($parts) !== 2) {
                continue;
            }
            $viaColumns = $context->inventory()->columns($parts[0], $parts[1]);
            if ($viaColumns === []) {
                $findings[] = Finding::error(
                    'L-5',
                    sprintf('stratify_via ссылается на таблицу «%s», которой нет в слепке — выборка упадёт', $via['table']),
                    $schema,
                    $table
                );
                continue;
            }
            $viaKnown = $this->lowerSet($viaColumns);
            $expected = array_merge([$via['column']], array_map('strval', array_keys($via['join'])));
            foreach ($expected as $viaColumn) {
                if (!isset($viaKnown[strtolower($viaColumn)])) {
                    $findings[] = Finding::error(
                        'L-5',
                        sprintf('stratify_via ссылается на несуществующую колонку «%s» таблицы %s — выборка упадёт', $viaColumn, $via['table']),
                        $schema,
                        $table,
                        $viaColumn
                    );
                }
            }
        }

        return $findings;
    }

    /**
     * @param array<int, string> $columns
     * @return array<int, Finding>
     */
    private function checkDeferred(TableConfig $config, string $schema, string $table, array $columns): array
    {
        $deferred = $config->getDeferredColumns();
        if (empty($deferred)) {
            return [];
        }

        $known = $this->lowerSet($columns);
        $findings = [];

        foreach ($deferred as $index => $entry) {
            if (isset($known[strtolower($entry['column'])])) {
                continue;
            }
            $findings[] = Finding::error(
                'L-7',
                sprintf(
                    'deferred_columns[%d].column «%s» отсутствует у таблицы — отложенный UPDATE не соберётся',
                    (int) $index,
                    $entry['column']
                ),
                $schema,
                $table,
                $entry['column']
            );
        }

        return $findings;
    }

    /**
     * @return array<int, Finding>
     */
    private function checkFaker(AuditContext $context, string $schema): array
    {
        $inventory = $context->inventory();
        $findings = [];

        foreach ($context->config()->getFaker($schema) as $table => $map) {
            $table = (string) $table;
            if (!$inventory->hasTable($schema, $table)) {
                continue;
            }
            $known = $this->lowerSet($inventory->columns($schema, $table));
            foreach ($map as $column => $pattern) {
                $column = (string) $column;
                if (isset($known[strtolower($column)])) {
                    continue;
                }
                $findings[] = Finding::warning(
                    'L-6',
                    sprintf(
                        'faker настроен на колонку «%s», которой нет у таблицы — маскирование не сработает',
                        $column
                    ),
                    $schema,
                    $table,
                    $column,
                    true,
                    [
                        'fix' => 'remove_faker_column',
                        'table' => $table,
                        'column' => $column,
                        'pattern' => $pattern,
                        'hint' => 'убрать маппинг несуществующей колонки',
                    ]
                );
            }
        }

        return $findings;
    }


    /**
     * Имена, которые допустимо встретить в WHERE этой таблицы: её колонки, её собственные
     * имя и схема (квалифицированные ссылки вида «table.column») и всё, что приносит с собой
     * подзапрос — имена схемы/таблицы из FROM/JOIN и колонки этих таблиц по слепку.
     *
     * Без этого EXISTS-подзапрос выглядит как набор неизвестных колонок, хотя SQL корректен.
     *
     * @param array<int, string> $columns
     * @return array<int, string>
     */
    private function visibleNames(
        AuditContext $context,
        string $schema,
        string $table,
        array $columns,
        string $where
    ): array {
        $names = $columns;
        $names[] = $table;
        $names[] = $schema;

        if (!$this->hasSubquery($where)) {
            return $names;
        }

        $pattern = '/\b(?:from|join)\s+([\p{L}_][\p{L}\p{N}_$]*)(?:\s*\.\s*([\p{L}_][\p{L}\p{N}_$]*))?/iu';
        if (preg_match_all($pattern, $where, $matches, PREG_SET_ORDER) === false) {
            return $names;
        }

        foreach ($matches as $match) {
            $hasSchema = isset($match[2]) && $match[2] !== '';
            $refSchema = $hasSchema ? $match[1] : $schema;
            $refTable = $hasSchema ? $match[2] : $match[1];

            $names[] = $match[1];
            if ($hasSchema) {
                $names[] = $match[2];
            }
            foreach ($context->inventory()->columns($refSchema, $refTable) as $column) {
                $names[] = $column;
            }
        }

        return $names;
    }

    /**
     * Есть ли внутри условия подзапрос — тогда имена разрешаются лишь приблизительно.
     */
    private function hasSubquery(string $where): bool
    {
        return preg_match('/\bselect\b/i', $where) === 1;
    }

    /**
     * @param array<int, string> $columns
     * @return array<string, bool>
     */
    private function lowerSet(array $columns): array
    {
        $set = [];
        foreach ($columns as $column) {
            $set[strtolower($column)] = true;
        }
        return $set;
    }
}
