<?php

namespace Timbrs\DatabaseDumps\Tests\Unit\Service\Dumper;

use Timbrs\DatabaseDumps\Config\DumpConfig;
use Timbrs\DatabaseDumps\Config\TableConfig;
use Timbrs\DatabaseDumps\Contract\ConnectionRegistryInterface;
use Timbrs\DatabaseDumps\Contract\DatabaseConnectionInterface;
use Timbrs\DatabaseDumps\Platform\MySqlPlatform;
use Timbrs\DatabaseDumps\Platform\OraclePlatform;
use Timbrs\DatabaseDumps\Platform\PostgresPlatform;
use Timbrs\DatabaseDumps\Service\Dumper\CascadeWhereResolver;
use PHPUnit\Framework\TestCase;

class CascadeWhereResolverTest extends TestCase
{
    /** @var CascadeWhereResolver */
    private $resolver;

    protected function setUp(): void
    {
        $connection = $this->createMock(DatabaseConnectionInterface::class);
        $connection->method('getPlatformName')->willReturn('postgresql');

        $registry = $this->createMock(ConnectionRegistryInterface::class);
        $registry->method('getPlatform')->willReturn(new PostgresPlatform());
        $registry->method('getConnection')->willReturn($connection);
        $this->resolver = new CascadeWhereResolver($registry);
    }

    public function testResolveReturnsNullWhenNoCascade(): void
    {
        $config = new TableConfig('public', 'users');
        $dumpConfig = new DumpConfig([], []);
        $this->assertNull($this->resolver->resolve($config, $dumpConfig));
    }

    public function testResolveReturnsNullWhenParentInFullExport(): void
    {
        $config = new TableConfig('public', 'orders', 500, null, null, null, [
            ['parent' => 'public.users', 'fk_column' => 'user_id', 'parent_column' => 'id'],
        ]);
        $dumpConfig = new DumpConfig(
            ['public' => ['users']],  // full_export
            []
        );
        $this->assertNull($this->resolver->resolve($config, $dumpConfig));
    }

    public function testResolveGeneratesSubqueryForPartialParent(): void
    {
        $config = new TableConfig('public', 'orders', 500, null, 'id DESC', null, [
            ['parent' => 'public.users', 'fk_column' => 'user_id', 'parent_column' => 'id'],
        ]);
        $dumpConfig = new DumpConfig(
            [],
            ['public' => [
                'users' => [
                    TableConfig::KEY_LIMIT => 100,
                    TableConfig::KEY_ORDER_BY => 'created_at DESC',
                    TableConfig::KEY_WHERE => 'is_active = true',
                ],
            ]]
        );
        $result = $this->resolver->resolve($config, $dumpConfig);
        $this->assertNotNull($result);
        $this->assertStringContainsString('(user_id IN (SELECT "id" FROM "public"."users"', $result);
        $this->assertStringContainsString('OR user_id IS NULL)', $result);
        $this->assertStringContainsString('WHERE is_active = true', $result);
        $this->assertStringContainsString('ORDER BY created_at DESC', $result);
        $this->assertStringContainsString('LIMIT 100', $result);
    }

    public function testResolveMultipleCascadesWithAnd(): void
    {
        $config = new TableConfig('public', 'order_items', 500, null, null, null, [
            ['parent' => 'public.users', 'fk_column' => 'user_id', 'parent_column' => 'id'],
            ['parent' => 'public.orders', 'fk_column' => 'order_id', 'parent_column' => 'id'],
        ]);
        $dumpConfig = new DumpConfig(
            [],
            ['public' => [
                'users' => [TableConfig::KEY_LIMIT => 100],
                'orders' => [TableConfig::KEY_LIMIT => 200],
            ]]
        );
        $result = $this->resolver->resolve($config, $dumpConfig);
        $this->assertNotNull($result);
        $this->assertStringContainsString('(user_id IN (', $result);
        $this->assertStringContainsString('OR user_id IS NULL)', $result);
        $this->assertStringContainsString('(order_id IN (', $result);
        $this->assertStringContainsString('OR order_id IS NULL)', $result);
        $this->assertStringContainsString(' AND ', $result);
    }

