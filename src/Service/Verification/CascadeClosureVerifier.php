<?php

namespace Timbrs\DatabaseDumps\Service\Verification;

use Timbrs\DatabaseDumps\Config\TableConfig;
use Timbrs\DatabaseDumps\Service\Validation\Finding;
use Timbrs\DatabaseDumps\Service\Verification\Sink\CountingSetSink;

/**
 * Проверка замкнутости связей ПО ФАКТУ ВЫГРУЗКИ.
 *
 * Валидатор проверяет правила, check-criteria — исполнимость критериев в БД.
 * Ни то, ни другое не отвечает на вопрос «а что реально легло в дамп»: если
 * cascade-условие по какой-то причине не доехало до запроса, ребёнок наберётся
 * просто по limit, файлы будут на месте, и обе проверки останутся зелёными.
 *
 * Рёбра берутся из двух источников: cascade_from конфига и внешние ключи БД из слепка
 * схемы. Сегодня в базе без констрейнтов второй список пуст, но появись FK — связь
 * проверяется без правки конфига, и сирота по FK-ребру всегда ошибка: импорт с
 * констрейнтом её не примет.
 *
 * Для каждого ребра берутся выгруженные значения fk_column ребёнка и parent_column
 * родителя, и считается доля сирот — строк, чей родитель в дамп не попал. При исправном
 * каскаде она равна нулю по построению, поэтому любое ненулевое значение означает, что
 * ограничение не сработало.
 *
 * Читаются только две колонки на ребро: содержимое остальных (в том числе ПД)
 * не покидает файл.
 */
class CascadeClosureVerifier implements DumpVerifierInterface
{
    /** Сироты: строки ребёнка ссылаются на родителя, которого нет в выгрузке. */
    public const CODE_ORPHANS = 'V-1';

    /** Таблица есть в конфиге, файла дампа нет. */
    public const CODE_NO_DUMP = 'V-2';

    /** Родитель связи не выгружен — связь проверить нечем. */
    public const CODE_NO_PARENT_DUMP = 'V-3';

    /** Колонка связи отсутствует в дампе. */
    public const CODE_COLUMN_MISSING = 'V-4';

    public const ORIGIN_CASCADE = 'cascade_from';
    public const ORIGIN_DB_FK = 'db_fk';

    /** Больше этого числа различных значений в множество не берём. */
    private const MAX_PARENT_VALUES = 2000000;

    /** @var DumpValueReader */
    private $reader;

    /** @var array<int, array<string, mixed>> */
    private $edges = [];

    /** @var array<int, string> индекс ребра => причина пропуска */
    private $skipped = [];

    /** @var array<int, array{child: CountingSetSink, parent: CountingSetSink, child_path: string, parent_path: string}> */
    private $planned = [];

    /** @var array<string, int> */
    private $stats = ['edges' => 0, 'checked' => 0, 'skipped' => 0, 'orphan_rows' => 0];

    public function __construct(DumpValueReader $reader)
    {
        $this->reader = $reader;
    }

    /**
     * Самостоятельный прогон без остальных проверок (обратная совместимость).
     *
     * @param array<int, TableConfig> $tables Разрешённые конфиги таблиц (TableConfigResolver::resolveAll)
     * @param string $dumpsRoot Абсолютный путь к каталогу dumps
     *
     * @return array{findings: array<int, Finding>, edges: int, checked: int, skipped: int, orphan_rows: int}
     */
    public function verify(array $tables, string $dumpsRoot): array
    {
        $input = new DumpVerificationInput($dumpsRoot, $tables);
        $store = new DumpColumnStore($this->reader);
        $this->plan($input, $store);
        $store->load();
        $findings = $this->check($input, $store);

        return [
            'findings' => $findings,
            'edges' => $this->stats['edges'],
            'checked' => $this->stats['checked'],
            'skipped' => $this->stats['skipped'],
            'orphan_rows' => $this->stats['orphan_rows'],
        ];
    }

