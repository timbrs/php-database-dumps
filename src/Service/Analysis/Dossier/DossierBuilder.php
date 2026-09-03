<?php

namespace Timbrs\DatabaseDumps\Service\Analysis\Dossier;

use Timbrs\DatabaseDumps\Config\DumpConfig;
use Timbrs\DatabaseDumps\Config\TableConfig;

/**
 * Досье: всё, что инструмент знает о таблице и каждой её колонке, — в одном файле на схему.
 *
 * Инвентарь отвечает на «что есть в БД», код-хинты — «где это в коде», конфиг — «как мы это
 * выгружаем». Порознь этого мало: решение «нужен ли тут разрез по attr_id» рождается ровно на
 * стыке трёх источников. Досье сводит их и честно помечает места, где источники расходятся или
 * молчат (`ambiguous`), — это и есть вопросы, с которыми идут к агенту по коду.
 *
 * Значений данных в досье нет: только типы, кардинальность и коды, прошедшие PII-шлюз.
 *
 * PHP 7.2-совместимо.
 */
class DossierBuilder
{
    /** Причины неоднозначности — их разбирает агент, а не инструмент. */
    public const WHY_ENUM_DB_MISMATCH = 'enum_db_mismatch';
    public const WHY_NO_CODE_MENTIONS = 'no_code_mentions';
    public const WHY_ENUM_CANDIDATES = 'enum_candidates';
    public const WHY_COVERAGE_GAP = 'coverage_gap';
    public const WHY_NO_ENUM_FOR_CODES = 'no_enum_for_codes';

    /** @var MigrationScanner|null */
    private $migrations;

    /** @var ViewScanner|null */
    private $views;

    public function __construct(MigrationScanner $migrations = null, ViewScanner $views = null)
    {
        $this->migrations = $migrations;
        $this->views = $views;
    }

    /**
     * Досье по одной схеме.
     *
     * @param array<string, mixed> $inventory слепок целиком (как его отдаёт AnalysisPackageBuilder)
     *
     * @return array<string, mixed>
     */
    public function build(string $schema, array $inventory, DumpConfig $dumpConfig): array
    {
        $tables = isset($inventory['schemas'][$schema]['tables']) && is_array($inventory['schemas'][$schema]['tables'])
            ? $inventory['schemas'][$schema]['tables']
            : [];

        $inDegree = $this->countInDegree($inventory);
        $migrations = $this->migrations !== null ? $this->migrations->scan() : [];
        $views = $this->views !== null ? $this->views->scan($schema) : [];

        $out = [];
        foreach ($tables as $table => $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $key = $schema . '.' . $table;
            $out[(string) $table] = $this->buildTable(
                $schema,
                (string) $table,
                $entry,
                $dumpConfig,
                isset($inDegree[$key]) ? $inDegree[$key] : [],
                isset($migrations[$key]) ? $migrations[$key] : null,
                isset($views[$key]) ? $views[$key] : []
            );
        }
        // Таблицы, настроенные в конфиге, которых в слепке нет: их не выгрузит ни один экспорт,
        // и молча — поэтому они попадают в досье помеченными, а не пропадают вовсе (R7).
        foreach ($this->configuredTables($schema, $dumpConfig) as $table) {
            if (isset($out[$table])) {
                continue;
            }
            $raw = $dumpConfig->getTableConfig($schema, $table);
            $out[$table] = [
                'phantom' => true,
                'row_count' => ['value' => null, 'estimated' => false, 'source' => null],
                'config' => [
                    'mode' => in_array($table, $dumpConfig->getFullExportTables($schema), true) ? 'full_export' : 'partial_export',
                    'limit' => $raw !== null && isset($raw[TableConfig::KEY_LIMIT]) ? $raw[TableConfig::KEY_LIMIT] : null,
                    'where' => null,
                    'order_by' => null,
                    'sample' => null,
                    'cascade_from' => null,
                ],
                'traits' => [],
                'edges' => [],
                'views' => [],
                'migrations' => null,
                'code' => [],
                'columns' => [],
                'ambiguous' => [],
            ];
        }
        ksort($out);

        return [
            'generated_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'schema' => $schema,
            'inventory_generated_at' => isset($inventory['generated_at']) ? $inventory['generated_at'] : null,
            'tables' => $out,
        ];
    }

    /**
     * Таблицы схемы, названные в конфиге (обе секции).
     *
     * @return array<int, string>
     */
    private function configuredTables(string $schema, DumpConfig $dumpConfig): array
    {
        $tables = $dumpConfig->getFullExportTables($schema);
        foreach ($dumpConfig->getPartialExportTables($schema) as $table => $_) {
            $tables[] = (string) $table;
        }

        return array_values(array_unique($tables));
    }

