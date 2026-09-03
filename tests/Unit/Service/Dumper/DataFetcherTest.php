<?php

namespace Timbrs\DatabaseDumps\Tests\Unit\Service\Dumper;

use PHPUnit\Framework\TestCase;
use Timbrs\DatabaseDumps\Config\DumpConfig;
use Timbrs\DatabaseDumps\Config\TableConfig;
use Timbrs\DatabaseDumps\Contract\ConnectionRegistryInterface;
use Timbrs\DatabaseDumps\Contract\DatabaseConnectionInterface;
use Timbrs\DatabaseDumps\Platform\OraclePlatform;
use Timbrs\DatabaseDumps\Platform\PostgresPlatform;
use Timbrs\DatabaseDumps\Service\Dumper\CascadeWhereResolver;
use Timbrs\DatabaseDumps\Service\Dumper\DataFetcher;
use Timbrs\DatabaseDumps\Service\Dumper\SampleQueryBuilder;

class DataFetcherTest extends TestCase
{
    /** @var DatabaseConnectionInterface&\PHPUnit\Framework\MockObject\MockObject */
    private $connection;

    /** @var CascadeWhereResolver&\PHPUnit\Framework\MockObject\MockObject */
    private $cascadeResolver;

    /** @var DataFetcher */
    private $fetcher;

    protected function setUp(): void
    {
        $this->connection = $this->createMock(DatabaseConnectionInterface::class);
        $platform = new PostgresPlatform();

        $registry = $this->createMock(ConnectionRegistryInterface::class);
        $registry->method('getConnection')->willReturn($this->connection);
        $registry->method('getPlatform')->willReturn($platform);

        $this->cascadeResolver = $this->createMock(CascadeWhereResolver::class);
        $this->cascadeResolver->method('resolve')->willReturn(null);

        $dumpConfig = new DumpConfig([], []);

        $this->fetcher = new DataFetcher($registry, $this->cascadeResolver, $dumpConfig);
    }

    public function testFetchFullExport(): void
    {
        $config = new TableConfig('users', 'users');

        $this->connection
            ->expects($this->once())
            ->method('fetchAllAssociative')
            ->with($this->stringContains('SELECT * FROM "users"."users"'))
            ->willReturn([
                ['id' => 1, 'name' => 'User 1'],
                ['id' => 2, 'name' => 'User 2']
            ]);

        $rows = $this->fetcher->fetch($config);

        $this->assertCount(2, $rows);
        $this->assertEquals('User 1', $rows[0]['name']);
    }

    public function testFetchWithLimit(): void
    {
        $config = new TableConfig('clients', 'clients', 100);

        $this->connection
            ->expects($this->once())
            ->method('fetchAllAssociative')
            ->with($this->stringContains('LIMIT 100'))
            ->willReturn([]);

        $this->fetcher->fetch($config);
    }

    public function testFetchWithWhere(): void
    {
        $config = new TableConfig('clients', 'clients', null, 'is_active = true');

        $this->connection
            ->expects($this->once())
            ->method('fetchAllAssociative')
            ->with($this->stringContains('WHERE is_active = true'))
            ->willReturn([]);

        $this->fetcher->fetch($config);
    }

    public function testFetchWithOrderBy(): void
    {
        $config = new TableConfig('clients', 'clients', null, null, 'created_at DESC');

        $this->connection
            ->expects($this->once())
            ->method('fetchAllAssociative')
            ->with($this->stringContains('ORDER BY created_at DESC'))
            ->willReturn([]);

        $this->fetcher->fetch($config);
    }

    public function testFetchWithAllOptions(): void
    {
        $config = new TableConfig(
            'clients',
            'clients',
            100,
            'is_active = true',
            'created_at DESC'
        );

        $this->connection
            ->expects($this->once())
            ->method('fetchAllAssociative')
            ->with($this->logicalAnd(
                $this->stringContains('WHERE is_active = true'),
                $this->stringContains('ORDER BY created_at DESC'),
                $this->stringContains('LIMIT 100')
            ))
            ->willReturn([]);

        $this->fetcher->fetch($config);
    }

    public function testFetchWithCascadeWhere(): void
    {
        $cascadeFrom = [
            ['parent' => 'public.users', 'fk_column' => 'user_id', 'parent_column' => 'id'],
        ];
        $config = new TableConfig('public', 'orders', null, null, null, null, $cascadeFrom);

        $cascadeWhereValue = 'user_id IN (SELECT "id" FROM "public"."users" WHERE active=true)';

        $cascadeResolver = $this->createMock(CascadeWhereResolver::class);
        $cascadeResolver->method('resolve')->willReturn($cascadeWhereValue);

        $registry = $this->createMock(ConnectionRegistryInterface::class);
        $registry->method('getConnection')->willReturn($this->connection);
        $registry->method('getPlatform')->willReturn(new PostgresPlatform());

        $dumpConfig = new DumpConfig([], []);

        $fetcher = new DataFetcher($registry, $cascadeResolver, $dumpConfig);

        $this->connection
            ->expects($this->once())
            ->method('fetchAllAssociative')
            ->with($this->stringContains($cascadeWhereValue))
            ->willReturn([]);

        $fetcher->fetch($config);
    }