    public function plan(DumpVerificationInput $input, DumpColumnStore $store): void
    {
        $this->edges = $this->collectEdges($input);
        $this->skipped = [];
        $this->planned = [];
        $this->stats = ['edges' => count($this->edges), 'checked' => 0, 'skipped' => 0, 'orphan_rows' => 0];

        foreach ($this->edges as $n => $edge) {
            /** @var TableConfig $child */
            $child = $edge['config'];
            $childPath = $input->pathFor($child);
            if (!is_file($childPath)) {
                $this->skipped[$n] = 'no_dump';
                continue;
            }

            $parentConfig = $input->tableByKey($edge['parent']);
            if ($parentConfig === null) {
                $this->skipped[$n] = 'parent_not_in_config';
                continue;
            }
            // У родителя в full_export выгружены все строки: замкнутость выполняется
            // по построению, а набор id может быть в миллионы значений.
            if ($parentConfig->isFullExport()) {
                $this->skipped[$n] = 'parent_full';
                continue;
            }
            $parentPath = $input->pathFor($parentConfig);
            if (!is_file($parentPath)) {
                $this->skipped[$n] = 'parent_no_dump';
                continue;
            }

            $childSink = new CountingSetSink(self::MAX_PARENT_VALUES);
            $parentSink = new CountingSetSink(self::MAX_PARENT_VALUES);
            $store->request($childPath, $edge['fk_column'], $childSink);
            $store->request($parentPath, $edge['parent_column'], $parentSink);
            $this->planned[$n] = [
                'child' => $childSink,
                'parent' => $parentSink,
                'child_path' => $childPath,
                'parent_path' => $parentPath,
            ];
        }
    }

    public function check(DumpVerificationInput $input, DumpColumnStore $store): array
    {
        $findings = [];
        $noDumpReported = [];

        foreach ($this->edges as $n => $edge) {
            /** @var TableConfig $child */
            $child = $edge['config'];
            $schema = $child->getSchema();
            $table = $child->getTable();
            $label = $this->edgeLabel($edge);

            if (isset($this->skipped[$n])) {
                $this->stats['skipped']++;
                switch ($this->skipped[$n]) {
                    case 'no_dump':
                        $key = $child->getFullTableName();
                        if (!isset($noDumpReported[$key])) {
                            $noDumpReported[$key] = true;
                            $findings[] = Finding::warning(
                                self::CODE_NO_DUMP,
                                sprintf('%s есть в конфиге, но файла дампа нет — таблица не выгружалась', $key),
                                $schema,
                                $table
                            );
                        }
                        break;
                    case 'parent_no_dump':
                        $findings[] = Finding::warning(
                            self::CODE_NO_PARENT_DUMP,
                            sprintf('%s: родитель %s не выгружен, связь %s.%s.%s проверить нечем', $label, $edge['parent'], $schema, $table, $edge['fk_column']),
                            $schema,
                            $table,
                            $edge['fk_column']
                        );
                        break;
                    case 'parent_not_in_config':
                        // Мёртвого родителя в cascade_from ловит валидатор (G-1). Родитель по FK,
                        // которого нет в конфиге, — таблица, на которую ссылаются, но не выгружают.
                        if ($edge['origin'] === self::ORIGIN_DB_FK) {
                            $findings[] = Finding::warning(
                                self::CODE_NO_PARENT_DUMP,
                                sprintf('%s: таблица %s не выгружается вовсе, а %s.%s.%s ссылается на неё внешним ключом', $label, $edge['parent'], $schema, $table, $edge['fk_column']),
                                $schema,
                                $table,
                                $edge['fk_column']
                            );
                        }
                        break;
                    default:
                        break;
                }
                continue;
            }

            $plan = $this->planned[$n];
            if (!$store->found($plan['child_path'], $edge['fk_column'])) {
                $this->stats['skipped']++;
                $findings[] = Finding::error(
                    self::CODE_COLUMN_MISSING,
                    sprintf('%s: колонки "%s" нет в выгрузке %s.%s — связь с %s не могла сработать', $label, $edge['fk_column'], $schema, $table, $edge['parent']),
                    $schema,
                    $table,
                    $edge['fk_column']
                );
                continue;
            }
            if (!$store->found($plan['parent_path'], $edge['parent_column'])) {
                $this->stats['skipped']++;
                $findings[] = Finding::error(
                    self::CODE_COLUMN_MISSING,
                    sprintf('%s: колонки "%s" нет в выгрузке родителя %s — сверять %s.%s.%s не с чем', $label, $edge['parent_column'], $edge['parent'], $schema, $table, $edge['fk_column']),
                    $schema,
                    $table,
                    $edge['fk_column']
                );
                continue;
            }

            /** @var CountingSetSink $childSink */
            $childSink = $plan['child'];
            /** @var CountingSetSink $parentSink */
            $parentSink = $plan['parent'];
            if ($parentSink->isCapped() || $childSink->isCapped()) {
                $this->stats['skipped']++;
                continue;
            }

            $orphans = 0;
            $orphanValues = 0;
            foreach ($childSink->counts() as $value => $count) {
                if (!$parentSink->has((string) $value)) {
                    $orphans += $count;
                    $orphanValues++;
                }
            }
            $this->stats['checked']++;
            $this->stats['orphan_rows'] += $orphans;

            if ($orphans === 0) {
                continue;
            }

            $linked = $childSink->nonNull();
            $rate = $linked > 0 ? round($orphans * 100 / $linked, 1) : 100.0;

            $findings[] = Finding::error(
                self::CODE_ORPHANS,
                sprintf(
                    '%s: %d из %d связанных строк ссылаются на родителя %s, которого нет в выгрузке (%s%%; %d разных значений). %s',
                    $label,
                    $orphans,
                    $linked,
                    $edge['parent'],
                    $rate,
                    $orphanValues,
                    $edge['origin'] === self::ORIGIN_DB_FK
                        ? 'Импорт в базу с этим внешним ключом такой дамп не примет.'
                        : 'Каскад не ограничил выборку.'
                ),
                $schema,
                $table,
                $edge['fk_column'],
                false,
                [
                    'origin' => $edge['origin'],
                    'parent' => $edge['parent'],
                    'fk_column' => $edge['fk_column'],
                    'parent_column' => $edge['parent_column'],
                    'child_rows' => $childSink->total(),
                    'child_nulls' => $childSink->nulls(),
                    'orphans' => $orphans,
                    'orphan_values' => $orphanValues,
                    'orphan_rate' => $rate,
                    'parent_rows' => $parentSink->total(),
                ]
            );
        }

        return $findings;
    }

