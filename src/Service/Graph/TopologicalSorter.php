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