    /**
     * @param array<string, mixed>                       $entry
     * @param array<int, array<string, mixed>>           $inDegree
     * @param array<string, mixed>|null                  $migration
     * @param array<int, string>                         $views
     *
     * @return array<string, mixed>
     */
    private function buildTable(
        string $schema,
        string $table,
        array $entry,
        DumpConfig $dumpConfig,
        array $inDegree,
        $migration,
        array $views
    ): array {
        $raw = $dumpConfig->getTableConfig($schema, $table);
        $isFull = in_array($table, $dumpConfig->getFullExportTables($schema), true);
        $codeHints = isset($entry['code_hints']) && is_array($entry['code_hints']) ? $entry['code_hints'] : [];
        $hintColumns = isset($codeHints['columns']) && is_array($codeHints['columns']) ? $codeHints['columns'] : [];

        $columns = [];
        foreach ($this->columnList($entry) as $column => $meta) {
            $columns[$column] = $this->buildColumn(
                $column,
                $meta,
                isset($entry['profiles']) && is_array($entry['profiles']) ? $entry['profiles'] : [],
                isset($hintColumns[$column]) && is_array($hintColumns[$column]) ? $hintColumns[$column] : [],
                $raw,
                $isFull
            );
        }

        $edges = $this->edges($schema, $table, $entry, $raw, $inDegree, $codeHints);

        return [
            'row_count' => [
                'value' => isset($entry['row_count']) ? $entry['row_count'] : null,
                'estimated' => !empty($entry['row_count_estimated']),
                'source' => isset($entry['row_count_source']) ? $entry['row_count_source'] : null,
            ],
            'config' => [
                'mode' => $isFull ? 'full_export' : ($raw !== null ? 'partial_export' : 'not_exported'),
                'limit' => $raw !== null && isset($raw[TableConfig::KEY_LIMIT]) ? $raw[TableConfig::KEY_LIMIT] : null,
                'where' => $raw !== null && isset($raw[TableConfig::KEY_WHERE]) ? $raw[TableConfig::KEY_WHERE] : null,
                'order_by' => $raw !== null && isset($raw[TableConfig::KEY_ORDER_BY]) ? $raw[TableConfig::KEY_ORDER_BY] : null,
                'sample' => $raw !== null && isset($raw[TableConfig::KEY_SAMPLE]) ? $raw[TableConfig::KEY_SAMPLE] : null,
                'cascade_from' => $raw !== null && isset($raw[TableConfig::KEY_CASCADE_FROM]) ? $raw[TableConfig::KEY_CASCADE_FROM] : null,
            ],
            'traits' => $this->traits($table, $entry, $columns, count($inDegree)),
            'edges' => $edges,
            'views' => $views,
            'migrations' => $migration,
            'code' => [
                'summary' => isset($codeHints['summary']) ? $codeHints['summary'] : null,
                'counts' => isset($codeHints['counts']) ? $codeHints['counts'] : [],
                'truncated' => !empty($codeHints['truncated']),
            ],
            'columns' => $columns,
            'ambiguous' => $this->tableAmbiguity($entry, $codeHints, $columns),
        ];
    }

    /**
     * @param array<string, mixed>      $meta
     * @param array<string, mixed>      $profiles
     * @param array<string, mixed>      $hint
     * @param array<string, mixed>|null $raw
     *
     * @return array<string, mixed>
     */
    private function buildColumn(string $column, array $meta, array $profiles, array $hint, $raw, bool $isFull): array
    {
        $profile = $this->profileOf($profiles, $column);
        $enum = isset($hint['enum']) && is_array($hint['enum']) ? $hint['enum'] : null;
        $faker = null;
        if ($raw !== null && isset($raw['faker'][$column])) {
            $faker = $raw['faker'][$column];
        }

        $codes = isset($profile['codes']) && is_array($profile['codes']) ? array_map('strval', $profile['codes']) : [];
        $coverage = $this->coverage($column, $raw, $isFull);
        $why = [];

        if ($enum !== null && $codes !== [] && !empty($enum['values'])) {
            $enumValues = array_map('strval', (array) $enum['values']);
            $missingInDb = array_values(array_diff($enumValues, $codes));
            $missingInEnum = array_values(array_diff($codes, $enumValues));
            if (($missingInDb !== [] || $missingInEnum !== []) && !empty($profile['codes_complete'])) {
                $why[] = self::WHY_ENUM_DB_MISMATCH;
            }
        }
        if ($enum !== null && !empty($enum['ambiguous'])) {
            $why[] = self::WHY_ENUM_CANDIDATES;
        }
        if ($enum === null && $codes !== []) {
            $why[] = self::WHY_NO_ENUM_FOR_CODES;
        }
        if ($hint === []) {
            $why[] = self::WHY_NO_CODE_MENTIONS;
        }
        if (!$isFull && $coverage['covered_by'] === null && ($codes !== [] || !empty($profile['categorical']))) {
            $why[] = self::WHY_COVERAGE_GAP;
        }

        return [
            'type' => isset($meta['type']) ? $meta['type'] : null,
            'nullable' => isset($meta['nullable']) ? (bool) $meta['nullable'] : null,
            'profile' => $profile,
            'enum' => $enum,
            'usages' => isset($hint['usages']) ? $hint['usages'] : [],
            'usage_count' => isset($hint['count']) ? (int) $hint['count'] : 0,
            'pii' => ['faker' => $faker],
            'coverage' => $coverage,
            'ambiguous' => array_values(array_unique($why)),
        ];
    }

