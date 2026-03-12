<?php

namespace Timbrs\DatabaseDumps\Tests\Unit\Service\Graph;

use Timbrs\DatabaseDumps\Service\Graph\TopologicalSorter;
use PHPUnit\Framework\TestCase;

class TopologicalSorterTest extends TestCase
{
    /** @var TopologicalSorter */
    private $sorter;

    protected function setUp(): void
    {
        $this->sorter = new TopologicalSorter();
    }

    public function testSortLinearChain(): void
    {
        // A depends on B, B depends on C
        $adjacency = [
            'A' => ['B'],
            'B' => ['C'],
            'C' => [],
        ];

        $result = $this->sorter->sort($adjacency);

        $this->assertEquals(['C', 'B', 'A'], $result);
    }

    public function testSortDiamond(): void
    {
        // D depends on B,C; B depends on A; C depends on A
        $adjacency = [
            'D' => ['B', 'C'],
            'B' => ['A'],
            'C' => ['A'],
            'A' => [],
        ];

        $result = $this->sorter->sort($adjacency);

        $this->assertEquals(['A', 'B', 'C', 'D'], $result);
    }

    public function testSortNoDependencies(): void
    {
        $adjacency = [
            'A' => [],
            'B' => [],
            'C' => [],
        ];

        $result = $this->sorter->sort($adjacency);

        $this->assertEquals(['A', 'B', 'C'], $result);
    }

    public function testSortSingleNode(): void
    {
        $adjacency = [
            'A' => [],
        ];

        $result = $this->sorter->sort($adjacency);

        $this->assertEquals(['A'], $result);
    }

    public function testSortEmptyGraph(): void
    {
        $result = $this->sorter->sort([]);

        $this->assertEquals([], $result);
    }

    public function testSortThrowsOnCycle(): void
    {
        // A -> B -> C -> A
        $adjacency = [
            'A' => ['B'],
            'B' => ['C'],
            'C' => ['A'],
        ];

        $this->expectException(\RuntimeException::class);

        $this->sorter->sort($adjacency);
    }

    public function testDetectCyclesFindsMultipleCycles(): void
    {
        // Cycle 1: A -> B -> A
        // Cycle 2: C -> D -> C
        $adjacency = [
            'A' => ['B'],
            'B' => ['A'],
            'C' => ['D'],
            'D' => ['C'],
            'E' => [],
        ];

        $cycles = $this->sorter->detectCycles($adjacency);

        $this->assertCount(2, $cycles);

        // Each cycle should contain the expected nodes (sorted)
        $cycleNodes = array_map(function ($cycle) {
            sort($cycle);
            return $cycle;
        }, $cycles);

        // Sort outer array for deterministic comparison
        usort($cycleNodes, function ($a, $b) {
            return $a[0] <=> $b[0];
        });

        $this->assertEquals(['A', 'B'], $cycleNodes[0]);
        $this->assertEquals(['C', 'D'], $cycleNodes[1]);
    }

    public function testDetectCyclesEmptyForAcyclicGraph(): void
    {
        $adjacency = [
            'A' => ['B'],
            'B' => ['C'],
            'C' => [],
        ];

        $cycles = $this->sorter->detectCycles($adjacency);

        $this->assertEquals([], $cycles);
    }
}
