<?php

namespace Timbrs\DatabaseDumps\Service\Graph;

use Timbrs\DatabaseDumps\Service\ConfigGenerator\ForeignKeyInspector;

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
     * Sort table keys for export (parents first).
     *
     * @param array<string> $tableKeys "schema.table" keys
     * @return array<string> sorted
     * @throws \RuntimeException on circular dependency
     */
    public function sortForExport(array $tableKeys, ?string $connectionName = null): array
    {
        return $this->sortTables($tableKeys, $connectionName);
    }

    /**
     * Sort table keys for import (same: parents first).
     *
     * @param array<string> $tableKeys
     * @return array<string>
     */
    public function sortForImport(array $tableKeys, ?string $connectionName = null): array
    {
        return $this->sortTables($tableKeys, $connectionName);
    }

    /**
     * Get full dependency graph.
     * key = "child_schema.child_table", value = map of "parent_schema.parent_table" => {source_column, target_column}
     *
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
     * Get cascade_from candidates for a child table.
     *
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
     * @return array<string>
     */
    private function sortTables(array $tableKeys, ?string $connectionName): array
    {
        $graph = $this->getDependencyGraph($connectionName);

        // Build adjacency: only include edges where BOTH nodes are in $tableKeys
        $tableKeySet = array_flip($tableKeys);
        $adjacency = [];

        // Init all nodes
        foreach ($tableKeys as $key) {
            $adjacency[$key] = [];
        }

        // Add edges (child depends on parent, but only if parent is also in the set)
        foreach ($tableKeys as $childKey) {
            if (isset($graph[$childKey])) {
                foreach ($graph[$childKey] as $parentKey => $columns) {
                    if (isset($tableKeySet[$parentKey])) {
                        $adjacency[$childKey][] = $parentKey;
                    }
                }
            }
        }

        return $this->sorter->sort($adjacency);
    }
}
