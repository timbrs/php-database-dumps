<?php

namespace Timbrs\DatabaseDumps\Tests\Unit\Service\ConfigGenerator;

use Timbrs\DatabaseDumps\Contract\ConnectionRegistryInterface;
use Timbrs\DatabaseDumps\Contract\DatabaseConnectionInterface;
use Timbrs\DatabaseDumps\Platform\PlatformFactory;
use Timbrs\DatabaseDumps\Service\ConfigGenerator\ForeignKeyInspector;
use PHPUnit\Framework\TestCase;

class ForeignKeyInspectorTest extends TestCase
{
    /** @var DatabaseConnectionInterface&\PHPUnit\Framework\MockObject\MockObject */
    private $connection;

    /** @var ConnectionRegistryInterface&\PHPUnit\Framework\MockObject\MockObject */
    private $registry;

    protected function setUp(): void
    {
        $this->connection = $this->createMock(DatabaseConnectionInterface::class);

        $this->registry = $this->createMock(ConnectionRegistryInterface::class);
        $this->registry->method('getConnection')->willReturn($this->connection);
    }

    public function testGetForeignKeysPostgres(): void
    {
        $this->connection->method('getPlatformName')->willReturn('postgresql');

        $fkRows = [
            [
                'constraint_name' => 'fk_orders_user_id',
                'source_schema' => 'public',
                'source_table' => 'orders',
                'source_column' => 'user_id',
                'target_schema' => 'public',
                'target_table' => 'users',
                'target_column' => 'id',
            ],
        ];

        $this->connection
            ->expects($this->once())
            ->method('fetchAllAssociative')
            ->with($this->logicalAnd(
                $this->stringContains('information_schema.table_constraints'),
                $this->stringContains("'pg_catalog'"),
                $this->stringContains("'information_schema'"),
                $this->stringContains('FOREIGN KEY')
            ))
            ->willReturn($fkRows);

        $inspector = new ForeignKeyInspector($this->registry);
        $result = $inspector->getForeignKeys();

        $this->assertCount(1, $result);
        $this->assertEquals('fk_orders_user_id', $result[0]['constraint_name']);
        $this->assertEquals('orders', $result[0]['source_table']);
        $this->assertEquals('users', $result[0]['target_table']);
    }

    public function testGetForeignKeysMysql(): void
    {
        $this->connection->method('getPlatformName')->willReturn('mysql');

        $fkRows = [
            [
                'constraint_name' => 'fk_orders_user_id',
                'source_schema' => 'mydb',
                'source_table' => 'orders',
                'source_column' => 'user_id',
                'target_schema' => 'mydb',
                'target_table' => 'users',
                'target_column' => 'id',
            ],
        ];

        $this->connection
            ->expects($this->once())
            ->method('fetchAllAssociative')
            ->with($this->logicalAnd(
                $this->stringContains('information_schema.TABLE_CONSTRAINTS'),
                $this->stringContains("'mysql'"),
                $this->stringContains("'performance_schema'"),
                $this->stringContains("'sys'"),
                $this->stringContains('FOREIGN KEY')
            ))
            ->willReturn($fkRows);

        $inspector = new ForeignKeyInspector($this->registry);
        $result = $inspector->getForeignKeys();

        $this->assertCount(1, $result);
        $this->assertEquals('fk_orders_user_id', $result[0]['constraint_name']);
        $this->assertEquals('mydb', $result[0]['source_schema']);
    }

    public function testFiltersSelfReferentialFks(): void
    {
        $this->connection->method('getPlatformName')->willReturn('postgresql');

        $fkRows = [
            [
                'constraint_name' => 'fk_orders_user_id',
                'source_schema' => 'public',
                'source_table' => 'orders',
                'source_column' => 'user_id',
                'target_schema' => 'public',
                'target_table' => 'users',
                'target_column' => 'id',
            ],
            [
                'constraint_name' => 'fk_categories_parent',
                'source_schema' => 'public',
                'source_table' => 'categories',
                'source_column' => 'parent_id',
                'target_schema' => 'public',
                'target_table' => 'categories',
                'target_column' => 'id',
            ],
        ];

        $this->connection->method('fetchAllAssociative')->willReturn($fkRows);

        $inspector = new ForeignKeyInspector($this->registry);
        $result = $inspector->getForeignKeys();

        $this->assertCount(1, $result);
        $this->assertEquals('fk_orders_user_id', $result[0]['constraint_name']);
    }

    public function testEmptyResult(): void
    {
        $this->connection->method('getPlatformName')->willReturn('postgresql');
        $this->connection->method('fetchAllAssociative')->willReturn([]);

        $inspector = new ForeignKeyInspector($this->registry);
        $result = $inspector->getForeignKeys();

        $this->assertEquals([], $result);
    }

    public function testWithConnectionName(): void
    {
        $this->connection->method('getPlatformName')->willReturn('mysql');
        $this->connection->method('fetchAllAssociative')->willReturn([]);

        $this->registry = $this->createMock(ConnectionRegistryInterface::class);
        $this->registry
            ->expects($this->once())
            ->method('getConnection')
            ->with('secondary')
            ->willReturn($this->connection);

        $inspector = new ForeignKeyInspector($this->registry);
        $result = $inspector->getForeignKeys('secondary');

        $this->assertEquals([], $result);
    }

    public function testGetForeignKeysOracle(): void
    {
        $this->connection->method('getPlatformName')->willReturn(PlatformFactory::ORACLE);

        $fkRows = [
            [
                'constraint_name' => 'fk_orders_user_id',
                'source_schema' => 'hr',
                'source_table' => 'orders',
                'source_column' => 'user_id',
                'target_schema' => 'hr',
                'target_table' => 'users',
                'target_column' => 'id',
            ],
        ];

        $this->connection
            ->expects($this->once())
            ->method('fetchAllAssociative')
            ->with($this->logicalAnd(
                $this->stringContains('all_constraints'),
                $this->stringContains('all_cons_columns'),
                $this->stringContains("'SYS'"),
                $this->stringContains("'SYSTEM'"),
                $this->stringContains("constraint_type = 'R'")
            ))
            ->willReturn($fkRows);

        $inspector = new ForeignKeyInspector($this->registry);
        $result = $inspector->getForeignKeys();

        $this->assertCount(1, $result);
        $this->assertEquals('fk_orders_user_id', $result[0]['constraint_name']);
        $this->assertEquals('orders', $result[0]['source_table']);
        $this->assertEquals('users', $result[0]['target_table']);
    }
}
