<?php

namespace Timbrs\DatabaseDumps\Tests\Unit\Service\ConfigGenerator;

use Timbrs\DatabaseDumps\Contract\ConnectionRegistryInterface;
use Timbrs\DatabaseDumps\Contract\DatabaseConnectionInterface;
use Timbrs\DatabaseDumps\Platform\PlatformFactory;
use Timbrs\DatabaseDumps\Service\ConfigGenerator\TableInspector;
use PHPUnit\Framework\TestCase;

class TableInspectorTest extends TestCase
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

    public function testListTablesPostgresql(): void
    {
        $this->connection->method('getPlatformName')->willReturn(PlatformFactory::POSTGRESQL);
        $this->connection
            ->expects($this->once())
            ->method('fetchAllAssociative')
            ->with($this->logicalAnd(
                $this->stringContains('information_schema.tables'),
                $this->stringContains("'pg_catalog'"),
                $this->stringContains("'information_schema'")
            ))
            ->willReturn([
                ['table_schema' => 'public', 'table_name' => 'users'],
                ['table_schema' => 'public', 'table_name' => 'orders'],
            ]);

        $inspector = new TableInspector($this->registry);
        $tables = $inspector->listTables();

        $this->assertCount(2, $tables);
        $this->assertEquals('users', $tables[0]['table_name']);
        $this->assertEquals('orders', $tables[1]['table_name']);
    }

    public function testListTablesMysql(): void
    {
        $this->connection->method('getPlatformName')->willReturn(PlatformFactory::MYSQL);
        $this->connection
            ->expects($this->once())
            ->method('fetchAllAssociative')
            ->with($this->logicalAnd(
                $this->stringContains('information_schema.tables'),
                $this->stringContains("'mysql'"),
                $this->stringContains("'performance_schema'"),
                $this->stringContains("'sys'")
            ))
            ->willReturn([
                ['table_schema' => 'mydb', 'table_name' => 'products'],
            ]);

        $inspector = new TableInspector($this->registry);
        $tables = $inspector->listTables();

        $this->assertCount(1, $tables);
        $this->assertEquals('products', $tables[0]['table_name']);
    }

    public function testCountRows(): void
    {
        $this->connection->method('getPlatformName')->willReturn(PlatformFactory::POSTGRESQL);
        $this->connection
            ->expects($this->once())
            ->method('fetchAllAssociative')
            ->with($this->stringContains('COUNT(*)'))
            ->willReturn([['cnt' => 42]]);

        $inspector = new TableInspector($this->registry);
        $count = $inspector->countRows('public', 'users');

        $this->assertEquals(42, $count);
    }

    public function testCountRowsMysql(): void
    {
        $this->connection->method('getPlatformName')->willReturn(PlatformFactory::MYSQL);
        $this->connection
            ->expects($this->once())
            ->method('fetchAllAssociative')
            ->with($this->stringContains('`public`.`users`'))
            ->willReturn([['cnt' => 100]]);

        $inspector = new TableInspector($this->registry);
        $count = $inspector->countRows('public', 'users');

        $this->assertEquals(100, $count);
    }

    public function testDetectOrderColumnUpdatedAt(): void
    {
        $this->connection->method('getPlatformName')->willReturn(PlatformFactory::POSTGRESQL);
        $this->connection->method('quote')->willReturnCallback(function ($value) {
            return "'{$value}'";
        });
        $this->connection
            ->expects($this->once())
            ->method('fetchAllAssociative')
            ->willReturn([
                ['column_name' => 'id'],
                ['column_name' => 'name'],
                ['column_name' => 'created_at'],
                ['column_name' => 'updated_at'],
            ]);

        $inspector = new TableInspector($this->registry);
        $orderColumn = $inspector->detectOrderColumn('public', 'users');

        $this->assertEquals('updated_at DESC', $orderColumn);
    }

    public function testDetectOrderColumnCreatedAt(): void
    {
        $this->connection->method('getPlatformName')->willReturn(PlatformFactory::POSTGRESQL);
        $this->connection->method('quote')->willReturnCallback(function ($value) {
            return "'{$value}'";
        });
        $this->connection
            ->expects($this->once())
            ->method('fetchAllAssociative')
            ->willReturn([
                ['column_name' => 'id'],
                ['column_name' => 'name'],
                ['column_name' => 'created_at'],
            ]);

        $inspector = new TableInspector($this->registry);
        $orderColumn = $inspector->detectOrderColumn('public', 'users');

        $this->assertEquals('created_at DESC', $orderColumn);
    }

    public function testDetectOrderColumnId(): void
    {
        $this->connection->method('getPlatformName')->willReturn(PlatformFactory::POSTGRESQL);
        $this->connection->method('quote')->willReturnCallback(function ($value) {
            return "'{$value}'";
        });
        $this->connection
            ->expects($this->once())
            ->method('fetchAllAssociative')
            ->willReturn([
                ['column_name' => 'id'],
                ['column_name' => 'name'],
                ['column_name' => 'email'],
            ]);

        $inspector = new TableInspector($this->registry);
        $orderColumn = $inspector->detectOrderColumn('public', 'users');

        $this->assertEquals('id DESC', $orderColumn);
    }

    public function testDetectOrderColumnFallbackToFirstColumn(): void
    {
        $this->connection->method('getPlatformName')->willReturn(PlatformFactory::POSTGRESQL);
        $this->connection->method('quote')->willReturnCallback(function ($value) {
            return "'{$value}'";
        });
        $this->connection
            ->expects($this->once())
            ->method('fetchAllAssociative')
            ->willReturn([
                ['column_name' => 'uuid'],
                ['column_name' => 'name'],
            ]);

        $inspector = new TableInspector($this->registry);
        $orderColumn = $inspector->detectOrderColumn('public', 'custom_table');

        $this->assertEquals('uuid DESC', $orderColumn);
    }

    public function testDetectOrderColumnUpdateAt(): void
    {
        $this->connection->method('getPlatformName')->willReturn(PlatformFactory::POSTGRESQL);
        $this->connection->method('quote')->willReturnCallback(function ($value) {
            return "'{$value}'";
        });
        $this->connection
            ->expects($this->once())
            ->method('fetchAllAssociative')
            ->willReturn([
                ['column_name' => 'id'],
                ['column_name' => 'update_at'],
                ['column_name' => 'create_at'],
            ]);

        $inspector = new TableInspector($this->registry);
        $orderColumn = $inspector->detectOrderColumn('public', 'users');

        $this->assertEquals('update_at DESC', $orderColumn);
    }

    public function testListTablesOracle(): void
    {
        $this->connection->method('getPlatformName')->willReturn(PlatformFactory::ORACLE);
        $this->connection
            ->expects($this->once())
            ->method('fetchAllAssociative')
            ->with($this->logicalAnd(
                $this->stringContains('all_tables'),
                $this->stringContains("'SYS'"),
                $this->stringContains("'SYSTEM'")
            ))
            ->willReturn([
                ['table_schema' => 'hr', 'table_name' => 'employees'],
                ['table_schema' => 'hr', 'table_name' => 'departments'],
            ]);

        $inspector = new TableInspector($this->registry);
        $tables = $inspector->listTables();

        $this->assertCount(2, $tables);
        $this->assertEquals('employees', $tables[0]['table_name']);
        $this->assertEquals('hr', $tables[0]['table_schema']);
    }

    public function testCountRowsOracle(): void
    {
        $this->connection->method('getPlatformName')->willReturn(PlatformFactory::ORACLE);
        $this->connection
            ->expects($this->once())
            ->method('fetchAllAssociative')
            ->with($this->stringContains('"HR"."EMPLOYEES"'))
            ->willReturn([['CNT' => 50]]);

        $inspector = new TableInspector($this->registry);
        $count = $inspector->countRows('hr', 'employees');

        $this->assertEquals(50, $count);
    }

    public function testDetectOrderColumnOracle(): void
    {
        $this->connection->method('getPlatformName')->willReturn(PlatformFactory::ORACLE);
        $this->connection->method('quote')->willReturnCallback(function ($value) {
            return "'{$value}'";
        });
        $this->connection
            ->expects($this->once())
            ->method('fetchAllAssociative')
            ->with($this->logicalAnd(
                $this->stringContains('all_tab_columns'),
                $this->stringContains("'HR'"),
                $this->stringContains("'EMPLOYEES'")
            ))
            ->willReturn([
                ['column_name' => 'id'],
                ['column_name' => 'name'],
                ['column_name' => 'updated_at'],
            ]);

        $inspector = new TableInspector($this->registry);
        $orderColumn = $inspector->detectOrderColumn('hr', 'employees');

        $this->assertEquals('updated_at DESC', $orderColumn);
    }
}
