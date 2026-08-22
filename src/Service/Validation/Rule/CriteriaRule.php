<?php

namespace Timbrs\DatabaseDumps\Service\Validation\Rule;

use Timbrs\DatabaseDumps\Config\TableConfig;
use Timbrs\DatabaseDumps\Service\Analysis\CriteriaValidator;
use Timbrs\DatabaseDumps\Service\Dumper\SampleQueryBuilder;
use Timbrs\DatabaseDumps\Service\Validation\AuditContext;
use Timbrs\DatabaseDumps\Service\Validation\Finding;

/**
 * Пригодность sample.criteria для дампера — поверх CriteriaValidator, который уже знает,
 * что SampleQueryBuilder выполняет ОДНОТАБЛИЧНЫЙ SELECT без JOIN и без bind-параметров.
 *
 *  Q-1 — алиас таблицы (t1.);
 *  Q-2 — bind-параметр (:name);
 *  Q-3 — непригодны ВСЕ критерии таблицы: SampleQueryBuilder пропустит каждый и молча
 *        свалится на плоский срез по limit — сегментов выборки не будет;
 *  Q-4 — повторяющиеся имена критериев внутри таблицы;
 *  Q-5 — сумма квот (плюс худший случай stratify_by × per_value) больше limit: лишние
 *        строки обрежет array_slice в SampleQueryBuilder, и обрежет молча.
 */
class CriteriaRule implements RuleInterface
{
    /** @var CriteriaValidator */
    private $criteriaValidator;

    public function __construct(?CriteriaValidator $criteriaValidator = null)
    {
        $this->criteriaValidator = $criteriaValidator ?? new CriteriaValidator();
    }

    public function name(): string
    {
        return 'критерии';
    }

    public function apply(AuditContext $context): array
    {
        $findings = [];

        foreach ($context->scopedSchemas() as $schema) {
            foreach ($context->config()->getPartialExport($schema) as $table => $_conf) {
                $table = (string) $table;
                $config = $context->tableConfig($schema, $table);
                if ($config === null || !$config->hasSample()) {
                    continue;
                }
                $sample = $config->getSample();
                if ($sample === null) {
                    continue;
                }

                $criteria = isset($sample[TableConfig::SAMPLE_KEY_CRITERIA])
                    && is_array($sample[TableConfig::SAMPLE_KEY_CRITERIA])
                    ? $sample[TableConfig::SAMPLE_KEY_CRITERIA]
                    : [];

                $findings = array_merge(
                    $findings,
                    $this->checkSyntax($criteria, $schema, $table),
                    $this->checkDuplicateNames($criteria, $schema, $table),
                    $this->checkQuota($context, $config, $sample, $criteria, $schema, $table)
                );
            }
        }

        return $findings;
    }

    /**
     * @param array<int|string, mixed> $criteria
     * @return array<int, Finding>
     */
    private function checkSyntax(array $criteria, string $schema, string $table): array
    {
        if (empty($criteria)) {
            return [];
        }

        $findings = [];
        $total = 0;
        $broken = 0;

        foreach ($criteria as $index => $criterion) {
            if (!is_array($criterion) || !isset($criterion[TableConfig::CRITERION_KEY_WHERE])) {
                continue;
            }
            $total++;
            $where = (string) $criterion[TableConfig::CRITERION_KEY_WHERE];
            $name = isset($criterion[TableConfig::CRITERION_KEY_NAME])
                ? (string) $criterion[TableConfig::CRITERION_KEY_NAME]
                : (string) $index;

            $hasAlias = preg_match('/\bt\d+\s*\./i', $where) === 1;
            $hasBind = preg_match('/(?<![:\w]):[A-Za-z_]\w*/', $where) === 1;

            if ($hasAlias) {
                $findings[] = Finding::error(
                    'Q-1',
                    sprintf(
                        'критерий «%s» содержит алиас таблицы — дампер выполняет однотабличный SELECT без JOIN, '
                        . 'критерий будет пропущен: %s',
                        $name,
                        $where
                    ),
                    $schema,
                    $table,
                    null,
                    false,
                    ['criterion' => $name, 'where' => $where, 'hint' => 'убрать префикс алиаса из имён колонок']
                );
            }
            if ($hasBind) {
                $findings[] = Finding::error(
                    'Q-2',
                    sprintf(
                        'критерий «%s» содержит bind-параметр — дампер не заполняет параметры, нужны литералы: %s',
                        $name,
                        $where
                    ),
                    $schema,
                    $table,
                    null,
                    false,
                    ['criterion' => $name, 'where' => $where, 'hint' => 'подставить литеральное значение']
                );
            }
            if (!$this->criteriaValidator->isDumpable($where)) {
                $broken++;
            }
        }

        if ($total > 0 && $broken === $total) {
            $findings[] = Finding::error(
                'Q-3',
                sprintf(
                    'непригодны все %d критериев — SampleQueryBuilder пропустит каждый и выгрузит таблицу '
                    . 'плоским срезом по limit, сегменты выборки потеряются',
                    $total
                ),
                $schema,
                $table
            );
        }

        return $findings;
    }