    public function testFetchWithCascadeWhereAndExistingWhere(): void
    {
        $cascadeFrom = [
            ['parent' => 'public.users', 'fk_column' => 'user_id', 'parent_column' => 'id'],
        ];
        $config = new TableConfig('public', 'orders', null, 'status = 1', null, null, $cascadeFrom);

        $cascadeWhereValue = 'user_id IN (SELECT "id" FROM "public"."users" WHERE active=true)';

        $cascadeResolver = $this->createMock(CascadeWhereResolver::class);
        $cascadeResolver->method('resolve')->willReturn($cascadeWhereValue);

        $registry = $this->createMock(ConnectionRegistryInterface::class);
        $registry->method('getConnection')->willReturn($this->connection);
        $registry->method('getPlatform')->willReturn(new PostgresPlatform());

        $dumpConfig = new DumpConfig([], []);

        $fetcher = new DataFetcher($registry, $cascadeResolver, $dumpConfig);

        $this->connection
            ->expects($this->once())
            ->method('fetchAllAssociative')
            ->with($this->logicalAnd(
                $this->stringContains('WHERE (status = 1) AND (' . $cascadeWhereValue . ')'),
                $this->stringContains('SELECT * FROM "public"."orders"')
            ))
            ->willReturn([]);

        $fetcher->fetch($config);
    }

    public function testFetchWithLimitOracle(): void
    {
        $connection = $this->createMock(DatabaseConnectionInterface::class);

        $platform = new OraclePlatform();

        $registry = $this->createMock(ConnectionRegistryInterface::class);
        $registry->method('getConnection')->willReturn($connection);
        $registry->method('getPlatform')->willReturn($platform);

        $cascadeResolver = $this->createMock(CascadeWhereResolver::class);
        $cascadeResolver->method('resolve')->willReturn(null);

        $dumpConfig = new DumpConfig([], []);
        $fetcher = new DataFetcher($registry, $cascadeResolver, $dumpConfig);

        $config = new TableConfig('clients', 'clients', 100);

        $connection
            ->expects($this->once())
            ->method('fetchAllAssociative')
            ->with($this->stringContains('FETCH FIRST 100 ROWS ONLY'))
            ->willReturn([]);

        $fetcher->fetch($config);
    }

    public function testGetLastQueryReturnsBuiltSql(): void
    {
        $config = new TableConfig(
            'clients',
            'clients',
            100,
            'is_active = true',
            'created_at DESC'
        );

        $this->connection->method('fetchAllAssociative')->willReturn([]);

        $this->fetcher->fetch($config);

        $lastQuery = $this->fetcher->getLastQuery();
        $this->assertNotNull($lastQuery);
        $this->assertStringContainsString('SELECT * FROM "clients"."clients"', $lastQuery);
        $this->assertStringContainsString('WHERE is_active = true', $lastQuery);
        $this->assertStringContainsString('ORDER BY created_at DESC', $lastQuery);
        $this->assertStringContainsString('LIMIT 100', $lastQuery);
    }

    public function testGetLastQueryReturnsNullBeforeFetch(): void
    {
        $this->assertNull($this->fetcher->getLastQuery());
    }

    public function testFetchDelegatesToSampleQueryBuilderWhenSamplePresent(): void
    {
        $sample = [
            TableConfig::SAMPLE_KEY_CRITERIA => [
                ['name' => 'red', 'where' => "status = 'red'", 'limit' => 10],
            ],
        ];
        $config = new TableConfig('public', 'clients', null, null, null, null, null, null, $sample);

        $sampleBuilder = $this->createMock(SampleQueryBuilder::class);
        $sampleBuilder
            ->expects($this->once())
            ->method('build')
            ->with($config)
            ->willReturn('SELECT * FROM "public"."clients" WHERE "id" IN (\'1\', \'2\')');

        $dumpConfig = new DumpConfig([], []);
        $fetcher = new DataFetcher($this->registryWithPostgres(), $this->cascadeResolver, $dumpConfig, $sampleBuilder);

        $this->connection
            ->expects($this->once())
            ->method('fetchAllAssociative')
            ->with($this->stringContains('WHERE "id" IN (\'1\', \'2\')'))
            ->willReturn([]);

        $fetcher->fetch($config);
    }

