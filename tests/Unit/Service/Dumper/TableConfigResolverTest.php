<?php

namespace Timbrs\DatabaseDumps\Tests\Unit\Service\Dumper;

use PHPUnit\Framework\TestCase;
use Timbrs\DatabaseDumps\Config\DumpConfig;
use Timbrs\DatabaseDumps\Config\TableConfig;
use Timbrs\DatabaseDumps\Contract\ConnectionRegistryInterface;
use Timbrs\DatabaseDumps\Service\Dumper\TableConfigResolver;

class TableConfigResolverTest extends TestCase
{
    /** @var TableConfigResolver */
    private $resolver;

    protected function setUp(): void
    {
        $dumpConfig = new DumpConfig(
            [
                'users' => ['users', 'roles'],
                'system' => ['settings']
            ],
            [
                'clients' => [
                    'clients' => [TableConfig::KEY_LIMIT => 100, TableConfig::KEY_ORDER_BY => 'created_at DESC']
                ]
            ]
        );

        $this->resolver = new TableConfigResolver($dumpConfig);
    }

    public function testResolveFullExportTable(): void
    {
        $config = $this->resolver->resolve('users', 'users');

        $this->assertEquals('users', $config->getSchema());
        $this->assertEquals('users', $config->getTable());
        $this->assertTrue($config->isFullExport());
        $this->assertNull($config->getLimit());
        $this->assertNull($config->getConnectionName());
    }

    public function testResolvePartialExportTable(): void
    {
        $config = $this->resolver->resolve('clients', 'clients');

        $this->assertEquals('clients', $config->getSchema());
        $this->assertEquals('clients', $config->getTable());
        $this->assertTrue($config->isPartialExport());
        $this->assertEquals(100, $config->getLimit());
        $this->assertEquals('created_at DESC', $config->getOrderBy());
    }

    public function testResolveAllFromSchema(): void
    {
        $tables = $this->resolver->resolveAllFromSchema('users');

        $this->assertCount(2, $tables);

        $tableNames = array_map(function ($config) { return $config->getTable(); }, $tables);
        $this->assertContains('users', $tableNames);
        $this->assertContains('roles', $tableNames);
    }

    public function testResolveAll(): void
    {
        $tables = $this->resolver->resolveAll();

        $this->assertGreaterThanOrEqual(3, count($tables));

        $fullNames = array_map(function ($config) { return $config->getFullTableName(); }, $tables);
        $this->assertContains('users.users', $fullNames);
        $this->assertContains('clients.clients', $fullNames);
    }

    public function testResolveAllWithSchemaFilter(): void
    {
        $tables = $this->resolver->resolveAll('users');

        $this->assertCount(2, $tables);

        foreach ($tables as $table) {
            $this->assertEquals('users', $table->getSchema());
        }
    }

    public function testResolveAllWithConnectionFilter(): void
    {
        $mysqlConfig = new DumpConfig(
            ['app_db' => ['events', 'metrics']],
            []
        );

        $dumpConfig = new DumpConfig(
            ['public' => ['users']],
            [],
            ['mysql' => $mysqlConfig]
        );

        $resolver = new TableConfigResolver($dumpConfig);

        // Дефолтное подключение
        $tables = $resolver->resolveAll();
        $this->assertCount(1, $tables);
        $this->assertEquals('users', $tables[0]->getTable());
        $this->assertNull($tables[0]->getConnectionName());

        // Конкретное подключение mysql
        $tables = $resolver->resolveAll(null, 'mysql');
        $this->assertCount(2, $tables);
        foreach ($tables as $table) {
            $this->assertEquals('mysql', $table->getConnectionName());
        }

        // Все подключения
        $tables = $resolver->resolveAll(null, ConnectionRegistryInterface::CONNECTION_ALL);
        $this->assertCount(3, $tables);
    }

    public function testResolveWithConnectionName(): void
    {
        $mysqlConfig = new DumpConfig(
            ['app_db' => ['events']],
            ['app_db' => ['logs' => [TableConfig::KEY_LIMIT => 1000]]]
        );

        $dumpConfig = new DumpConfig(
            ['public' => ['users']],
            [],
            ['mysql' => $mysqlConfig]
        );

        $resolver = new TableConfigResolver($dumpConfig);

        $config = $resolver->resolve('app_db', 'events', 'mysql');
        $this->assertEquals('events', $config->getTable());
        $this->assertEquals('mysql', $config->getConnectionName());

        $config = $resolver->resolve('app_db', 'logs', 'mysql');
        $this->assertEquals('logs', $config->getTable());
        $this->assertEquals(1000, $config->getLimit());
        $this->assertEquals('mysql', $config->getConnectionName());
    }
}