    /**
     * @param array<int|string, mixed> $criteria
     * @return array<int, Finding>
     */
    private function checkDuplicateNames(array $criteria, string $schema, string $table): array
    {
        $seen = [];
        $findings = [];

        foreach ($criteria as $index => $criterion) {
            if (!is_array($criterion) || !isset($criterion[TableConfig::CRITERION_KEY_NAME])) {
                continue;
            }
            $name = (string) $criterion[TableConfig::CRITERION_KEY_NAME];
            if (!isset($seen[$name])) {
                $seen[$name] = true;
                continue;
            }

            $findings[] = Finding::warning(
                'Q-4',
                sprintf(
                    'имя критерия «%s» встречается повторно — сегменты выборки не различить в логе и в правках',
                    $name
                ),
                $schema,
                $table,
                null,
                true,
                [
                    'fix' => 'rename_criterion',
                    'index' => (int) $index,
                    'name' => $name,
                    'hint' => 'переименовать дубль',
                ]
            );
        }

        return $findings;
    }

    /**
     * @param array<string, mixed> $sample
     * @param array<int|string, mixed> $criteria
     * @return array<int, Finding>
     */
    private function checkQuota(
        AuditContext $context,
        TableConfig $config,
        array $sample,
        array $criteria,
        string $schema,
        string $table
    ): array {
        $limit = $config->getLimit();
        if ($limit === null || $limit <= 0) {
            return [];
        }

        $quota = 0;
        foreach ($criteria as $criterion) {
            if (is_array($criterion) && isset($criterion[TableConfig::CRITERION_KEY_LIMIT])) {
                $quota += (int) $criterion[TableConfig::CRITERION_KEY_LIMIT];
            }
        }

        $stratifyBy = isset($sample[TableConfig::SAMPLE_KEY_STRATIFY_BY])
            ? $sample[TableConfig::SAMPLE_KEY_STRATIFY_BY]
            : null;
        $buckets = 0;
        if (is_string($stratifyBy) && $stratifyBy !== '') {
            $perValue = isset($sample[TableConfig::SAMPLE_KEY_PER_VALUE])
                ? (int) $sample[TableConfig::SAMPLE_KEY_PER_VALUE]
                : TableConfig::DEFAULT_PER_VALUE;
            $buckets = $this->bucketCount($context, $schema, $table, $stratifyBy);
            $quota += $buckets * $perValue;
        }

        if ($quota <= $limit) {
            return [];
        }

        return [Finding::warning(
            'Q-5',
            sprintf(
                'сумма квот выборки (%d%s) больше limit (%d) — объединённую выборку молча обрежет array_slice, '
                . 'последние сегменты в дамп не попадут',
                $quota,
                $buckets > 0 ? sprintf(', включая %d корзин stratify_by', $buckets) : '',
                $limit
            ),
            $schema,
            $table,
            null,
            false,
            ['quota' => $quota, 'limit' => $limit, 'hint' => 'поднять limit или урезать квоты критериев']
        )];
    }

    /**
     * Сколько корзин даст stratify_by: реальное число значений из профиля, если оно
     * известно и не упёрлось в потолок профилировщика, иначе — потолок дампера.
     */
    private function bucketCount(AuditContext $context, string $schema, string $table, string $column): int
    {
        $max = SampleQueryBuilder::MAX_STRATIFY_BUCKETS;
        $profile = $context->inventory()->profile($schema, $table, $column);
        if ($profile === null || !isset($profile['distinct_count'])) {
            return $max;
        }
        if (!empty($profile['distinct_capped'])) {
            return $max;
        }
        $distinct = (int) $profile['distinct_count'];
        return $distinct > 0 && $distinct < $max ? $distinct : $max;
    }
}