    public function testIterateDelegatesToSampleQueryBuilder(): void
    {
        $sample = [
            TableConfig::SAMPLE_KEY_CRITERIA => [
                ['name' => 'red', 'where' => "status = 'red'", 'limit' => 10],
            ],
        ];
        $config = new TableConfig('public', 'clients', null, null, null, null, null, null, $sample);

        $sampleBuilder = $this->createMock(SampleQueryBuilder::class);
        $sampleBuilder
            ->expects($this->once())
            ->method('build')
            ->with($config)
            ->willReturn('SELECT * FROM "public"."clients" WHERE "id" IN (\'1\')');

        $dumpConfig = new DumpConfig([], []);
        $fetcher = new DataFetcher($this->registryWithPostgres(), $this->cascadeResolver, $dumpConfig, $sampleBuilder);

        $this->connection
            ->expects($this->once())
            ->method('iterateAssociative')
            ->with($this->stringContains('WHERE "id" IN (\'1\')'))
            ->willReturnCallback(function () {
                yield ['id' => 1];
            });

        $rows = iterator_to_array($fetcher->iterate($config));
        $this->assertCount(1, $rows);
        $this->assertSame('SELECT * FROM "public"."clients" WHERE "id" IN (\'1\')', $fetcher->getLastQuery());
    }

    public function testSampleReceivesCascadeWhereAndReferencedColumns(): void
    {
        // sample и cascade_from совместимы: каскад вычисляется до выборки по критериям и
        // уходит в базовое условие корзин; колонки, на которые ссылаются дети, — в фазу 1.
        $sample = [
            TableConfig::SAMPLE_KEY_CRITERIA => [
                ['name' => 'red', 'where' => "status = 'red'", 'limit' => 10],
            ],
        ];
        $cascadeFrom = [
            ['parent' => 'public.users', 'fk_column' => 'user_id', 'parent_column' => 'id'],
        ];
        $config = new TableConfig('public', 'clients', null, null, null, null, $cascadeFrom, null, $sample);

        $cascadeResolver = $this->createMock(CascadeWhereResolver::class);
        $cascadeResolver->expects($this->once())->method('resolve')->willReturn('("user_id" IN (\'7\') OR "user_id" IS NULL)');

        $sampleBuilder = $this->createMock(SampleQueryBuilder::class);
        $sampleBuilder->expects($this->once())
            ->method('build')
            ->with($config, '("user_id" IN (\'7\') OR "user_id" IS NULL)', ['core_id'], 25)
            ->willReturn('SELECT * FROM "public"."clients" WHERE "id" IN (\'1\')');

        $dumpConfig = new DumpConfig([], [
            'public' => [
                'clients' => ['limit' => 100],
                'orders' => [
                    'limit' => 100,
                    'cascade_from' => [['parent' => 'public.clients', 'fk_column' => 'client_id', 'parent_column' => 'core_id']],
                ],
            ],
        ], [], null, ['sample' => ['per_value' => 25]]);
        $fetcher = new DataFetcher($this->registryWithPostgres(), $cascadeResolver, $dumpConfig, $sampleBuilder);

        $this->assertSame(['core_id'], $fetcher->referencedColumns($config));

        $this->connection->method('fetchAllAssociative')->willReturn([]);
        $fetcher->fetch($config);
    }

    public function testFetchWithSampleButNoBuilderThrows(): void
    {
        $sample = [
            TableConfig::SAMPLE_KEY_CRITERIA => [
                ['name' => 'red', 'where' => "status = 'red'", 'limit' => 10],
            ],
        ];
        $config = new TableConfig('public', 'clients', null, null, null, null, null, null, $sample);

        $this->expectException(\RuntimeException::class);
        $this->fetcher->fetch($config);
    }

    private function registryWithPostgres(): ConnectionRegistryInterface
    {
        $registry = $this->createMock(ConnectionRegistryInterface::class);
        $registry->method('getConnection')->willReturn($this->connection);
        $registry->method('getPlatform')->willReturn(new PostgresPlatform());
        return $registry;
    }

    public function testFetchWithCascadeFromReturningNull(): void
    {
        $cascadeFrom = [
            ['parent' => 'public.users', 'fk_column' => 'user_id', 'parent_column' => 'id'],
        ];
        $config = new TableConfig('public', 'orders', null, 'status = 1', null, null, $cascadeFrom);

        // cascadeResolver returns null (parent is full export)
        $cascadeResolver = $this->createMock(CascadeWhereResolver::class);
        $cascadeResolver->method('resolve')->willReturn(null);

        $registry = $this->createMock(ConnectionRegistryInterface::class);
        $registry->method('getConnection')->willReturn($this->connection);
        $registry->method('getPlatform')->willReturn(new PostgresPlatform());

        $dumpConfig = new DumpConfig([], []);

        $fetcher = new DataFetcher($registry, $cascadeResolver, $dumpConfig);

        $this->connection
            ->expects($this->once())
            ->method('fetchAllAssociative')
            ->with($this->logicalAnd(
                $this->stringContains('WHERE status = 1'),
                $this->logicalNot($this->stringContains('AND'))
            ))
            ->willReturn([]);

        $fetcher->fetch($config);
    }
}
