<?php

namespace Timbrs\DatabaseDumps\Tests\Unit\Service\Db;

use PHPUnit\Framework\TestCase;
use Timbrs\DatabaseDumps\Contract\ConnectionRegistryInterface;
use Timbrs\DatabaseDumps\Contract\DatabaseConnectionInterface;
use Timbrs\DatabaseDumps\Service\Db\PgStatsReader;
use Timbrs\DatabaseDumps\Service\Db\RowEstimate;

class PgStatsReaderTest extends TestCase
{
    /** @var DatabaseConnectionInterface&\PHPUnit\Framework\MockObject\MockObject */
    private $connection;

    /** @var PgStatsReader */
    private $reader;

    protected function setUp(): void
    {
        $this->connection = $this->createMock(DatabaseConnectionInterface::class);
        $this->connection->method('getPlatformName')->willReturn('postgresql');
        $registry = $this->createMock(ConnectionRegistryInterface::class);
        $registry->method('getConnection')->willReturn($this->connection);
        $this->reader = new PgStatsReader($registry);
    }

    public function testTableStatsAreReadInOneBatchAndCached(): void
    {
        $this->connection->expects($this->once())
            ->method('fetchAllAssociative')
            ->with($this->logicalAnd(
                $this->stringContains('pg_class'),
                $this->stringContains('pg_stat_user_tables'),
                $this->stringContains('has_table_privilege')
            ))
            ->willReturn([
                [
                    'table_schema' => 'public', 'table_name' => 'users', 'reltuples' => '1234.5', 'relpages' => '10',
                    'n_live_tup' => '1200', 'last_analyze' => '2026-01-01 10:00:00', 'last_autoanalyze' => null, 'can_select' => 't',
                ],
                [
                    'TABLE_SCHEMA' => 'public', 'TABLE_NAME' => 'fresh', 'RELTUPLES' => '-1', 'RELPAGES' => '0',
                    'N_LIVE_TUP' => null, 'LAST_ANALYZE' => null, 'LAST_AUTOANALYZE' => null, 'CAN_SELECT' => false,
                ],
            ]);

        $first = $this->reader->readTableStats();
        $second = $this->reader->readTableStats();

        self::assertSame($first, $second, 'второй вызов — из кэша, без запроса');
        self::assertSame(1234.5, $first['public.users']['reltuples']);
        self::assertSame(1200, $first['public.users']['n_live_tup']);
        self::assertTrue($first['public.users']['can_select']);
        self::assertSame(-1.0, $first['public.fresh']['reltuples']);
        self::assertNull($first['public.fresh']['n_live_tup']);
        self::assertFalse($first['public.fresh']['can_select']);
    }

    public function testColumnStatsParseArrayLiterals(): void
    {
        $this->connection->expects($this->once())
            ->method('fetchAllAssociative')
            ->with($this->stringContains('FROM pg_stats'), ['schema' => 'public'])
            ->willReturn([
                [
                    'tablename' => 'clients', 'attname' => 'status_id', 'null_frac' => '0.1', 'n_distinct' => '3',
                    'avg_width' => '4', 'mcv' => '{1,2,-4}', 'mcf' => '{0.5,0.3,0.1}', 'hb' => null,
                ],
                [
                    'tablename' => 'clients', 'attname' => 'created_at', 'null_frac' => '0', 'n_distinct' => '-1',
                    'avg_width' => '8', 'mcv' => null, 'mcf' => null, 'hb' => '{"2020-01-01","2021-06-01"}',
                ],
            ]);

        $stats = $this->reader->readColumnStats('public');

        self::assertSame(['1', '2', '-4'], $stats['clients']['status_id']['most_common_vals']);
        self::assertSame([0.5, 0.3, 0.1], $stats['clients']['status_id']['most_common_freqs']);
        self::assertSame(3.0, $stats['clients']['status_id']['n_distinct']);
        self::assertSame(0.1, $stats['clients']['status_id']['null_frac']);
        self::assertNull($stats['clients']['created_at']['most_common_vals']);
        self::assertSame(['2020-01-01', '2021-06-01'], $stats['clients']['created_at']['histogram_bounds']);
    }

    public function testColumnPrivilegesComeFromPgAttribute(): void
    {
        $this->connection->expects($this->once())
            ->method('fetchAllAssociative')
            ->with($this->stringContains('has_column_privilege'), ['schema' => 'persons'])
            ->willReturn([
                ['table_name' => 'persons', 'column_name' => 'id', 'can_select' => true],
                ['table_name' => 'persons', 'column_name' => 'snils', 'can_select' => 'f'],
            ]);

        $privileges = $this->reader->readColumnPrivileges('persons');

        self::assertTrue($privileges['persons']['id']);
        self::assertFalse($privileges['persons']['snils']);
    }

    public function testRowEstimateRules(): void
    {
        $fromClass = PgStatsReader::estimateRows(['reltuples' => 1234.6, 'n_live_tup' => 5]);
        self::assertSame(1235, $fromClass->getValue());
        self::assertTrue($fromClass->isEstimated());
        self::assertSame(RowEstimate::SOURCE_PG_CLASS, $fromClass->getSource());

        // PG14+: -1 — таблица ни разу не анализировалась; сборщик статистики видел вставки.
        $fromStat = PgStatsReader::estimateRows(['reltuples' => -1.0, 'n_live_tup' => 500]);
        self::assertSame(500, $fromStat->getValue());
        self::assertSame(RowEstimate::SOURCE_PG_STAT, $fromStat->getSource());

        $unknown = PgStatsReader::estimateRows(['reltuples' => -1.0, 'n_live_tup' => 0]);
        self::assertFalse($unknown->isKnown());
        self::assertSame(RowEstimate::SOURCE_NONE, $unknown->getSource());

        $empty = PgStatsReader::estimateRows(['reltuples' => 0.0, 'n_live_tup' => 0]);
        self::assertSame(0, $empty->getValue());

        self::assertFalse(PgStatsReader::estimateRows(null)->isKnown());
    }

    public function testSupportsOnlyPostgres(): void
    {
        self::assertTrue($this->reader->supports());

        $mysql = $this->createMock(DatabaseConnectionInterface::class);
        $mysql->method('getPlatformName')->willReturn('mysql');
        $registry = $this->createMock(ConnectionRegistryInterface::class);
        $registry->method('getConnection')->willReturn($mysql);

        self::assertFalse((new PgStatsReader($registry))->supports());
    }
}