    /**
     * Чем колонка покрыта в выборке: критерий, стратификация или ничем.
     *
     * @param array<string, mixed>|null $raw
     * @return array{covered_by: string|null, detail: string|null}
     */
    private function coverage(string $column, $raw, bool $isFull): array
    {
        if ($isFull) {
            return ['covered_by' => 'full_export', 'detail' => null];
        }
        if ($raw === null || !isset($raw[TableConfig::KEY_SAMPLE]) || !is_array($raw[TableConfig::KEY_SAMPLE])) {
            return ['covered_by' => null, 'detail' => null];
        }
        $sample = $raw[TableConfig::KEY_SAMPLE];

        foreach (TableConfig::stratifyColumns($sample) as $stratifyColumn) {
            if ($stratifyColumn === $column) {
                return ['covered_by' => 'stratify', 'detail' => null];
            }
        }
        foreach (TableConfig::stratifySpecs($sample) as $spec) {
            if ($spec['then'] !== null && $spec['then']['column'] === $column) {
                return ['covered_by' => 'stratify.then', 'detail' => $spec['column']];
            }
        }
        foreach (TableConfig::stratifyVia($sample) as $via) {
            if ($via['column'] === $column) {
                return ['covered_by' => 'stratify_via', 'detail' => $via['table']];
            }
        }
        if (isset($sample[TableConfig::SAMPLE_KEY_CRITERIA]) && is_array($sample[TableConfig::SAMPLE_KEY_CRITERIA])) {
            foreach ($sample[TableConfig::SAMPLE_KEY_CRITERIA] as $criterion) {
                if (!is_array($criterion) || !isset($criterion[TableConfig::CRITERION_KEY_WHERE])) {
                    continue;
                }
                if (preg_match('/\b' . preg_quote($column, '/') . '\b/', (string) $criterion[TableConfig::CRITERION_KEY_WHERE]) === 1) {
                    return ['covered_by' => 'criteria', 'detail' => isset($criterion[TableConfig::CRITERION_KEY_NAME]) ? (string) $criterion[TableConfig::CRITERION_KEY_NAME] : null];
                }
            }
        }

        return ['covered_by' => null, 'detail' => null];
    }

    /**
     * Роль таблицы: словарь, версионная (SCD2), EAV-пара, число ссылающихся.
     *
     * @param array<string, mixed>                $entry
     * @param array<string, array<string, mixed>> $columns
     *
     * @return array<string, mixed>
     */
    private function traits(string $table, array $entry, array $columns, int $inDegree): array
    {
        $names = array_keys($columns);
        $has = function (string $column) use ($names): bool {
            return in_array($column, $names, true);
        };

        $eav = null;
        if (substr($table, -6) === '_attrs') {
            $eav = ['role' => 'values', 'pair' => $table . '_dict'];
        } elseif (substr($table, -11) === '_attrs_dict') {
            $eav = ['role' => 'dictionary', 'pair' => substr($table, 0, -5)];
        }

        return [
            'dict' => substr($table, -5) === '_dict',
            'scd2' => $has('date_from') && $has('date_to'),
            'active_flag' => $has('active_flg') || $has('active_flag') || $has('is_active'),
            'eav' => $eav,
            'in_degree' => $inDegree,
            'columns' => count($columns),
        ];
    }

