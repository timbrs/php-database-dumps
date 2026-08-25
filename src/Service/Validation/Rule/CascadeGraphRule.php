<?php

namespace Timbrs\DatabaseDumps\Service\Validation\Rule;

use Timbrs\DatabaseDumps\Service\Graph\TopologicalSorter;
use Timbrs\DatabaseDumps\Service\Validation\AuditContext;
use Timbrs\DatabaseDumps\Service\Validation\Finding;

/**
 * Граф `cascade_from` — связность дампа.
 *
 * В базе без FK-констрейнтов топологической сортировке не за что зацепиться: у всех узлов
 * in-degree 0, и TopologicalSorter выдаёт алфавитный порядок. Значит вся связность держится
 * на cascade_from, а cascade-консистентность — на том, что родитель попал в дамп раньше
 * ребёнка (только тогда SelectedPkRegistry знает выбранные id).
 *
 *  G-1 — родитель не выгружается вовсе: CascadeWhereResolver вернёт null, ограничение молча пропадёт;
 *  G-2 — цикл в cascade_from;
 *  G-3 — цепочка длиннее settings.max_cascade_depth: хвост отбрасывается;
 *  G-4 — родитель выгружается ПОЗЖЕ ребёнка, а выбирается через sample: реестр id пуст,
 *        резолвер откатится к подзапросу, который не повторяет критерии — наборы разойдутся.
 *        С тех пор как cascade_from подаётся в граф порядка вторым источником рёбер, это
 *        остаточный случай: ребро пришлось разорвать ради цикла либо родителя нет в графе;
 *  G-5 — родитель с sample выгружается раньше: связность держится на SelectedPkRegistry
 *        и на ребре cascade_from, а не на удачном порядке имён;
 *  G-6 — у таблицы одновременно sample и cascade_from: DataFetcher вернёт результат
 *        SampleQueryBuilder до вычисления cascade-WHERE, связь с родителем молча отключится.
 */
class CascadeGraphRule implements RuleInterface
{
    /** Потолок рекурсии по цепочке cascade_from — страховка от разрастания графа. */
    private const MAX_CHAIN_GUARD = 100;

    public function name(): string
    {
        return 'каскады';
    }

    public function apply(AuditContext $context): array
    {
        $cascade = $this->collectCascade($context);
        $findings = array_merge(
            $this->checkEntries($context, $cascade),
            $this->checkCycles($context, $cascade),
            $this->checkDepth($context, $cascade)
        );

        return $findings;
    }

    /**
     * @param array<string, array<int, array{parent: string, fk_column: string, parent_column: string}>> $cascade
     * @return array<int, Finding>
     */
    private function checkEntries(AuditContext $context, array $cascade): array
    {
        $config = $context->config();
        $exported = $config->getExportedTableKeys();
        $findings = [];

        foreach ($cascade as $childKey => $entries) {
            $childParts = explode('.', $childKey, 2);
            $childSchema = $childParts[0];
            $childTable = isset($childParts[1]) ? $childParts[1] : '';
            if (!$context->inScope($childSchema)) {
                continue;
            }

            $childConfig = $context->tableConfig($childSchema, $childTable);
            if ($childConfig !== null && $childConfig->hasSample()) {
                $findings[] = Finding::note(
                    'G-6',
                    'у таблицы заданы и sample, и cascade_from — DataFetcher вернёт выборку по критериям '
                    . 'до вычисления cascade-WHERE, связь с родителем не применится',
                    $childSchema,
                    $childTable,
                    null,
                    false,
                    ['hint' => 'оставить что-то одно: либо sample, либо cascade_from']
                );
            }

            $childPosition = $context->exportPosition($childSchema, $childTable);

            foreach ($entries as $entry) {
                $parentKey = $entry['parent'];
                $parentParts = explode('.', $parentKey, 2);
                if (count($parentParts) !== 2) {
                    continue;
                }
                [$parentSchema, $parentTable] = $parentParts;

                if (!isset($exported[$parentKey])) {
                    $findings[] = Finding::error(
                        'G-1',
                        sprintf(
                            'родитель %s не выгружается — CascadeWhereResolver вернёт null, '
                            . 'ограничение по родителю молча отбросится',
                            $parentKey
                        ),
                        $childSchema,
                        $childTable,
                        null,
                        false,
                        ['parent' => $parentKey, 'hint' => 'добавить родителя в конфиг или убрать cascade_from']
                    );
                    continue;
                }

                $modes = $config->getTableModes($parentSchema);
                if (isset($modes[$parentTable]) && $modes[$parentTable] === 'full') {
                    // Родитель выгружается целиком — резолвер намеренно не строит подзапрос.
                    continue;
                }

                $parentConfig = $context->tableConfig($parentSchema, $parentTable);
                if ($parentConfig === null || !$parentConfig->hasSample()) {
                    // Подзапрос детерминирован и повторяет выборку родителя один в один.
                    continue;
                }

                $parentPosition = $context->exportPosition($parentSchema, $parentTable);
                if ($childPosition === null || $parentPosition === null) {
                    continue;
                }

                if ($parentPosition > $childPosition) {
                    $findings[] = Finding::warning(
                        'G-4',
                        sprintf(
                            'родитель %s выбирается через sample и выгружается ПОЗЖЕ ребёнка (%d против %d '
                            . 'в порядке экспорта): реестр выбранных id пуст, резолвер откатится к подзапросу, '
                            . 'который не повторяет критерии — строки родителя в дампе и в подзапросе разойдутся',
                            $parentKey,
                            $parentPosition,
                            $childPosition
                        ),
                        $childSchema,
                        $childTable,
                        null,
                        false,
                        [
                            'parent' => $parentKey,
                            'parent_position' => $parentPosition,
                            'child_position' => $childPosition,
                            'hint' => 'снять sample с родителя, добавить FK в БД либо принять расхождение осознанно',
                        ]
                    );
                    continue;
                }

                $findings[] = Finding::note(
                    'G-5',
                    sprintf(
                        'родитель %s выбирается через sample, но выгружается раньше ребёнка — '
                        . 'связность держится на SelectedPkRegistry и на ребре cascade_from '
                        . 'в графе порядка выгрузки',
                        $parentKey
                    ),
                    $childSchema,
                    $childTable,
                    null,
                    false,
                    ['parent' => $parentKey]
                );
            }
        }

        return $findings;
    }

