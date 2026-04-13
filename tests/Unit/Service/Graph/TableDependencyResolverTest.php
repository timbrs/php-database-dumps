<?php

namespace Timbrs\DatabaseDumps\Tests\Unit\Service\Graph;

use Timbrs\DatabaseDumps\Service\ConfigGenerator\ForeignKeyInspector;
use Timbrs\DatabaseDumps\Service\Graph\TableDependencyResolver;
use Timbrs\DatabaseDumps\Service\Graph\TopologicalSorter;
use PHPUnit\Framework\TestCase;

class TableDependencyResolverTest extends TestCase
{
    /** @var ForeignKeyInspector&\PHPUnit\Framework\MockObject\MockObject */
    private $fkInspector;
    /** @var TableDependencyResolver */
    private $resolver;

    protected function setUp(): void
    {
        $this->fkInspector = $this->createMock(ForeignKeyInspector::class);
        $this->fkInspector->method('getForeignKeyNullability')->willReturn([]);
        $this->resolver = new TableDependencyResolver($this->fkInspector, new TopologicalSorter());
    }

    public function testSortForExportWithFkGraph(): void
    {
        // orders -> users (orders depends on users)
        $this->fkInspector->method('getForeignKeys')->willReturn([
            [
                'constraint_name' => 'fk_orders_user',
                'source_schema' => 'public',
                'source_table' => 'orders',
                'source_column' => 'user_id',
                'target_schema' => 'public',
                'target_table' => 'users',
                'target_column' => 'id',
            ],
        ]);

        $sorted = $this->resolver->sortForExport(['public.users', 'public.orders']);
        $this->assertEquals(['public.users', 'public.orders'], $sorted);
    }

    public function testSortForExportIgnoresExternalParents(): void
    {
        // orders -> users, but users not in tableKeys
        $this->fkInspector->method('getForeignKeys')->willReturn([
            [
                'constraint_name' => 'fk_orders_user',
                'source_schema' => 'public',
                'source_table' => 'orders',
                'source_column' => 'user_id',
                'target_schema' => 'public',
                'target_table' => 'users',
                'target_column' => 'id',
            ],
        ]);

        $sorted = $this->resolver->sortForExport(['public.orders']);
        $this->assertEquals(['public.orders'], $sorted);
    }

    public function testGetDependencyGraph(): void
    {
        $this->fkInspector->method('getForeignKeys')->willReturn([
            [
                'constraint_name' => 'fk_orders_user',
                'source_schema' => 'public',
                'source_table' => 'orders',
                'source_column' => 'user_id',
                'target_schema' => 'public',
                'target_table' => 'users',
                'target_column' => 'id',
            ],
        ]);

        $graph = $this->resolver->getDependencyGraph();
        $this->assertArrayHasKey('public.orders', $graph);
        $this->assertArrayHasKey('public.users', $graph['public.orders']);
        $this->assertEquals('user_id', $graph['public.orders']['public.users']['source_column']);
    }

    public function testGetCascadeFromCandidates(): void
    {
        $this->fkInspector->method('getForeignKeys')->willReturn([
            [
                'constraint_name' => 'fk_orders_user',
                'source_schema' => 'public',
                'source_table' => 'orders',
                'source_column' => 'user_id',
                'target_schema' => 'public',
                'target_table' => 'users',
                'target_column' => 'id',
            ],
        ]);

        $candidates = $this->resolver->getCascadeFromCandidates('public', 'orders');
        $this->assertCount(1, $candidates);
        $this->assertEquals('public.users', $candidates[0]['parent']);
        $this->assertEquals('user_id', $candidates[0]['fk_column']);
        $this->assertEquals('id', $candidates[0]['parent_column']);
    }

    public function testGetCascadeFromCandidatesEmpty(): void
    {
        $this->fkInspector->method('getForeignKeys')->willReturn([]);
        $candidates = $this->resolver->getCascadeFromCandidates('public', 'users');
        $this->assertEmpty($candidates);
    }

    public function testCaching(): void
    {
        $this->fkInspector->expects($this->once())->method('getForeignKeys')->willReturn([]);
        // Call twice — should only query once
        $this->resolver->getDependencyGraph();
        $this->resolver->getDependencyGraph();
    }

    public function testSortForImport(): void
    {
        $this->fkInspector->method('getForeignKeys')->willReturn([
            [
                'constraint_name' => 'fk_orders_user',
                'source_schema' => 'public',
                'source_table' => 'orders',
                'source_column' => 'user_id',
                'target_schema' => 'public',
                'target_table' => 'users',
                'target_column' => 'id',
            ],
        ]);

        $sorted = $this->resolver->sortForImport(['public.orders', 'public.users']);
        $this->assertEquals(['public.users', 'public.orders'], $sorted);
    }
}
