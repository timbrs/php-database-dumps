<?php

namespace Timbrs\DatabaseDumps\Tests\Unit\Service\Check;

use PHPUnit\Framework\TestCase;
use Timbrs\DatabaseDumps\Config\DumpConfig;
use Timbrs\DatabaseDumps\Contract\ConfigLoaderInterface;
use Timbrs\DatabaseDumps\Contract\ConnectionRegistryInterface;
use Timbrs\DatabaseDumps\Contract\DatabaseConnectionInterface;
use Timbrs\DatabaseDumps\Contract\LoggerInterface;
use Timbrs\DatabaseDumps\Platform\PostgresPlatform;
use Timbrs\DatabaseDumps\Service\Ai\DbdumpConfigStore;
use Timbrs\DatabaseDumps\Service\Analysis\CriteriaSqlTester;
use Timbrs\DatabaseDumps\Service\Check\CheckRunner;
use Timbrs\DatabaseDumps\Service\Validation\AuditFixer;
use Timbrs\DatabaseDumps\Service\Validation\ConfigAuditor;
use Timbrs\DatabaseDumps\Service\Validation\Finding;
use Timbrs\DatabaseDumps\Service\Validation\FindingCatalog;
use Timbrs\DatabaseDumps\Service\Verification\DumpValueReader;
use Timbrs\DatabaseDumps\Service\Verification\DumpVerificationRunner;
use Timbrs\DatabaseDumps\Tests\Support\InMemoryFileSystem;

class CheckRunnerTest extends TestCase
{
    private const CONFIG_PATH = '/proj/docker/database/dump_config.yaml';
    private const INVENTORY_PATH = '/proj/docker/database/analysis/schema_inventory.json';

    /** @var InMemoryFileSystem */
    private $fileSystem;

    /** @var DatabaseConnectionInterface&\PHPUnit\Framework\MockObject\MockObject */
    private $connection;

    /** @var CriteriaSqlTester&\PHPUnit\Framework\MockObject\MockObject */
    private $sqlTester;

    /** @var array<int, string> */
    private $queries = [];

    protected function setUp(): void
    {
        $this->fileSystem = new InMemoryFileSystem([
            self::CONFIG_PATH => "partial_export:\n  public:\n    clients:\n      limit: 100\n",
            self::INVENTORY_PATH => (string) json_encode([
                'generated_at' => '2026-01-15T00:00:00Z',
                'database_platform' => 'postgresql',
                'warnings' => [
                    ['code' => 'P-1', 'message' => 'размер public.clients неизвестен — выполните ANALYZE', 'schema' => 'public', 'table' => 'clients'],
                    ['code' => 'ZZ-9', 'message' => 'код не из реестра — игнорируется', 'schema' => 'public', 'table' => 'clients'],
                ],
                'schemas' => ['public' => ['tables' => ['clients' => [
                    'row_count' => 100,
                    'columns' => [['name' => 'id', 'type' => 'bigint', 'nullable' => false]],
                    'foreign_keys' => [],
                    'profiles' => [],
                ]]]],
            ]),
        ]);

        $this->connection = $this->createMock(DatabaseConnectionInterface::class);
        $this->connection->method('getPlatformName')->willReturn('postgresql');
        $this->sqlTester = $this->createMock(CriteriaSqlTester::class);
    }

    public function testStaticStageCarriesCoverageAndFindings(): void
    {
        $report = $this->runner()->run([
            'config_path' => self::CONFIG_PATH,
            'inventory_path' => self::INVENTORY_PATH,
            'stages' => [FindingCatalog::STAGE_STATIC],
        ]);

        $payload = $report->toArray();
        self::assertTrue($payload['stages']['static']['ran']);
        self::assertNotNull($payload['coverage']);
        self::assertArrayNotHasKey('live', $payload['stages']);
        // Каждая находка помечена стадией из реестра — отчёт читается по одному пространству кодов.
        foreach ($payload['findings'] as $finding) {
            self::assertSame(FindingCatalog::stageOf($finding['code']), $finding['stage']);
        }
    }

    public function testLiveStageIsSkippedWhenDatabaseIsUnreachable(): void
    {
        $this->connection->method('fetchAllAssociative')->willThrowException(new \RuntimeException('could not connect to server'));

        $report = $this->runner()->run([
            'config_path' => self::CONFIG_PATH,
            'inventory_path' => self::INVENTORY_PATH,
            'stages' => [FindingCatalog::STAGE_LIVE],
        ]);

        $stage = $report->toArray()['stages']['live'];
        self::assertFalse($stage['ran']);
        self::assertStringContainsString('БД недоступна', $stage['why_skipped']);
    }