    public function stats(): array
    {
        return $this->stats;
    }

    /**
     * Рёбра из cascade_from и внешних ключей слепка; FK, уже описанный в cascade_from
     * (тот же родитель и та же колонка), второй раз не берётся.
     *
     * @return array<int, array<string, mixed>>
     */
    private function collectEdges(DumpVerificationInput $input): array
    {
        $edges = [];
        $inventory = $input->getInventory();

        foreach ($input->getTables() as $config) {
            $seen = [];
            foreach ($config->getCascadeFrom() ?? [] as $i => $entry) {
                // Полноту ключей гарантирует TableConfig::validateCascadeFrom — до сюда
                // неполная запись не доходит.
                $edges[] = [
                    'config' => $config,
                    'parent' => $entry['parent'],
                    'fk_column' => $entry['fk_column'],
                    'parent_column' => $entry['parent_column'],
                    'origin' => self::ORIGIN_CASCADE,
                    'position' => $i,
                ];
                $seen[$entry['parent'] . '|' . $entry['fk_column']] = true;
            }

            if ($inventory === null) {
                continue;
            }
            foreach ($inventory->foreignKeys($config->getSchema(), $config->getTable()) as $fk) {
                if (isset($seen[$fk['references_table'] . '|' . $fk['column']])) {
                    continue;
                }
                $edges[] = [
                    'config' => $config,
                    'parent' => $fk['references_table'],
                    'fk_column' => $fk['column'],
                    'parent_column' => $fk['references_column'],
                    'origin' => self::ORIGIN_DB_FK,
                    'position' => null,
                ];
            }
        }

        return $edges;
    }

    /**
     * @param array<string, mixed> $edge
     */
    private function edgeLabel(array $edge): string
    {
        if ($edge['origin'] === self::ORIGIN_DB_FK) {
            return sprintf('FK в БД (%s → %s.%s)', $edge['fk_column'], $edge['parent'], $edge['parent_column']);
        }

        return sprintf('cascade_from[%d] от %s', (int) $edge['position'], $edge['parent']);
    }
}
