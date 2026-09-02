<?php

namespace Timbrs\DatabaseDumps\Service\Verification;

use Timbrs\DatabaseDumps\Config\TableConfig;
use Timbrs\DatabaseDumps\Service\Validation\Finding;

/**
 * Проверка замкнутости каскадов ПО ФАКТУ ВЫГРУЗКИ.
 *
 * Валидатор проверяет правила, check-criteria — исполнимость критериев в БД.
 * Ни то, ни другое не отвечает на вопрос «а что реально легло в дамп»: если
 * cascade-условие по какой-то причине не доехало до запроса, ребёнок наберётся
 * просто по limit, файлы будут на месте, и обе проверки останутся зелёными.
 *
 * Здесь для каждого ребра cascade_from берутся выгруженные значения fk_column
 * ребёнка и parent_column родителя, и считается доля сирот — строк, чей родитель
 * в дамп не попал. При исправном каскаде она равна нулю по построению, поэтому
 * любое ненулевое значение означает, что ограничение не сработало.
 *
 * Читаются только две колонки на ребро: содержимое остальных (в том числе ПД)
 * не покидает файл.
 */
class CascadeClosureVerifier
{
    /** Сироты: строки ребёнка ссылаются на родителя, которого нет в выгрузке. */
    public const CODE_ORPHANS = 'V-1';

    /** Таблица есть в конфиге, файла дампа нет. */
    public const CODE_NO_DUMP = 'V-2';

    /** Родитель каскада не выгружен — связь проверить нечем. */
    public const CODE_NO_PARENT_DUMP = 'V-3';

    /** Колонка каскада отсутствует в дампе. */
    public const CODE_COLUMN_MISSING = 'V-4';

    /** Больше этого числа значений родителя в память не берём. */
    private const MAX_PARENT_VALUES = 2000000;

    /** @var DumpValueReader */
    private $reader;

    public function __construct(DumpValueReader $reader)
    {
        $this->reader = $reader;
    }

    /**
     * @param array<int, TableConfig> $tables Разрешённые конфиги таблиц (TableConfigResolver::resolveAll)
     * @param string $dumpsRoot Абсолютный путь к каталогу dumps
     *
     * @return array{findings: array<int, Finding>, edges: int, checked: int, skipped: int, orphan_rows: int}
     */
    public function verify(array $tables, string $dumpsRoot): array
    {
        $index = [];
        foreach ($tables as $config) {
            $index[$config->getFullTableName()] = $config;
        }

        $findings = [];
        $edges = 0;
        $checked = 0;
        $skipped = 0;
        $orphanRows = 0;
        $missingDumpReported = [];

        foreach ($tables as $config) {
            $cascade = $config->getCascadeFrom();
            if (empty($cascade)) {
                continue;
            }

            $childPath = $this->dumpPath($dumpsRoot, $config);
            if (!is_file($childPath)) {
                $key = $config->getFullTableName();
                if (!isset($missingDumpReported[$key])) {
                    $missingDumpReported[$key] = true;
                    $findings[] = Finding::warning(
                        self::CODE_NO_DUMP,
                        sprintf('%s есть в конфиге, но файла дампа нет — таблица не выгружалась', $key),
                        $config->getSchema(),
                        $config->getTable()
                    );
                }
                $skipped += count($cascade);
                $edges += count($cascade);
                continue;
            }

            foreach ($cascade as $i => $entry) {
                ++$edges;
                $result = $this->verifyEdge($config, $entry, $i, $childPath, $dumpsRoot, $index);
                if ($result['finding'] !== null) {
                    $findings[] = $result['finding'];
                }
                if ($result['checked']) {
                    ++$checked;
                    $orphanRows += $result['orphans'];
                } else {
                    ++$skipped;
                }
            }
        }

        return [
            'findings' => $findings,
            'edges' => $edges,
            'checked' => $checked,
            'skipped' => $skipped,
            'orphan_rows' => $orphanRows,
        ];
    }

