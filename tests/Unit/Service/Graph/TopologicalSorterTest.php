<?php

namespace Timbrs\DatabaseDumps\Tests\Unit\Service\Graph;

use Timbrs\DatabaseDumps\Service\Graph\SortResult;
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

    public function testSortWithCycleBreakingSelfReferential(): void
    {
        // A depends on itself (self-referential) and B depends on A
        $adjacency = [
            'A' => ['A'],
            'B' => ['A'],
        ];

        $edgeDetails = [
            'A' => [
                'A' => ['source_column' => 'parent_id', 'target_column' => 'id'],
            ],
        ];

        $result = $this->sorter->sortWithCycleBreaking($adjacency, [], $edgeDetails);

        $this->assertInstanceOf(SortResult::class, $result);
        $this->assertEquals(['A', 'B'], $result->getSorted());
        $this->assertTrue($result->hasDeferredEdges());

        $deferred = $result->getDeferredEdges();
        $this->assertCount(1, $deferred);
        $this->assertEquals('A', $deferred[0]['source']);
        $this->assertEquals('A', $deferred[0]['target']);
        $this->assertEquals('parent_id', $deferred[0]['source_column']);
        $this->assertEquals('id', $deferred[0]['target_column']);
    }

    public function testSortWithCycleBreakingNullableEdge(): void
    {
        // Cycle: A -> B -> C -> A, with A->B marked as nullable
        $adjacency = [
            'A' => ['B'],
            'B' => ['C'],
            'C' => ['A'],
        ];

        $nullableEdges = [
            'A->B' => true,
        ];

        $edgeDetails = [
            'A' => [
                'B' => ['source_column' => 'b_id', 'target_column' => 'id'],
            ],
        ];

        $result = $this->sorter->sortWithCycleBreaking($adjacency, $nullableEdges, $edgeDetails);

        $this->assertInstanceOf(SortResult::class, $result);

        $sorted = $result->getSorted();
        $this->assertCount(3, $sorted);
        $this->assertContains('A', $sorted);
        $this->assertContains('B', $sorted);
        $this->assertContains('C', $sorted);

        $this->assertTrue($result->hasDeferredEdges());

        $deferred = $result->getDeferredEdges();
        $this->assertCount(1, $deferred);
        $this->assertEquals('A', $deferred[0]['source']);
        $this->assertEquals('B', $deferred[0]['target']);
        $this->assertEquals('b_id', $deferred[0]['source_column']);
        $this->assertEquals('id', $deferred[0]['target_column']);
    }

    public function testSortWithCycleBreakingNonNullableFallback(): void
    {
        // Cycle: A -> B -> A, no nullable edges
        $adjacency = [
            'A' => ['B'],
            'B' => ['A'],
        ];

        $result = $this->sorter->sortWithCycleBreaking($adjacency);

        $this->assertInstanceOf(SortResult::class, $result);

        $sorted = $result->getSorted();
        $this->assertCount(2, $sorted);
        $this->assertContains('A', $sorted);
        $this->assertContains('B', $sorted);

        $this->assertTrue($result->hasDeferredEdges());

        $deferred = $result->getDeferredEdges();
        $this->assertCount(1, $deferred);

        // One edge in the A<->B cycle must have been broken
        $source = $deferred[0]['source'];
        $target = $deferred[0]['target'];
        $this->assertTrue(
            ($source === 'A' && $target === 'B') || ($source === 'B' && $target === 'A'),
            'Deferred edge should be part of the A<->B cycle'
        );
        $this->assertEquals('', $deferred[0]['source_column']);
        $this->assertEquals('', $deferred[0]['target_column']);
    }

    public function testSortWithCycleBreakingNoCycle(): void
    {
        // Acyclic: A -> B -> C
        $adjacency = [
            'A' => ['B'],
            'B' => ['C'],
            'C' => [],
        ];

        $result = $this->sorter->sortWithCycleBreaking($adjacency);

        $this->assertInstanceOf(SortResult::class, $result);
        $this->assertEquals(['C', 'B', 'A'], $result->getSorted());
        $this->assertFalse($result->hasDeferredEdges());
        $this->assertEquals([], $result->getDeferredEdges());
    }

    public function testSortWithCycleBreakingMultipleCycles(): void
    {
        // Cycle 1: A -> B -> A
        // Cycle 2: C -> D -> C
        // E has no cycle
        $adjacency = [
            'A' => ['B'],
            'B' => ['A'],
            'C' => ['D'],
            'D' => ['C'],
            'E' => [],
        ];

        $result = $this->sorter->sortWithCycleBreaking($adjacency);

        $this->assertInstanceOf(SortResult::class, $result);

        $sorted = $result->getSorted();
        $this->assertCount(5, $sorted);
        $this->assertContains('A', $sorted);
        $this->assertContains('B', $sorted);
        $this->assertContains('C', $sorted);
        $this->assertContains('D', $sorted);
        $this->assertContains('E', $sorted);

        $this->assertTrue($result->hasDeferredEdges());

        $deferred = $result->getDeferredEdges();
        $this->assertCount(2, $deferred);

        // Collect deferred edge pairs for verification
        $deferredPairs = array_map(function ($edge) {
            $pair = [$edge['source'], $edge['target']];
            sort($pair);
            return $pair;
        }, $deferred);

        usort($deferredPairs, function ($a, $b) {
            return $a[0] <=> $b[0];
        });

        // One edge from cycle A<->B and one from cycle C<->D should be deferred
        $this->assertEquals(['A', 'B'], $deferredPairs[0]);
        $this->assertEquals(['C', 'D'], $deferredPairs[1]);
    }
}