    public function testResolveReturnsNullWhenParentNotInConfig(): void
    {
        $config = new TableConfig('public', 'orders', 500, null, null, null, [
            ['parent' => 'public.users', 'fk_column' => 'user_id', 'parent_column' => 'id'],
        ]);
        $dumpConfig = new DumpConfig([], []);
        $this->assertNull($this->resolver->resolve($config, $dumpConfig));
    }

    public function testResolveWithChainedCascade(): void
    {
        // order_items -> orders -> users (3 levels)
        $config = new TableConfig('public', 'order_items', 500, null, null, null, [
            ['parent' => 'public.orders', 'fk_column' => 'order_id', 'parent_column' => 'id'],
        ]);
        $dumpConfig = new DumpConfig(
            [],
            ['public' => [
                'users' => [
                    TableConfig::KEY_LIMIT => 100,
                    TableConfig::KEY_WHERE => 'is_active = true',
                ],
                'orders' => [
                    TableConfig::KEY_LIMIT => 200,
                    TableConfig::KEY_CASCADE_FROM => [
                        ['parent' => 'public.users', 'fk_column' => 'user_id', 'parent_column' => 'id'],
                    ],
                ],
            ]]
        );
        $result = $this->resolver->resolve($config, $dumpConfig);
        $this->assertNotNull($result);
        // Should have nested subquery
        $this->assertStringContainsString('(order_id IN (SELECT "id" FROM "public"."orders"', $result);
        $this->assertStringContainsString('OR order_id IS NULL)', $result);
        $this->assertStringContainsString('(user_id IN (SELECT "id" FROM "public"."users"', $result);
        $this->assertStringContainsString('OR user_id IS NULL)', $result);
    }

    public function testResolveUsesOracleLimitSyntax(): void
    {
        $connection = $this->createMock(DatabaseConnectionInterface::class);
        $connection->method('getPlatformName')->willReturn('oracle');

        $registry = $this->createMock(ConnectionRegistryInterface::class);
        $registry->method('getPlatform')->willReturn(new OraclePlatform());
        $registry->method('getConnection')->willReturn($connection);
        $resolver = new CascadeWhereResolver($registry);

        $config = new TableConfig('public', 'orders', 500, null, 'id DESC', null, [
            ['parent' => 'public.users', 'fk_column' => 'user_id', 'parent_column' => 'id'],
        ]);
        $dumpConfig = new DumpConfig(
            [],
            ['public' => [
                'users' => [
                    TableConfig::KEY_LIMIT => 100,
                    TableConfig::KEY_ORDER_BY => 'created_at DESC',
                ],
            ]]
        );
        $result = $resolver->resolve($config, $dumpConfig);
        $this->assertNotNull($result);
        $this->assertStringContainsString('(user_id IN (', $result);
        $this->assertStringContainsString('OR user_id IS NULL)', $result);
        $this->assertStringContainsString('FETCH FIRST 100 ROWS ONLY', $result);
        $this->assertStringNotContainsString('LIMIT', $result);
    }

    public function testMysqlWrapsSubqueryWithLimitInDerivedTable(): void
    {
        $connection = $this->createMock(DatabaseConnectionInterface::class);
        $connection->method('getPlatformName')->willReturn('mysql');

        $registry = $this->createMock(ConnectionRegistryInterface::class);
        $registry->method('getPlatform')->willReturn(new MySqlPlatform());
        $registry->method('getConnection')->willReturn($connection);
        $resolver = new CascadeWhereResolver($registry);

        $config = new TableConfig('public', 'orders', 500, null, null, null, [
            ['parent' => 'public.users', 'fk_column' => 'user_id', 'parent_column' => 'id'],
        ]);
        $dumpConfig = new DumpConfig(
            [],
            ['public' => [
                'users' => [
                    TableConfig::KEY_LIMIT => 100,
                    TableConfig::KEY_ORDER_BY => 'created_at DESC',
                    TableConfig::KEY_WHERE => 'is_active = 1',
                ],
            ]]
        );
        $result = $resolver->resolve($config, $dumpConfig);
        $this->assertNotNull($result);
        // Подзапрос обёрнут: SELECT * FROM (...) AS _cascade_0
        $this->assertStringContainsString('(user_id IN (SELECT * FROM (SELECT `id` FROM `public`.`users`', $result);
        $this->assertStringContainsString('OR user_id IS NULL)', $result);
        $this->assertStringContainsString('AS _cascade_0', $result);
        $this->assertStringContainsString('LIMIT 100', $result);
    }

