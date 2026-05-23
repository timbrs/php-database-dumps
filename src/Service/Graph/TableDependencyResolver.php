<?php

namespace Timbrs\DatabaseDumps\Service\Graph;

use Timbrs\DatabaseDumps\Service\ConfigGenerator\ForeignKeyInspector;

/**
 * Резолвер FK-зависимостей.
 *
 * Семантика порядка:
 * - sortForExport — родители впереди (для генерации дампа: чтобы при импорте
 *   родительские строки уже существовали к моменту INSERT детей).
 * - sortForImport — то же самое (parents first), так как INSERT детей нуждается
 *   в существующих родителях; TRUNCATE/DELETE для MySQL/Oracle делаются
 *   с временно отключёнными FK-checks, для PG — через CASCADE.
 *
 * Циклы FK разрываются автоматически через TopologicalSorter::sortWithCycleBreaking
 * (предпочитая разорвать nullable-рёбра — они потом восстанавливаются через
 * DeferredUpdateGenerator после основного INSERT).
 */
class TableDependencyResolver
{
    /** @var ForeignKeyInspector */
    private $fkInspector;

    /** @var TopologicalSorter */
    private $sorter;

    /** @var array<string, array<string, array<string, array{source_column: string, target_column: string}>>> */
    private $graphCache = [];

    public function __construct(ForeignKeyInspector $fkInspector, TopologicalSorter $sorter)
    {
        $this->fkInspector = $fkInspector;
        $this->sorter = $sorter;
    }

    /**
     * Универсальный метод сортировки.
     *
     * @param array<string> $tableKeys "schema.table" keys
     */
    public function sort(array $tableKeys, ?string $connectionName = null): SortResult
    {
        return $this->sortTablesWithCycleBreaking($tableKeys, $connectionName);
    }

    /**
     * @param array<string> $tableKeys
     * @return array<string>
     */
    public function sortForExport(array $tableKeys, ?string $connectionName = null): array
    {
        return $this->sort($tableKeys, $connectionName)->getSorted();
    }

    /**
     * Импорт — тот же порядок (родители первыми), поскольку INSERT детей
     * требует существующих родителей.
     *
     * @param array<string> $tableKeys
     * @return array<string>
     */
    public function sortForImport(array $tableKeys, ?string $connectionName = null): array
    {
        return $this->sortForExport($tableKeys, $connectionName);
    }

    /**
     * @param array<string> $tableKeys
     */
    public function sortForExportWithResult(array $tableKeys, ?string $connectionName = null): SortResult
    {
        return $this->sort($tableKeys, $connectionName);
    }

    /**
     * @return array<string, array<string, array{source_column: string, target_column: string}>>
     */
    public function getDependencyGraph(?string $connectionName = null): array
    {
        $cacheKey = $connectionName ?? '__default__';
        if (isset($this->graphCache[$cacheKey])) {
            return $this->graphCache[$cacheKey];
        }

        $fks = $this->fkInspector->getForeignKeys($connectionName);
        $graph = [];

        foreach ($fks as $fk) {
            $child = $fk['source_schema'] . '.' . $fk['source_table'];
            $parent = $fk['target_schema'] . '.' . $fk['target_table'];

            if (!isset($graph[$child])) {
                $graph[$child] = [];
            }
            $graph[$child][$parent] = [
                'source_column' => $fk['source_column'],
                'target_column' => $fk['target_column'],
            ];
        }

        $this->graphCache[$cacheKey] = $graph;
        return $graph;
    }

    /**
     * @return array<int, array{parent: string, fk_column: string, parent_column: string}>
     */
    public function getCascadeFromCandidates(string $childSchema, string $childTable, ?string $connectionName = null): array
    {
        $graph = $this->getDependencyGraph($connectionName);
        $childKey = $childSchema . '.' . $childTable;

        if (!isset($graph[$childKey])) {
            return [];
        }

        $candidates = [];
        foreach ($graph[$childKey] as $parentKey => $columns) {
            $candidates[] = [
                'parent' => $parentKey,
                'fk_column' => $columns['source_column'],
                'parent_column' => $columns['target_column'],
            ];
        }

        return $candidates;
    }

    /**
     * @param array<string> $tableKeys
     */
    private function sortTablesWithCycleBreaking(array $tableKeys, ?string $connectionName): SortResult
    {
        $graph = $this->getDependencyGraph($connectionName);
        $fks = $this->fkInspector->getForeignKeys($connectionName);

        $tableKeySet = array_flip($tableKeys);
        $adjacency = [];
        $edgeDetails = [];

        foreach ($tableKeys as $key) {
            $adjacency[$key] = [];
        }

        foreach ($tableKeys as $childKey) {
            if (!isset($graph[$childKey])) {
                continue;
            }
            foreach ($graph[$childKey] as $parentKey => $columns) {
                if (!isset($tableKeySet[$parentKey])) {
                    continue;
                }
                // Дедуп — несколько FK на одного родителя не должны давать дубль ребра
                if (!in_array($parentKey, $adjacency[$childKey], true)) {
                    $adjacency[$childKey][] = $parentKey;
                }
                if (!isset($edgeDetails[$childKey])) {
                    $edgeDetails[$childKey] = [];
                }
                $edgeDetails[$childKey][$parentKey] = $columns;
            }
        }

        $nullability = $this->fkInspector->getForeignKeyNullability($fks, $connectionName);
        $nullableEdges = [];
        foreach ($tableKeys as $childKey) {
            if (!isset($graph[$childKey])) {
                continue;
            }
            foreach ($graph[$childKey] as $parentKey => $columns) {
                if (!isset($tableKeySet[$parentKey])) {
                    continue;
                }
                $parts = explode('.', $childKey, 2);
                if (count($parts) !== 2) {
                    continue;
                }
                $nullKey = $parts[0] . '.' . $parts[1] . '.' . $columns['source_column'];
                $edgeKey = $childKey . '->' . $parentKey;
                if (isset($nullability[$nullKey])) {
                    $nullableEdges[$edgeKey] = $nullability[$nullKey];
                }
            }
        }

        return $this->sorter->sortWithCycleBreaking($adjacency, $nullableEdges, $edgeDetails);
    }
}
