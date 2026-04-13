<?php

namespace Timbrs\DatabaseDumps\Service\Graph;

/**
 * Топологическая сортировка графа зависимостей (алгоритм Кана)
 */
class TopologicalSorter
{
    /**
     * Топологическая сортировка (родители перед потомками)
     *
     * @param array<string, array<string>> $adjacency key=node, value=list of dependencies (parents)
     * @return array<string> sorted list (parents before children)
     * @throws \RuntimeException if circular dependency detected
     */
    public function sort(array $adjacency): array
    {
        if (empty($adjacency)) {
            return [];
        }

        // Collect all nodes (from keys and values)
        $allNodes = [];
        foreach ($adjacency as $node => $deps) {
            $allNodes[$node] = true;
            foreach ($deps as $dep) {
                $allNodes[$dep] = true;
            }
        }

        // Ensure all nodes have adjacency entries
        foreach ($allNodes as $node => $_) {
            if (!isset($adjacency[$node])) {
                $adjacency[$node] = [];
            }
        }

        // Build in-degree map (how many dependencies each node has)
        $inDegree = [];
        foreach ($adjacency as $node => $deps) {
            if (!isset($inDegree[$node])) {
                $inDegree[$node] = 0;
            }
            $inDegree[$node] = count($deps);
        }

        // Build reverse graph: for each dependency, track which nodes depend on it
        $dependents = [];
        foreach ($adjacency as $node => $deps) {
            foreach ($deps as $dep) {
                if (!isset($dependents[$dep])) {
                    $dependents[$dep] = [];
                }
                $dependents[$dep][] = $node;
            }
        }

        // Start with nodes having no dependencies (in-degree 0), sorted alphabetically
        $queue = [];
        foreach ($inDegree as $node => $degree) {
            if ($degree === 0) {
                $queue[] = $node;
            }
        }
        sort($queue);

        $result = [];

        while (!empty($queue)) {
            $current = array_shift($queue);
            $result[] = $current;

            if (isset($dependents[$current])) {
                foreach ($dependents[$current] as $dependent) {
                    $inDegree[$dependent]--;
                    if ($inDegree[$dependent] === 0) {
                        $queue[] = $dependent;
                        sort($queue);
                    }
                }
            }
        }

        if (count($result) < count($allNodes)) {
            $cycles = $this->detectCycles($adjacency);
            $cycleDescriptions = [];
            foreach ($cycles as $cycle) {
                $cycleDescriptions[] = implode(' -> ', $cycle) . ' -> ' . $cycle[0];
            }
            throw new \RuntimeException(
                'Обнаружена циклическая зависимость: ' . implode('; ', $cycleDescriptions)
            );
        }

        return $result;
    }