    public function testMysqlUniqueAliasesForMultipleCascades(): void
    {
        $connection = $this->createMock(DatabaseConnectionInterface::class);
        $connection->method('getPlatformName')->willReturn('mysql');

        $registry = $this->createMock(ConnectionRegistryInterface::class);
        $registry->method('getPlatform')->willReturn(new MySqlPlatform());
        $registry->method('getConnection')->willReturn($connection);
        $resolver = new CascadeWhereResolver($registry);

        $config = new TableConfig('public', 'order_items', 500, null, null, null, [
            ['parent' => 'public.users', 'fk_column' => 'user_id', 'parent_column' => 'id'],
            ['parent' => 'public.orders', 'fk_column' => 'order_id', 'parent_column' => 'id'],
        ]);
        $dumpConfig = new DumpConfig(
            [],
            ['public' => [
                'users' => [TableConfig::KEY_LIMIT => 100],
                'orders' => [TableConfig::KEY_LIMIT => 200],
            ]]
        );
        $result = $resolver->resolve($config, $dumpConfig);
        $this->assertNotNull($result);
        $this->assertStringContainsString('OR user_id IS NULL)', $result);
        $this->assertStringContainsString('OR order_id IS NULL)', $result);
        $this->assertStringContainsString('_cascade_0', $result);
        $this->assertStringContainsString('_cascade_1', $result);
    }

    public function testMysqlNoWrapWithoutLimit(): void
    {
        $connection = $this->createMock(DatabaseConnectionInterface::class);
        $connection->method('getPlatformName')->willReturn('mysql');

        $registry = $this->createMock(ConnectionRegistryInterface::class);
        $registry->method('getPlatform')->willReturn(new MySqlPlatform());
        $registry->method('getConnection')->willReturn($connection);
        $resolver = new CascadeWhereResolver($registry);

        $config = new TableConfig('public', 'orders', 500, null, null, null, [
            ['parent' => 'public.users', 'fk_column' => 'user_id', 'parent_column' => 'id'],
        ]);
        $dumpConfig = new DumpConfig(
            [],
            ['public' => [
                'users' => [
                    TableConfig::KEY_WHERE => 'is_active = 1',
                ],
            ]]
        );
        $result = $resolver->resolve($config, $dumpConfig);
        $this->assertNotNull($result);
        // Без LIMIT не оборачивается
        $this->assertStringContainsString('OR user_id IS NULL)', $result);
        $this->assertStringNotContainsString('_cascade_', $result);
        $this->assertStringNotContainsString('SELECT * FROM', $result);
    }

    public function testPostgresNoWrapWithLimit(): void
    {
        $config = new TableConfig('public', 'orders', 500, null, null, null, [
            ['parent' => 'public.users', 'fk_column' => 'user_id', 'parent_column' => 'id'],
        ]);
        $dumpConfig = new DumpConfig(
            [],
            ['public' => [
                'users' => [
                    TableConfig::KEY_LIMIT => 100,
                ],
            ]]
        );
        $result = $this->resolver->resolve($config, $dumpConfig);
        $this->assertNotNull($result);
        // PG не оборачивает
        $this->assertStringContainsString('OR user_id IS NULL)', $result);
        $this->assertStringNotContainsString('_cascade_', $result);
        $this->assertStringNotContainsString('SELECT * FROM', $result);
        $this->assertStringContainsString('LIMIT 100', $result);
    }

    public function testResolveIncludesOrIsNullForNullableFk(): void
    {
        $config = new TableConfig('public', 'orders', null, null, null, null, [
            ['parent' => 'public.users', 'fk_column' => 'user_id', 'parent_column' => 'id'],
        ]);
        $dumpConfig = new DumpConfig(
            [],
            ['public' => [
                'users' => [
                    TableConfig::KEY_WHERE => 'is_active = true',
                ],
            ]]
        );
        $result = $this->resolver->resolve($config, $dumpConfig);
        $this->assertNotNull($result);

        // Полная проверка формата: (fk IN (...) OR fk IS NULL)
        $this->assertEquals(
            '(user_id IN (SELECT "id" FROM "public"."users" WHERE is_active = true) OR user_id IS NULL)',
            $result
        );
    }
}