    /**
     * Связи таблицы: FK из БД, cascade_from конфига, кандидаты из кода — с источником у каждой.
     *
     * @param array<string, mixed>            $entry
     * @param array<string, mixed>|null       $raw
     * @param array<int, array<string, mixed>> $inDegree
     * @param array<string, mixed>            $codeHints
     *
     * @return array<int, array<string, mixed>>
     */
    private function edges(string $schema, string $table, array $entry, $raw, array $inDegree, array $codeHints): array
    {
        $edges = [];
        $seen = [];

        foreach (isset($entry['foreign_keys']) && is_array($entry['foreign_keys']) ? $entry['foreign_keys'] : [] as $fk) {
            if (!is_array($fk) || !isset($fk['column'], $fk['references_table'])) {
                continue;
            }
            $key = 'out|' . $fk['references_table'] . '|' . $fk['column'];
            $seen[$key] = true;
            $edges[] = [
                'dir' => 'out',
                'table' => $fk['references_table'],
                'column' => $fk['column'],
                'parent_column' => isset($fk['references_column']) ? $fk['references_column'] : null,
                'source' => 'db_fk',
                'in_db_fk' => true,
            ];
        }

        foreach ($raw !== null && isset($raw[TableConfig::KEY_CASCADE_FROM]) && is_array($raw[TableConfig::KEY_CASCADE_FROM]) ? $raw[TableConfig::KEY_CASCADE_FROM] : [] as $cascade) {
            if (!is_array($cascade) || !isset($cascade['parent'], $cascade['fk_column'])) {
                continue;
            }
            $key = 'out|' . $cascade['parent'] . '|' . $cascade['fk_column'];
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $edges[] = [
                'dir' => 'out',
                'table' => $cascade['parent'],
                'column' => $cascade['fk_column'],
                'parent_column' => isset($cascade['parent_column']) ? $cascade['parent_column'] : null,
                'source' => 'config',
                'in_db_fk' => false,
            ];
        }

        foreach (isset($codeHints['relationships']) && is_array($codeHints['relationships']) ? $codeHints['relationships'] : [] as $relationship) {
            if (!is_array($relationship) || !isset($relationship['target'])) {
                continue;
            }
            $column = isset($relationship['fk_column']) ? $relationship['fk_column'] : null;
            $key = 'out|' . $relationship['target'] . '|' . $column;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $edges[] = [
                'dir' => 'out',
                'table' => $relationship['target'],
                'column' => $column,
                'parent_column' => isset($relationship['parent_column']) ? $relationship['parent_column'] : null,
                'source' => isset($relationship['origin']) && $relationship['origin'] === 'migration' ? 'code_migration' : 'code_doctrine',
                'in_db_fk' => !empty($relationship['in_db_fk']),
                'evidence' => isset($relationship['file']) ? ['file' => $relationship['file'], 'line' => isset($relationship['line']) ? $relationship['line'] : null] : null,
            ];
        }

        foreach ($inDegree as $incoming) {
            $edges[] = [
                'dir' => 'in',
                'table' => $incoming['table'],
                'column' => $incoming['column'],
                'parent_column' => $incoming['parent_column'],
                'source' => 'db_fk',
                'in_db_fk' => true,
            ];
        }

        return $edges;
    }

    /**
     * Кто ссылается на таблицу по FK — обратная сторона графа связей.
     *
     * @param array<string, mixed> $inventory
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function countInDegree(array $inventory): array
    {
        $result = [];
        $schemas = isset($inventory['schemas']) && is_array($inventory['schemas']) ? $inventory['schemas'] : [];
        foreach ($schemas as $schema => $data) {
            $tables = isset($data['tables']) && is_array($data['tables']) ? $data['tables'] : [];
            foreach ($tables as $table => $entry) {
                if (!is_array($entry) || !isset($entry['foreign_keys']) || !is_array($entry['foreign_keys'])) {
                    continue;
                }
                foreach ($entry['foreign_keys'] as $fk) {
                    if (!is_array($fk) || !isset($fk['references_table'])) {
                        continue;
                    }
                    $result[(string) $fk['references_table']][] = [
                        'table' => $schema . '.' . $table,
                        'column' => isset($fk['column']) ? $fk['column'] : null,
                        'parent_column' => isset($fk['references_column']) ? $fk['references_column'] : null,
                    ];
                }
            }
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $entry
     * @return array<string, array<string, mixed>> колонка => {type, nullable}
     */
    private function columnList(array $entry): array
    {
        $columns = [];
        foreach (isset($entry['columns']) && is_array($entry['columns']) ? $entry['columns'] : [] as $column) {
            if (!is_array($column) || !isset($column['name'])) {
                continue;
            }
            $columns[(string) $column['name']] = $column;
        }

        return $columns;
    }

    /**
     * @param array<string, mixed> $profiles
     * @return array<string, mixed>
     */
    private function profileOf(array $profiles, string $column): array
    {
        foreach ($profiles as $profile) {
            if (is_array($profile) && isset($profile['column']) && (string) $profile['column'] === $column) {
                return $profile;
            }
        }

        return [];
    }

    /**
     * @param array<string, mixed>                $entry
     * @param array<string, mixed>                $codeHints
     * @param array<string, array<string, mixed>> $columns
     *
     * @return array<int, string>
     */
    private function tableAmbiguity(array $entry, array $codeHints, array $columns): array
    {
        $why = [];
        if ($codeHints === []) {
            $why[] = self::WHY_NO_CODE_MENTIONS;
        }
        foreach ($columns as $column) {
            foreach ($column['ambiguous'] as $reason) {
                if ($reason !== self::WHY_NO_CODE_MENTIONS) {
                    $why[] = $reason;
                }
            }
        }

        return array_values(array_unique($why));
    }
}
