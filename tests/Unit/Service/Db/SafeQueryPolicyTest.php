<?php

namespace Timbrs\DatabaseDumps\Tests\Unit\Service\Db;

use PHPUnit\Framework\TestCase;
use Timbrs\DatabaseDumps\Service\Db\SafeQueryPolicy;

class SafeQueryPolicyTest extends TestCase
{
    public function testDefaultsAreTheDocumentedOnes(): void
    {
        $policy = SafeQueryPolicy::defaults();

        self::assertSame(SafeQueryPolicy::PROFILE_ANALYZE, $policy->getProfile());
        self::assertSame(15000, $policy->getAnalyzeStatementTimeoutMs());
        self::assertSame(1800000, $policy->getExportStatementTimeoutMs());
        self::assertSame(2000, $policy->getLockTimeoutMs());
        self::assertSame(60000, $policy->getIdleInTransactionTimeoutMs());
        self::assertSame(2000, $policy->getQueryBudget());
        self::assertSame(50000, $policy->getMaxScanRows());
    }

    public function testSettingsFromYamlOrEnvAreCastToInt(): void
    {
        // Строка из env не должна попасть в SET как есть.
        $policy = new SafeQueryPolicy([
            'analyze_statement_timeout' => '5000',
            'query_budget' => '10',
            'max_scan_rows' => 100,
        ]);

        self::assertSame(5000, $policy->getAnalyzeStatementTimeoutMs());
        self::assertSame(10, $policy->getQueryBudget());
        self::assertSame(100, $policy->getMaxScanRows());
        self::assertSame(1800000, $policy->getExportStatementTimeoutMs(), 'незаданный ключ — дефолт');
    }

    public function testNonNumericSettingIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new SafeQueryPolicy(['lock_timeout' => '2 seconds']);
    }

    public function testNegativeSettingIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new SafeQueryPolicy(['query_budget' => -1]);
    }

    public function testUnknownProfileIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        SafeQueryPolicy::defaults()->setProfile('turbo');
    }

    public function testPostgresStatementsFollowTheProfile(): void
    {
        $policy = SafeQueryPolicy::defaults();

        self::assertSame(
            [
                ['SET statement_timeout = 15000'],
                ['SET lock_timeout = 2000'],
                ['SET idle_in_transaction_session_timeout = 60000'],
            ],
            $policy->sessionStatements('postgresql')
        );

        self::assertSame(
            ['SET statement_timeout = 1800000'],
            $policy->sessionStatements('pgsql', SafeQueryPolicy::PROFILE_EXPORT)[0]
        );

        // Импорт: без ограничений — TRUNCATE и INSERT ждут столько, сколько надо.
        self::assertSame(
            [
                ['SET statement_timeout = 0'],
                ['SET lock_timeout = 0'],
                ['SET idle_in_transaction_session_timeout = 0'],
            ],
            $policy->sessionStatements('postgresql', SafeQueryPolicy::PROFILE_IMPORT)
        );
    }

    public function testMysqlOffersMariaDbAlternativeForTheSameLimit(): void
    {
        $statements = SafeQueryPolicy::defaults()->sessionStatements('mysql');

        self::assertSame(
            ['SET SESSION max_execution_time = 15000', 'SET SESSION max_statement_time = 15'],
            $statements[0]
        );
        self::assertSame(['SET SESSION innodb_lock_wait_timeout = 2'], $statements[1]);
    }

    public function testOracleHasNoSessionStatements(): void
    {
        self::assertSame([], SafeQueryPolicy::defaults()->sessionStatements('oracle'));
    }

    public function testScanIsAllowedOnlyForKnownSmallTables(): void
    {
        $policy = new SafeQueryPolicy(['max_scan_rows' => 1000]);

        self::assertTrue($policy->allowsScan(1000));
        self::assertFalse($policy->allowsScan(1001));
        self::assertFalse($policy->allowsScan(null), 'неизвестный размер — не сканируем');
    }

    public function testQueryBudgetCountsOnlyInAnalyzeProfile(): void
    {
        $policy = new SafeQueryPolicy(['query_budget' => 3]);

        self::assertTrue($policy->allowsQuery(3));
        self::assertFalse($policy->allowsQuery(4));

        $policy->setProfile(SafeQueryPolicy::PROFILE_EXPORT);
        self::assertTrue($policy->allowsQuery(4));

        $unlimited = new SafeQueryPolicy(['query_budget' => 0]);
        self::assertTrue($unlimited->allowsQuery(1000000));
    }
}