    public function testLiveStageReportsFailingTimeoutAndEmptyBuckets(): void
    {
        $this->fileSystem->write(self::CONFIG_PATH, $this->configWithCriteria());
        $this->connection->method('fetchAllAssociative')->willReturnCallback(function (string $sql): array {
            $this->queries[] = $sql;
            if (strpos($sql, 'SELECT 1') === 0) {
                return [['?column?' => 1]];
            }
            // Корзина «green» не ловит ни одной строки.
            return [['c' => strpos($sql, "'green'") !== false ? 0 : 3]];
        });
        $this->sqlTester->method('test')->willReturnCallback(function (string $schema, string $table, string $where) {
            if (strpos($where, 'broken') !== false) {
                return 'SQLSTATE[42703]: column "broken" does not exist';
            }
            if (strpos($where, 'slow') !== false) {
                return CriteriaSqlTester::TIMEOUT_PREFIX . 'statement timeout';
            }

            return null;
        });

        $report = $this->runner()->run([
            'config_path' => self::CONFIG_PATH,
            'inventory_path' => self::INVENTORY_PATH,
            'stages' => [FindingCatalog::STAGE_LIVE],
        ]);

        $codes = array_map(function (Finding $f) {
            return $f->getCode();
        }, $report->getFindings());
        sort($codes);
        // P-1 подхвачен из warnings слепка, ZZ-9 отброшен как не из реестра.
        self::assertSame(['P-1', 'Q-6', 'Q-7', 'Q-8'], $codes);
        self::assertSame(4, $report->toArray()['stages']['live']['criteria_tested']);
    }

    public function testPlanStageDescribesEveryTableWithoutDatabase(): void
    {
        $this->fileSystem->write(self::CONFIG_PATH, $this->configWithCriteria());

        $report = $this->runner()->run([
            'config_path' => self::CONFIG_PATH,
            'inventory_path' => self::INVENTORY_PATH,
            'stages' => [FindingCatalog::STAGE_PLAN],
        ]);

        $plan = $report->toArray()['stages']['plan'];
        self::assertTrue($plan['ran']);
        self::assertSame('public.clients', $plan['tables'][0]['table']);
        self::assertSame('partial', $plan['tables'][0]['mode']);
        self::assertCount(4, $plan['tables'][0]['criteria']);
        self::assertSame([], $this->queries, 'plan не должен трогать БД');
    }

    public function testDumpStageIsSkippedWithoutDumpsDirectory(): void
    {
        $report = $this->runner()->run([
            'config_path' => self::CONFIG_PATH,
            'inventory_path' => self::INVENTORY_PATH,
            'stages' => [FindingCatalog::STAGE_DUMP],
        ]);

        $stage = $report->toArray()['stages']['dump'];
        self::assertFalse($stage['ran']);
        self::assertStringContainsString('каталога дампов нет', $stage['why_skipped']);
    }

    public function testImportStageNeedsScratchConnection(): void
    {
        $report = $this->runner()->run([
            'config_path' => self::CONFIG_PATH,
            'inventory_path' => self::INVENTORY_PATH,
            'stages' => [FindingCatalog::STAGE_IMPORT],
        ]);

        $stage = $report->toArray()['stages']['import'];
        self::assertFalse($stage['ran']);
        self::assertStringContainsString('--import-connection', $stage['why_skipped']);
    }

    public function testTableFilterDropsFindingsOfOtherTables(): void
    {
        $this->fileSystem->write(
            self::CONFIG_PATH,
            "partial_export:\n  public:\n    clients:\n      limit: 100\n      order_by: nope DESC\n"
        );

        $all = $this->runner()->run([
            'config_path' => self::CONFIG_PATH,
            'inventory_path' => self::INVENTORY_PATH,
            'stages' => [FindingCatalog::STAGE_STATIC],
        ]);
        self::assertNotSame([], $all->getFindings());

        $filtered = $this->runner()->run([
            'config_path' => self::CONFIG_PATH,
            'inventory_path' => self::INVENTORY_PATH,
            'stages' => [FindingCatalog::STAGE_STATIC],
            'tables' => ['public.other'],
        ]);
        foreach ($filtered->getFindings() as $finding) {
            self::assertNotSame('clients', $finding->getTable());
        }
    }

    private function configWithCriteria(): string
    {
        return "partial_export:\n"
            . "  public:\n"
            . "    clients:\n"
            . "      limit: 100\n"
            . "      sample:\n"
            . "        criteria:\n"
            . "          - { name: red, where: \"status = 'red'\", limit: 10 }\n"
            . "          - { name: green, where: \"status = 'green'\", limit: 10 }\n"
            . "          - { name: bad, where: \"broken = 1\", limit: 10 }\n"
            . "          - { name: slowish, where: \"slow = 1\", limit: 10 }\n";
    }

    private function runner(): CheckRunner
    {
        $registry = $this->createMock(ConnectionRegistryInterface::class);
        $registry->method('getConnection')->willReturn($this->connection);
        $registry->method('getPlatform')->willReturn(new PostgresPlatform());

        $configLoader = $this->createMock(ConfigLoaderInterface::class);
        $configLoader->method('load')->willReturnCallback(function (string $path): DumpConfig {
            $parsed = \Symfony\Component\Yaml\Yaml::parse($this->fileSystem->read($path));

            return new DumpConfig(
                isset($parsed['full_export']) ? $parsed['full_export'] : [],
                isset($parsed['partial_export']) ? $parsed['partial_export'] : []
            );
        });

        $store = $this->createMock(DbdumpConfigStore::class);
        $store->method('getDataDir')->willReturn('docker/database');

        return new CheckRunner(
            new ConfigAuditor($this->fileSystem),
            new AuditFixer($this->fileSystem),
            $this->fileSystem,
            $configLoader,
            $registry,
            $this->sqlTester,
            new DumpVerificationRunner(new DumpValueReader(), []),
            $store,
            $this->createMock(LoggerInterface::class),
            '/proj'
        );
    }
}