    /**
     * @param array{parent: string, fk_column: string, parent_column: string} $entry
     * @param array<string, TableConfig> $index
     *
     * @return array{finding: Finding|null, checked: bool, orphans: int}
     */
    private function verifyEdge(
        TableConfig $config,
        array $entry,
        int $position,
        string $childPath,
        string $dumpsRoot,
        array $index
    ): array {
        // Полноту ключей гарантирует TableConfig::validateCascadeFrom — до сюда
        // неполная запись не доходит.
        $parentKey = $entry['parent'];
        $fkColumn = $entry['fk_column'];
        $parentColumn = $entry['parent_column'];
        $schema = $config->getSchema();
        $table = $config->getTable();

        $parentConfig = isset($index[$parentKey]) ? $index[$parentKey] : null;
        if ($parentConfig === null) {
            // Мёртвого родителя ловит валидатор (G-1) — здесь просто нечего сверять.
            return ['finding' => null, 'checked' => false, 'orphans' => 0];
        }

        // У родителя в full_export выгружены все строки: замкнутость выполняется
        // по построению, а набор id может быть в миллионы значений.
        if ($parentConfig->isFullExport()) {
            return ['finding' => null, 'checked' => false, 'orphans' => 0];
        }

        $parentPath = $this->dumpPath($dumpsRoot, $parentConfig);
        if (!is_file($parentPath)) {
            return [
                'finding' => Finding::warning(
                    self::CODE_NO_PARENT_DUMP,
                    sprintf(
                        'cascade_from[%d]: родитель %s не выгружен, связь %s.%s.%s проверить нечем',
                        $position,
                        $parentKey,
                        $schema,
                        $table,
                        $fkColumn
                    ),
                    $schema,
                    $table,
                    $fkColumn
                ),
                'checked' => false,
                'orphans' => 0,
            ];
        }

        $child = $this->reader->readColumn($childPath, $fkColumn);
        if (!$child['found']) {
            return [
                'finding' => Finding::error(
                    self::CODE_COLUMN_MISSING,
                    sprintf(
                        'cascade_from[%d]: колонки "%s" нет в выгрузке %s.%s — каскад от %s не мог сработать',
                        $position,
                        $fkColumn,
                        $schema,
                        $table,
                        $parentKey
                    ),
                    $schema,
                    $table,
                    $fkColumn
                ),
                'checked' => false,
                'orphans' => 0,
            ];
        }

        $parent = $this->reader->readColumn($parentPath, $parentColumn);
        if (!$parent['found']) {
            return [
                'finding' => Finding::error(
                    self::CODE_COLUMN_MISSING,
                    sprintf(
                        'cascade_from[%d]: колонки "%s" нет в выгрузке родителя %s — сверять %s.%s.%s не с чем',
                        $position,
                        $parentColumn,
                        $parentKey,
                        $schema,
                        $table,
                        $fkColumn
                    ),
                    $schema,
                    $table,
                    $fkColumn
                ),
                'checked' => false,
                'orphans' => 0,
            ];
        }

        if (count($parent['values']) > self::MAX_PARENT_VALUES) {
            return ['finding' => null, 'checked' => false, 'orphans' => 0];
        }

        $allowed = [];
        foreach ($parent['values'] as $value) {
            if ($value !== null) {
                $allowed[$value] = true;
            }
        }

        $total = 0;
        $nulls = 0;
        $orphans = 0;
        $examples = [];
        foreach ($child['values'] as $value) {
            ++$total;
            if ($value === null) {
                ++$nulls;
                continue;
            }
            if (!isset($allowed[$value])) {
                ++$orphans;
                if (count($examples) < 3 && !in_array($value, $examples, true)) {
                    $examples[] = $value;
                }
            }
        }

        if ($orphans === 0) {
            return ['finding' => null, 'checked' => true, 'orphans' => 0];
        }

        $linked = $total - $nulls;
        $rate = $linked > 0 ? round($orphans * 100 / $linked, 1) : 100.0;

        return [
            'finding' => Finding::error(
                self::CODE_ORPHANS,
                sprintf(
                    'cascade_from[%d] от %s: %d из %d связанных строк ссылаются на родителя, '
                    . 'которого нет в выгрузке (%s%%). Каскад не ограничил выборку. '
                    . 'Значения без родителя, например: %s',
                    $position,
                    $parentKey,
                    $orphans,
                    $linked,
                    $rate,
                    implode(', ', $examples)
                ),
                $schema,
                $table,
                $fkColumn,
                false,
                [
                    'parent' => $parentKey,
                    'fk_column' => $fkColumn,
                    'parent_column' => $parentColumn,
                    'child_rows' => $total,
                    'child_nulls' => $nulls,
                    'orphans' => $orphans,
                    'orphan_rate' => $rate,
                    'parent_rows' => count($parent['values']),
                ]
            ),
            'checked' => true,
            'orphans' => $orphans,
        ];
    }

    private function dumpPath(string $dumpsRoot, TableConfig $config): string
    {
        $connection = $config->getConnectionName();
        $prefix = rtrim($dumpsRoot, '/\\');
        if ($connection !== null) {
            $prefix .= '/' . $connection;
        }

        return $prefix . '/' . $config->getSchema() . '/' . $config->getTable() . '.sql';
    }
}
