<?php

namespace Timbrs\DatabaseDumps\Tests\Unit\Service\Dumper;

use PHPUnit\Framework\TestCase;
use Timbrs\DatabaseDumps\Config\DumpConfig;
use Timbrs\DatabaseDumps\Config\TableConfig;
use Timbrs\DatabaseDumps\Contract\ConnectionRegistryInterface;
use Timbrs\DatabaseDumps\Contract\DatabaseConnectionInterface;
use Timbrs\DatabaseDumps\Contract\LoggerInterface;
use Timbrs\DatabaseDumps\Platform\MySqlPlatform;
use Timbrs\DatabaseDumps\Platform\OraclePlatform;
use Timbrs\DatabaseDumps\Platform\PostgresPlatform;
use Timbrs\DatabaseDumps\Service\Dumper\CascadeWhereResolver;
use Timbrs\DatabaseDumps\Service\Dumper\SelectedPkRegistry;

/**
 * fk_column ТЕПЕРЬ квотируется через platform.quoteIdentifier (защита от SQL-инъекций
 * через имена колонок в YAML cascade_from). Поэтому в результате видим "user_id"
 * (PG), `user_id` (MySQL), "USER_ID" (Oracle).
 */
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
        $dumpConfig = new DumpConfig(['public' => ['users']], []);
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
        $this->assertStringContainsString('("user_id" IN (SELECT "id" FROM "public"."users"', $result);
        $this->assertStringContainsString('OR "user_id" IS NULL)', $result);
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
        $this->assertStringContainsString('("user_id" IN (', $result);
        $this->assertStringContainsString('OR "user_id" IS NULL)', $result);
        $this->assertStringContainsString('("order_id" IN (', $result);
        $this->assertStringContainsString('OR "order_id" IS NULL)', $result);
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
        $this->assertStringContainsString('("order_id" IN (SELECT "id" FROM "public"."orders"', $result);
        $this->assertStringContainsString('OR "order_id" IS NULL)', $result);
        $this->assertStringContainsString('("user_id" IN (SELECT "id" FROM "public"."users"', $result);
        $this->assertStringContainsString('OR "user_id" IS NULL)', $result);
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
        $this->assertStringContainsString('("USER_ID" IN (', $result);
        $this->assertStringContainsString('OR "USER_ID" IS NULL)', $result);
        $this->assertStringContainsString('FETCH FIRST 100 ROWS ONLY', $result);
        $this->assertStringNotContainsString(' LIMIT ', $result);
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
        $this->assertStringContainsString('(`user_id` IN (SELECT * FROM (SELECT `id` FROM `public`.`users`', $result);
        $this->assertStringContainsString('OR `user_id` IS NULL)', $result);
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
        $this->assertStringContainsString('OR `user_id` IS NULL)', $result);
        $this->assertStringContainsString('OR `order_id` IS NULL)', $result);
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
                'users' => [TableConfig::KEY_WHERE => 'is_active = 1'],
            ]]
        );
        $result = $resolver->resolve($config, $dumpConfig);
        $this->assertNotNull($result);
        $this->assertStringContainsString('OR `user_id` IS NULL)', $result);
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
                'users' => [TableConfig::KEY_LIMIT => 100],
            ]]
        );
        $result = $this->resolver->resolve($config, $dumpConfig);
        $this->assertNotNull($result);
        $this->assertStringContainsString('OR "user_id" IS NULL)', $result);
        $this->assertStringNotContainsString('_cascade_', $result);
        $this->assertStringNotContainsString('SELECT * FROM (SELECT', $result);
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
                'users' => [TableConfig::KEY_WHERE => 'is_active = true'],
            ]]
        );
        $result = $this->resolver->resolve($config, $dumpConfig);
        $this->assertNotNull($result);

        $this->assertSame(
            '("user_id" IN (SELECT "id" FROM "public"."users" WHERE is_active = true) OR "user_id" IS NULL)',
            $result
        );
    }

    public function testSampleParentUsesSelectedIdSetInsteadOfSubquery(): void
    {
        $connection = $this->createMock(DatabaseConnectionInterface::class);
        $connection->method('getPlatformName')->willReturn('postgresql');
        $connection->method('quote')->willReturnCallback(function ($v) {
            return "'" . $v . "'";
        });

        $registry = $this->createMock(ConnectionRegistryInterface::class);
        $registry->method('getPlatform')->willReturn(new PostgresPlatform());
        $registry->method('getConnection')->willReturn($connection);

        $selectedPk = new SelectedPkRegistry();
        $selectedPk->record('public', 'users', ['id' => [10, 20, 30]]);

        $resolver = new CascadeWhereResolver($registry, 10, null, $selectedPk);

        $config = new TableConfig('public', 'orders', null, null, null, null, [
            ['parent' => 'public.users', 'fk_column' => 'user_id', 'parent_column' => 'id'],
        ]);
        $dumpConfig = new DumpConfig(
            [],
            ['public' => [
                'users' => [
                    TableConfig::KEY_SAMPLE => [
                        TableConfig::SAMPLE_KEY_CRITERIA => [
                            ['name' => 'vip', 'where' => "tier = 'vip'", 'limit' => 30],
                        ],
                    ],
                ],
            ]]
        );

        $result = $resolver->resolve($config, $dumpConfig);
        $this->assertSame(
            '("user_id" IN (\'10\', \'20\', \'30\') OR "user_id" IS NULL)',
            $result
        );
        // Никакого подзапроса — ссылаемся на конкретные id.
        $this->assertStringNotContainsString('SELECT', $result);
    }

    public function testSampleParentWithEmptySelectionYieldsNoMatch(): void
    {
        $connection = $this->createMock(DatabaseConnectionInterface::class);
        $connection->method('getPlatformName')->willReturn('postgresql');

        $registry = $this->createMock(ConnectionRegistryInterface::class);
        $registry->method('getPlatform')->willReturn(new PostgresPlatform());
        $registry->method('getConnection')->willReturn($connection);

        $selectedPk = new SelectedPkRegistry();
        $selectedPk->record('public', 'users', ['id' => []]);

        $resolver = new CascadeWhereResolver($registry, 10, null, $selectedPk);

        $config = new TableConfig('public', 'orders', null, null, null, null, [
            ['parent' => 'public.users', 'fk_column' => 'user_id', 'parent_column' => 'id'],
        ]);
        $dumpConfig = new DumpConfig(
            [],
            ['public' => [
                'users' => [
                    TableConfig::KEY_SAMPLE => [
                        TableConfig::SAMPLE_KEY_STRATIFY_BY => 'tier',
                    ],
                ],
            ]]
        );

        $result = $resolver->resolve($config, $dumpConfig);
        $this->assertSame('("user_id" IN (NULL) OR "user_id" IS NULL)', $result);
    }

    public function testSampleParentWithoutRegistryFallsBackToSubqueryAndWarns(): void
    {
        $connection = $this->createMock(DatabaseConnectionInterface::class);
        $connection->method('getPlatformName')->willReturn('postgresql');

        $registry = $this->createMock(ConnectionRegistryInterface::class);
        $registry->method('getPlatform')->willReturn(new PostgresPlatform());
        $registry->method('getConnection')->willReturn($connection);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('warning')
            ->with($this->stringContains('откат к подзапросу'));

        // Реестр НЕ передан (null) — должен сработать fallback на подзапрос + warning.
        $resolver = new CascadeWhereResolver($registry, 10, $logger, null);

        $config = new TableConfig('public', 'orders', null, null, null, null, [
            ['parent' => 'public.users', 'fk_column' => 'user_id', 'parent_column' => 'id'],
        ]);
        $dumpConfig = new DumpConfig(
            [],
            ['public' => [
                'users' => [
                    TableConfig::KEY_SAMPLE => [
                        TableConfig::SAMPLE_KEY_CRITERIA => [
                            ['name' => 'vip', 'where' => "tier = 'vip'", 'limit' => 30],
                        ],
                    ],
                ],
            ]]
        );

        $result = $resolver->resolve($config, $dumpConfig);
        $this->assertNotNull($result);
        // Откат к подзапросу: видим SELECT по родителю.
        $this->assertStringContainsString('("user_id" IN (SELECT "id" FROM "public"."users"', $result);
    }

    public function testSampleParentColumnNotRecordedFallsBackToSubquery(): void
    {
        $connection = $this->createMock(DatabaseConnectionInterface::class);
        $connection->method('getPlatformName')->willReturn('postgresql');

        $registry = $this->createMock(ConnectionRegistryInterface::class);
        $registry->method('getPlatform')->willReturn(new PostgresPlatform());
        $registry->method('getConnection')->willReturn($connection);

        // Реестр есть, но parent_column='id' не зарегистрирован (записан другой столбец).
        $selectedPk = new SelectedPkRegistry();
        $selectedPk->record('public', 'users', ['uuid' => ['a', 'b']]);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning');

        $resolver = new CascadeWhereResolver($registry, 10, $logger, $selectedPk);

        $config = new TableConfig('public', 'orders', null, null, null, null, [
            ['parent' => 'public.users', 'fk_column' => 'user_id', 'parent_column' => 'id'],
        ]);
        $dumpConfig = new DumpConfig(
            [],
            ['public' => [
                'users' => [
                    TableConfig::KEY_SAMPLE => [
                        TableConfig::SAMPLE_KEY_STRATIFY_BY => 'tier',
                    ],
                ],
            ]]
        );

        $result = $resolver->resolve($config, $dumpConfig);
        $this->assertNotNull($result);
        $this->assertStringContainsString('IN (SELECT "id" FROM "public"."users"', $result);
    }

    public function testSampleParentIdSetEscapesValues(): void
    {
        $connection = $this->createMock(DatabaseConnectionInterface::class);
        $connection->method('getPlatformName')->willReturn('postgresql');
        // quote() оборачивает в кавычки и удваивает внутренние — проверяем экранирование.
        $connection->method('quote')->willReturnCallback(function ($v) {
            return "'" . str_replace("'", "''", (string) $v) . "'";
        });

        $registry = $this->createMock(ConnectionRegistryInterface::class);
        $registry->method('getPlatform')->willReturn(new PostgresPlatform());
        $registry->method('getConnection')->willReturn($connection);

        $selectedPk = new SelectedPkRegistry();
        $selectedPk->record('public', 'users', ['id' => ["O'Brien", 'x']]);

        $resolver = new CascadeWhereResolver($registry, 10, null, $selectedPk);

        $config = new TableConfig('public', 'orders', null, null, null, null, [
            ['parent' => 'public.users', 'fk_column' => 'user_id', 'parent_column' => 'id'],
        ]);
        $dumpConfig = new DumpConfig(
            [],
            ['public' => [
                'users' => [
                    TableConfig::KEY_SAMPLE => [
                        TableConfig::SAMPLE_KEY_CRITERIA => [
                            ['name' => 'x', 'where' => 'id > 0', 'limit' => 5],
                        ],
                    ],
                ],
            ]]
        );

        $result = $resolver->resolve($config, $dumpConfig);
        $this->assertNotNull($result);
        // Одинарная кавычка в значении удвоена — инъекция невозможна.
        $this->assertStringContainsString("IN ('O''Brien', 'x')", $result);
    }
}