    /**
     * Топологическая сортировка с автоматическим разрывом циклов.
     *
     * Разрывает циклы, удаляя nullable-рёбра (или self-referential).
     * Возвращает SortResult с отсортированными узлами и списком отложенных рёбер.
     *
     * @param array<string, array<string>> $adjacency key=node, value=list of dependencies
     * @param array<string, bool> $nullableEdges key = "source->target" (edge key), value = is_nullable
     * @param array<string, array<string, array{source_column: string, target_column: string}>> $edgeDetails key=node, value = map of dep => column info
     * @return SortResult
     */
    public function sortWithCycleBreaking(array $adjacency, array $nullableEdges = [], array $edgeDetails = []): SortResult
    {
        if (empty($adjacency)) {
            return new SortResult([]);
        }

        $deferredEdges = [];

        // Сначала удаляем self-referential рёбра — они всегда deferred
        foreach ($adjacency as $node => $deps) {
            $filtered = [];
            foreach ($deps as $dep) {
                if ($dep === $node) {
                    // Self-referential: всегда deferred
                    $detail = isset($edgeDetails[$node][$dep]) ? $edgeDetails[$node][$dep] : null;
                    $deferredEdges[] = [
                        'source' => $node,
                        'target' => $dep,
                        'source_column' => $detail !== null ? $detail['source_column'] : '',
                        'target_column' => $detail !== null ? $detail['target_column'] : '',
                    ];
                } else {
                    $filtered[] = $dep;
                }
            }
            $adjacency[$node] = $filtered;
        }

        // Итеративно пытаемся отсортировать, разрывая циклы
        $maxIterations = 100;
        $iteration = 0;

        while ($iteration < $maxIterations) {
            $iteration++;

            try {
                $sorted = $this->sort($adjacency);
                return new SortResult($sorted, $deferredEdges);
            } catch (\RuntimeException $e) {
                // Цикл обнаружен — ищем ребро для разрыва
                $cycles = $this->detectCycles($adjacency);
                if (count($cycles) === 0) {
                    // Не должно произойти, но на всякий случай
                    break;
                }

                $broken = false;
                // Приоритет 1: nullable-ребро внутри цикла
                foreach ($cycles as $cycle) {
                    foreach ($cycle as $nodeInCycle) {
                        foreach ($adjacency[$nodeInCycle] as $dep) {
                            if (in_array($dep, $cycle, true)) {
                                $edgeKey = $nodeInCycle . '->' . $dep;
                                if (isset($nullableEdges[$edgeKey]) && $nullableEdges[$edgeKey]) {
                                    // Разрываем это ребро
                                    $adjacency[$nodeInCycle] = array_values(array_filter(
                                        $adjacency[$nodeInCycle],
                                        function ($d) use ($dep) { return $d !== $dep; }
                                    ));
                                    $detail = isset($edgeDetails[$nodeInCycle][$dep]) ? $edgeDetails[$nodeInCycle][$dep] : null;
                                    $deferredEdges[] = [
                                        'source' => $nodeInCycle,
                                        'target' => $dep,
                                        'source_column' => $detail !== null ? $detail['source_column'] : '',
                                        'target_column' => $detail !== null ? $detail['target_column'] : '',
                                    ];
                                    $broken = true;
                                    break 3;
                                }
                            }
                        }
                    }
                }

                // Приоритет 2: любое ребро внутри первого цикла
                if (!$broken) {
                    $cycle = $cycles[0];
                    $nodeInCycle = $cycle[0];
                    foreach ($adjacency[$nodeInCycle] as $dep) {
                        if (in_array($dep, $cycle, true)) {
                            $adjacency[$nodeInCycle] = array_values(array_filter(
                                $adjacency[$nodeInCycle],
                                function ($d) use ($dep) { return $d !== $dep; }
                            ));
                            $detail = isset($edgeDetails[$nodeInCycle][$dep]) ? $edgeDetails[$nodeInCycle][$dep] : null;
                            $deferredEdges[] = [
                                'source' => $nodeInCycle,
                                'target' => $dep,
                                'source_column' => $detail !== null ? $detail['source_column'] : '',
                                'target_column' => $detail !== null ? $detail['target_column'] : '',
                            ];
                            break;
                        }
                    }
                }
            }
        }

        // Fallback: если за maxIterations не удалось, возвращаем все узлы в алфавитном порядке
        $allNodes = array_keys($adjacency);
        sort($allNodes);
        return new SortResult($allNodes, $deferredEdges);
    }

    /**
     * Detect cycles using Tarjan's SCC algorithm
     *
     * @param array<string, array<string>> $adjacency
     * @return array<array<string>> each inner array is one cycle path
     */
    public function detectCycles(array $adjacency): array
    {
        $index = 0;
        $stack = [];
        $onStack = [];
        $indices = [];
        $lowlinks = [];
        $sccs = [];

        // Ensure all nodes are present
        $allNodes = [];
        foreach ($adjacency as $node => $deps) {
            $allNodes[$node] = true;
            foreach ($deps as $dep) {
                $allNodes[$dep] = true;
                if (!isset($adjacency[$dep])) {
                    $adjacency[$dep] = [];
                }
            }
        }

        $strongConnect = function (string $v) use (
            &$index,
            &$stack,
            &$onStack,
            &$indices,
            &$lowlinks,
            &$sccs,
            &$adjacency,
            &$strongConnect
        ) {
            $indices[$v] = $index;
            $lowlinks[$v] = $index;
            $index++;
            $stack[] = $v;
            $onStack[$v] = true;

            // Consider successors: in our adjacency, deps are what v depends on
            // For cycle detection, we follow the dependency direction
            foreach ($adjacency[$v] as $w) {
                if (!isset($indices[$w])) {
                    $strongConnect($w);
                    $lowlinks[$v] = min($lowlinks[$v], $lowlinks[$w]);
                } elseif (isset($onStack[$w]) && $onStack[$w]) {
                    $lowlinks[$v] = min($lowlinks[$v], $indices[$w]);
                }
            }

            // If v is a root node, pop the SCC
            if ($lowlinks[$v] === $indices[$v]) {
                /** @var string[] $scc */
                $scc = [];
                do {
                    /** @var string $w */
                    $w = array_pop($stack);
                    $onStack[$w] = false;
                    $scc[] = $w;
                } while ($w !== $v);
                if (count($scc) > 1) {
                    sort($scc);
                    $sccs[] = $scc;
                }
            }
        };

        // Process nodes in sorted order for determinism
        $sortedNodes = array_keys($allNodes);
        sort($sortedNodes);

        foreach ($sortedNodes as $node) {
            if (!isset($indices[$node])) {
                $strongConnect($node);
            }
        }

        return $sccs;
    }
}