    /**
     * @param array<string, array<int, array{parent: string, fk_column: string, parent_column: string}>> $cascade
     * @return array<int, Finding>
     */
    private function checkCycles(AuditContext $context, array $cascade): array
    {
        $adjacency = [];
        foreach ($cascade as $childKey => $entries) {
            $adjacency[$childKey] = [];
            foreach ($entries as $entry) {
                if (!in_array($entry['parent'], $adjacency[$childKey], true)) {
                    $adjacency[$childKey][] = $entry['parent'];
                }
            }
        }
        if (empty($adjacency)) {
            return [];
        }

        $sorter = new TopologicalSorter();
        $findings = [];

        foreach ($sorter->detectCycles($adjacency) as $cycle) {
            $head = $cycle[0];
            $parts = explode('.', $head, 2);
            if (!$context->inScope($parts[0])) {
                continue;
            }
            $findings[] = Finding::error(
                'G-2',
                sprintf(
                    'цикл в cascade_from: %s — резолвер оборвёт ветку на повторном посещении, '
                    . 'ограничение будет неполным',
                    implode(' -> ', $cycle) . ' -> ' . $head
                ),
                $parts[0],
                isset($parts[1]) ? $parts[1] : null,
                null,
                false,
                ['cycle' => $cycle]
            );
        }

        return $findings;
    }

    /**
     * @param array<string, array<int, array{parent: string, fk_column: string, parent_column: string}>> $cascade
     * @return array<int, Finding>
     */
    private function checkDepth(AuditContext $context, array $cascade): array
    {
        $maxDepth = $context->config()->getMaxCascadeDepth();
        $findings = [];

        foreach (array_keys($cascade) as $childKey) {
            $parts = explode('.', $childKey, 2);
            if (!$context->inScope($parts[0])) {
                continue;
            }
            $length = $this->chainLength($childKey, $cascade, []);
            if ($length <= $maxDepth) {
                continue;
            }
            $findings[] = Finding::warning(
                'G-3',
                sprintf(
                    'цепочка cascade_from длиной %d при max_cascade_depth = %d — хвост цепочки '
                    . 'будет отброшен, выборка получится шире задуманной',
                    $length,
                    $maxDepth
                ),
                $parts[0],
                isset($parts[1]) ? $parts[1] : null,
                null,
                false,
                ['length' => $length, 'max_depth' => $maxDepth]
            );
        }

        return $findings;
    }

    /**
     * Длина самой длинной цепочки cascade_from вверх от таблицы (сама таблица — 1).
     * Родитель в full_export обрывает цепочку: резолвер туда не спускается.
     *
     * @param array<string, array<int, array{parent: string, fk_column: string, parent_column: string}>> $cascade
     * @param array<string, bool> $visited путь рекурсии — та же защита от циклов, что и в резолвере
     */
    private function chainLength(string $key, array $cascade, array $visited, int $guard = 0): int
    {
        if ($guard > self::MAX_CHAIN_GUARD || isset($visited[$key]) || !isset($cascade[$key])) {
            return 0;
        }
        $visited[$key] = true;

        $longest = 0;
        foreach ($cascade[$key] as $entry) {
            $longest = max($longest, 1 + $this->chainLength($entry['parent'], $cascade, $visited, $guard + 1));
        }

        return $longest;
    }

    /**
     * Все записи cascade_from по всему конфигу (не только по схемам из фильтра): родитель
     * ребёнка часто живёт в соседней схеме.
     *
     * @return array<string, array<int, array{parent: string, fk_column: string, parent_column: string}>>
     */
    private function collectCascade(AuditContext $context): array
    {
        $cascade = [];
        $config = $context->config();

        foreach ($config->getSchemas() as $schema) {
            foreach (array_keys($config->getPartialExport($schema)) as $table) {
                $table = (string) $table;
                $tableConfig = $context->tableConfig($schema, $table);
                if ($tableConfig === null) {
                    continue;
                }
                $entries = $tableConfig->getCascadeFrom();
                if (empty($entries)) {
                    continue;
                }
                $cascade[$schema . '.' . $table] = $entries;
            }
        }

        return $cascade;
    }
}
