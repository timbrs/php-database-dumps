<?php

namespace Timbrs\DatabaseDumps\Tests\Unit\Service\ConfigGenerator;

use PHPUnit\Framework\TestCase;
use Timbrs\DatabaseDumps\Contract\ConnectionRegistryInterface;
use Timbrs\DatabaseDumps\Contract\DatabaseConnectionInterface;
use Timbrs\DatabaseDumps\Service\ConfigGenerator\PrimaryKeyInspector;

class PrimaryKeyInspectorTest extends TestCase
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

    public function testDetectsSinglePkPostgres(): void
    {
        $this->connection->method('getPlatformName')->willReturn('postgresql');
        $this->connection
            ->expects($this->once())
            ->method('fetchAllAssociative')
            ->with(
                $this->stringContains("constraint_type = 'PRIMARY KEY'"),
                ['schema' => 'public', 'table' => 'clients']
            )
            ->willReturn([['column_name' => 'id']]);

        $inspector = new PrimaryKeyInspector($this->registry);
        $this->assertSame(['id'], $inspector->getPrimaryKeyColumns('public', 'clients'));
    }

    public function testDetectsCompositePkMysqlOrdered(): void
    {
        $this->connection->method('getPlatformName')->willReturn('mysql');
        $this->connection
            ->method('fetchAllAssociative')
            ->willReturn([
                ['column_name' => 'order_id'],
                ['column_name' => 'product_id'],
            ]);

        $inspector = new PrimaryKeyInspector($this->registry);
        $this->assertSame(['order_id', 'product_id'], $inspector->getPrimaryKeyColumns('shop', 'order_items'));
    }

    public function testDetectsPkOracleUppercaseParams(): void
    {
        $this->connection->method('getPlatformName')->willReturn('oracle');
        $this->connection
            ->expects($this->once())
            ->method('fetchAllAssociative')
            ->with(
                $this->stringContains("c.constraint_type = 'P'"),
                ['owner' => 'HR', 'tbl' => 'EMPLOYEES']
            )
            ->willReturn([['column_name' => 'emp_id']]);

        $inspector = new PrimaryKeyInspector($this->registry);
        $this->assertSame(['emp_id'], $inspector->getPrimaryKeyColumns('hr', 'employees'));
    }

    public function testReturnsEmptyWhenNoPk(): void
    {
        $this->connection->method('getPlatformName')->willReturn('postgresql');
        $this->connection->method('fetchAllAssociative')->willReturn([]);

        $inspector = new PrimaryKeyInspector($this->registry);
        $this->assertSame([], $inspector->getPrimaryKeyColumns('public', 'logs'));
    }

    public function testCachesResultPerTable(): void
    {
        $this->connection->method('getPlatformName')->willReturn('postgresql');
        $this->connection
            ->expects($this->once()) // только один запрос несмотря на два вызова
            ->method('fetchAllAssociative')
            ->willReturn([['column_name' => 'id']]);

        $inspector = new PrimaryKeyInspector($this->registry);
        $inspector->getPrimaryKeyColumns('public', 'clients');
        $inspector->getPrimaryKeyColumns('public', 'clients');
    }

    public function testHandlesUppercaseResultKeys(): void
    {
        // Doctrine/PDO могут вернуть COLUMN_NAME в верхнем регистре.
        $this->connection->method('getPlatformName')->willReturn('mysql');
        $this->connection->method('fetchAllAssociative')->willReturn([['COLUMN_NAME' => 'id']]);

        $inspector = new PrimaryKeyInspector($this->registry);
        $this->assertSame(['id'], $inspector->getPrimaryKeyColumns('shop', 'clients'));
    }

    public function testCacheKeyDistinguishesByConnectionName(): void
    {
        // Один и тот же schema.table на разных подключениях — два отдельных запроса.
        $this->connection->method('getPlatformName')->willReturn('postgresql');
        $this->connection
            ->expects($this->exactly(2))
            ->method('fetchAllAssociative')
            ->willReturn([['column_name' => 'id']]);

        $inspector = new PrimaryKeyInspector($this->registry);
        $inspector->getPrimaryKeyColumns('public', 'clients', 'conn_a');
        $inspector->getPrimaryKeyColumns('public', 'clients', 'conn_a'); // из кэша
        $inspector->getPrimaryKeyColumns('public', 'clients', 'conn_b'); // новый запрос
    }

    public function testSkipsEmptyOrNullColumnNames(): void
    {
        $this->connection->method('getPlatformName')->willReturn('postgresql');
        $this->connection->method('fetchAllAssociative')->willReturn([
            ['column_name' => 'id'],
            ['column_name' => null],
            ['column_name' => ''],
            ['column_name' => 'tenant_id'],
        ]);

        $inspector = new PrimaryKeyInspector($this->registry);
        $this->assertSame(['id', 'tenant_id'], $inspector->getPrimaryKeyColumns('public', 'clients'));
    }

    public function testBindsConnectionNameToRegistry(): void
    {
        $this->connection->method('getPlatformName')->willReturn('postgresql');
        $this->connection->method('fetchAllAssociative')->willReturn([['column_name' => 'id']]);

        $this->registry
            ->expects($this->once())
            ->method('getConnection')
            ->with('reporting');

        $inspector = new PrimaryKeyInspector($this->registry);
        $inspector->getPrimaryKeyColumns('public', 'clients', 'reporting');
    }
}
