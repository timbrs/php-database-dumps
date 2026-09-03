<?php

namespace Timbrs\DatabaseDumps\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;
use Timbrs\DatabaseDumps\Adapter\PdoAdapter;
use Timbrs\DatabaseDumps\Config\DumpConfig;
use Timbrs\DatabaseDumps\Config\EnvironmentConfig;
use Timbrs\DatabaseDumps\Contract\LoggerInterface;
use Timbrs\DatabaseDumps\Service\Ai\DbdumpConfigStore;
use Timbrs\DatabaseDumps\Service\Analysis\AnalysisPackageBuilder;
use Timbrs\DatabaseDumps\Service\Analysis\ConfigEnricher;
use Timbrs\DatabaseDumps\Service\Analysis\CriteriaSqlTester;
use Timbrs\DatabaseDumps\Service\Analysis\Decision\DecisionEngine;
use Timbrs\DatabaseDumps\Service\Analysis\Dossier\DossierBuilder;
use Timbrs\DatabaseDumps\Service\Analysis\Dossier\MigrationScanner;
use Timbrs\DatabaseDumps\Service\Analysis\Dossier\ViewScanner;
use Timbrs\DatabaseDumps\Service\Analysis\CodeHintScanner;
use Timbrs\DatabaseDumps\Service\Check\CheckRunner;
use Timbrs\DatabaseDumps\Service\ConfigGenerator\ColumnStatisticsInspector;
use Timbrs\DatabaseDumps\Service\ConfigGenerator\ConfigSplitter;
use Timbrs\DatabaseDumps\Service\ConfigGenerator\ForeignKeyInspector;
use Timbrs\DatabaseDumps\Service\ConfigGenerator\PrimaryKeyInspector;
use Timbrs\DatabaseDumps\Service\ConfigGenerator\ServiceTableFilter;
use Timbrs\DatabaseDumps\Service\ConfigGenerator\TableInspector;
use Timbrs\DatabaseDumps\Service\ConnectionRegistry;
use Timbrs\DatabaseDumps\Service\Db\PgStatsReader;
use Timbrs\DatabaseDumps\Service\Db\RowCounter;
use Timbrs\DatabaseDumps\Service\Db\SafeQueryPolicy;
use Timbrs\DatabaseDumps\Service\Dumper\CascadeWhereResolver;
use Timbrs\DatabaseDumps\Service\Dumper\DatabaseDumper;
use Timbrs\DatabaseDumps\Service\Dumper\DataFetcher;
use Timbrs\DatabaseDumps\Service\Dumper\SampleQueryBuilder;
use Timbrs\DatabaseDumps\Service\Dumper\SampleReportCollector;
use Timbrs\DatabaseDumps\Service\Dumper\SelectedPkRegistry;
use Timbrs\DatabaseDumps\Service\Dumper\TableConfigResolver;
use Timbrs\DatabaseDumps\Service\Faker\RussianFaker;
use Timbrs\DatabaseDumps\Service\Generator\DeferredUpdateGenerator;
use Timbrs\DatabaseDumps\Service\Generator\InsertGenerator;
use Timbrs\DatabaseDumps\Service\Generator\SequenceGenerator;
use Timbrs\DatabaseDumps\Service\Generator\SqlGenerator;
use Timbrs\DatabaseDumps\Service\Generator\TruncateGenerator;
use Timbrs\DatabaseDumps\Service\Graph\TableDependencyResolver;
use Timbrs\DatabaseDumps\Service\Graph\TopologicalSorter;
use Timbrs\DatabaseDumps\Service\Security\ProductionGuard;
use Timbrs\DatabaseDumps\Service\Validation\AuditFixer;
use Timbrs\DatabaseDumps\Service\Validation\ConfigAuditor;
use Timbrs\DatabaseDumps\Service\Validation\Finding;
use Timbrs\DatabaseDumps\Service\Validation\FindingCatalog;
use Timbrs\DatabaseDumps\Service\Verification\CascadeClosureVerifier;
use Timbrs\DatabaseDumps\Service\Verification\DumpValueReader;
use Timbrs\DatabaseDumps\Service\Verification\DumpVerificationRunner;
use Timbrs\DatabaseDumps\Service\Verification\PiiLeakVerifier;
use Timbrs\DatabaseDumps\Service\Verification\RowCountVerifier;
use Timbrs\DatabaseDumps\Service\Verification\ValueCoverageVerifier;
use Timbrs\DatabaseDumps\Util\FileSystemHelper;
use Timbrs\DatabaseDumps\Util\YamlConfigLoader;

/**
 * Полный цикл на живой БД: сид → слепок → досье → решения → apply → check → export → check.
 *
 * Единственный тест, который доказывает, что звенья стыкуются: юнит-тесты проверяют каждое
 * по отдельности с моками, а расходятся они как раз на стыках — на форме слепка, на том, что
 * решение адресует таблицу так же, как её называет конфиг, на порядке выгрузки.
 *
 * Требует Postgres. Без `DBDUMP_TEST_DSN` тест пропускается — в CI закрытого контура базы нет:
 *   DBDUMP_TEST_DSN='pgsql:host=127.0.0.1;port=5432;dbname=app' \
 *   DBDUMP_TEST_USER=app DBDUMP_TEST_PASSWORD=app vendor/bin/phpunit tests/Integration
 *
 * Работает в своей схеме (`dbdump_it`), которую сам создаёт и сносит: чужие таблицы не трогает.
 */
class FullCycleTest extends TestCase
{
    private const SCHEMA = 'dbdump_it';

    /** @var \PDO|null */
    private $pdo;

    /** @var string Пустая строка до setUp: он может выйти по markTestSkipped раньше присвоения. */
    private $projectDir = '';

    /** @var ConnectionRegistry */
    private $registry;

    /** @var FileSystemHelper */
    private $fileSystem;

    protected function setUp(): void
    {
        $dsn = getenv('DBDUMP_TEST_DSN');
        if ($dsn === false || $dsn === '') {
            $this->markTestSkipped('Нет DBDUMP_TEST_DSN — полный цикл требует живой БД.');
        }
        if (strpos($dsn, 'pgsql') !== 0) {
            $this->markTestSkipped('Полный цикл рассчитан на Postgres: pg_stats и TABLESAMPLE.');
        }

        $user = getenv('DBDUMP_TEST_USER');
        $password = getenv('DBDUMP_TEST_PASSWORD');
        $this->pdo = new \PDO(
            $dsn,
            $user === false ? null : $user,
            $password === false ? null : $password,
            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
        );

        $this->projectDir = sys_get_temp_dir() . '/dbdump-it-' . bin2hex(random_bytes(4));
        $this->fileSystem = new FileSystemHelper();
        $this->fileSystem->createDirectory($this->projectDir . '/docker/database/analysis');

        $policy = new SafeQueryPolicy([]);
        $this->registry = new ConnectionRegistry('default', $policy, $this->logger());
        $this->registry->register('default', new PdoAdapter($this->pdo));

        $this->seed();
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo)) {
            $this->pdo->exec('DROP SCHEMA IF EXISTS ' . self::SCHEMA . ' CASCADE');
        }
        if ($this->projectDir !== '' && is_dir($this->projectDir)) {
            $this->removeTree($this->projectDir);
        }
    }

    public function testCycleFromSeedToImportLeavesNoErrors(): void
    {
        // --- Слепок: оценки строк, профили колонок, внешние ключи. Без чтения значений.
        $builder = $this->analysisBuilder();
        $inventory = $builder->buildInventory();

        self::assertArrayHasKey(self::SCHEMA, $inventory['schemas']);
        $tables = $inventory['schemas'][self::SCHEMA]['tables'];
        self::assertArrayHasKey('parents', $tables);
        self::assertArrayHasKey('children', $tables);

        // Внешний ключ из БД обязан попасть в слепок: на нём стоит связь `db_fk`.
        $childFks = $this->foreignKeysOf($tables['children']);
        self::assertContains(self::SCHEMA . '.parents', $childFks);

        // --- Досье и решения.
        $dumpConfig = $this->writeConfig();
        $dossier = (new DossierBuilder(
            new MigrationScanner($this->projectDir),
            new ViewScanner($this->registry)
        ))->build(self::SCHEMA, $inventory, $dumpConfig);

        $decisions = (new DecisionEngine())->decide($dossier);
        self::assertGreaterThan(0, $decisions['summary']['total'], 'Правила не нашли ни одного повода — досье собрано неверно.');

        $rules = array_keys($decisions['summary']['by_rule']);
        // R4: колонка с ПД-именем без faker; R5: связь по внешнему ключу; R7: таблицы нет в БД.
        self::assertContains('R4', $rules, 'ФИО без faker должно было дать решение R4.');
        self::assertContains('R5', $rules, 'Внешний ключ БД должен был дать связь (R5).');
        self::assertContains('R7', $rules, 'Таблица, которой нет в базе, должна была дать R7.');

        // --- Применение: только auto, без отметок.
        $enricher = new ConfigEnricher(
            $this->fileSystem,
            new ConfigSplitter($this->fileSystem, $this->logger()),
            $this->logger(),
            $this->projectDir,
            $this->configStore()
        );
        $applied = $enricher->applyDecisions($this->configPath(), $decisions['decisions']);

        self::assertGreaterThan(0, $applied['applied'], 'Ни одно механическое решение не применилось.');
        self::assertSame(0, $applied['stale'], 'Решение сочло конфиг изменившимся, хотя его никто не трогал.');
        self::assertSame(0, $applied['invalid'], 'Правило предложило то, что не проходит валидацию конфига.');

        $written = Yaml::parse($this->fileSystem->read($this->configPath()));
        // R4 поставил faker на ФИО, R7 убрал таблицу-фантом.
        self::assertSame('fio', $written['faker'][self::SCHEMA]['parents']['full_name']);
        self::assertArrayNotHasKey('ghost', $written['partial_export'][self::SCHEMA]);

        // --- Проверки до выгрузки.
        $reloaded = (new YamlConfigLoader())->load($this->configPath());
        $inventoryPath = $this->projectDir . '/docker/database/analysis/schema_inventory.json';
        $this->fileSystem->write(
            $inventoryPath,
            (string) json_encode($inventory, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );

        $report = $this->checkRunner()->run([
            'config_path' => $this->configPath(),
            'inventory_path' => $inventoryPath,
            'schemas' => [self::SCHEMA],
            'stages' => [FindingCatalog::STAGE_STATIC, FindingCatalog::STAGE_LIVE, FindingCatalog::STAGE_PLAN],
        ]);
        self::assertFalse(
            $report->hasAtLeast(Finding::SEVERITY_ERROR),
            'Проверки до выгрузки нашли ошибку: ' . $this->describe($report->toArray())
        );

        // --- Выгрузка.
        $this->dumper($reloaded)->exportAll((new TableConfigResolver($reloaded))->resolveAll(self::SCHEMA));

        $dumpFile = $this->projectDir . '/docker/database/dumps/' . self::SCHEMA . '/parents.sql';
        self::assertFileExists($dumpFile);
        $dump = $this->fileSystem->read($dumpFile);
        self::assertStringNotContainsString(
            'Иванов Иван Иванович',
            $dump,
            'ФИО ушло в дамп как есть — faker не применился.'
        );

        // --- Проверки после выгрузки и контрольный импорт в ту же базу (своя схема).
        $after = $this->checkRunner()->run([
            'config_path' => $this->configPath(),
            'inventory_path' => $inventoryPath,
            'schemas' => [self::SCHEMA],
            'stages' => [FindingCatalog::STAGE_DUMP],
        ]);
        self::assertFalse(
            $after->hasAtLeast(Finding::SEVERITY_ERROR),
            'Проверки дампа нашли ошибку: ' . $this->describe($after->toArray())
        );

        $coverage = $after->toArray();
        self::assertArrayHasKey('stages', $coverage);
    }

    /**
     * Сид: родитель с настоящим внешним ключом от ребёнка, категориальная колонка со всеми
     * значениями домена и колонка с ПД-именем без faker — по одному поводу на правило.
     */
    private function seed(): void
    {
        $schema = self::SCHEMA;
        $pdo = $this->pdo();
        $pdo->exec("DROP SCHEMA IF EXISTS {$schema} CASCADE");
        $pdo->exec("CREATE SCHEMA {$schema}");
        $pdo->exec("
            CREATE TABLE {$schema}.parents (
                id        bigserial PRIMARY KEY,
                status_id integer NOT NULL,
                full_name varchar(255),
                phone     varchar(32)
            )
        ");
        $pdo->exec("
            CREATE TABLE {$schema}.children (
                id        bigserial PRIMARY KEY,
                parent_id bigint NOT NULL REFERENCES {$schema}.parents (id),
                kind_id   integer NOT NULL
            )
        ");

        $insertParent = $pdo->prepare(
            "INSERT INTO {$schema}.parents (status_id, full_name, phone) VALUES (?, ?, ?)"
        );
        $insertChild = $pdo->prepare(
            "INSERT INTO {$schema}.children (parent_id, kind_id) VALUES (?, ?)"
        );
        for ($i = 1; $i <= 60; $i++) {
            $insertParent->execute([
                1 + ($i % 4),
                $i === 1 ? 'Иванов Иван Иванович' : 'Петров Пётр Петрович',
                '+7916000' . str_pad((string) $i, 4, '0', STR_PAD_LEFT),
            ]);
            $insertChild->execute([(int) $pdo->lastInsertId(), 1 + ($i % 3)]);
        }

        // Без ANALYZE у Postgres 14+ reltuples = -1: слепок соберётся по пустой статистике.
        $pdo->exec("ANALYZE {$schema}.parents");
        $pdo->exec("ANALYZE {$schema}.children");
    }

    /**
     * Конфиг с одной таблицей-фантомом: её нет в БД, и её должно убрать правило R7.
     */
    private function writeConfig(): DumpConfig
    {
        $config = [
            'settings' => ['batch_size' => 100],
            'partial_export' => [
                self::SCHEMA => [
                    'parents' => ['limit' => 50, 'order_by' => 'id'],
                    'children' => ['limit' => 50, 'order_by' => 'id'],
                    'ghost' => ['limit' => 10],
                ],
            ],
        ];
        $this->fileSystem->write($this->configPath(), Yaml::dump($config, 6, 2));

        return (new YamlConfigLoader())->load($this->configPath());
    }

    private function configPath(): string
    {
        return $this->projectDir . '/docker/database/dump_config.yaml';
    }

    private function configStore(): DbdumpConfigStore
    {
        return new DbdumpConfigStore(
            $this->fileSystem,
            new EnvironmentConfig('test'),
            null,
            ['data_dir' => 'docker/database']
        );
    }

    private function analysisBuilder(): AnalysisPackageBuilder
    {
        $stats = new PgStatsReader($this->registry);
        $inspector = new TableInspector($this->registry, $stats);

        return new AnalysisPackageBuilder(
            $this->fileSystem,
            $this->registry,
            $inspector,
            new ServiceTableFilter(),
            $this->dependencyResolver(),
            new ColumnStatisticsInspector($this->registry, 200, new SafeQueryPolicy([]), $stats),
            $this->logger(),
            $this->projectDir,
            new CodeHintScanner($this->projectDir, $this->logger()),
            $this->configStore(),
            new RowCounter($inspector, new SafeQueryPolicy([])),
            $stats
        );
    }

    private function checkRunner(): CheckRunner
    {
        return new CheckRunner(
            new ConfigAuditor($this->fileSystem),
            new AuditFixer($this->fileSystem, $this->logger()),
            $this->fileSystem,
            new YamlConfigLoader(),
            $this->registry,
            new CriteriaSqlTester($this->registry),
            $this->verificationRunner(),
            $this->configStore(),
            $this->logger(),
            $this->projectDir
        );
    }

    private function dumper(DumpConfig $config): DatabaseDumper
    {
        $pkInspector = new PrimaryKeyInspector($this->registry);
        $pkRegistry = new SelectedPkRegistry();
        $policy = new SafeQueryPolicy([]);
        $stats = new PgStatsReader($this->registry);

        $sample = new SampleQueryBuilder(
            $this->registry,
            $pkInspector,
            $pkRegistry,
            $this->logger(),
            $stats,
            $policy,
            new TableInspector($this->registry, $stats),
            new SampleReportCollector()
        );

        $fetcher = new DataFetcher(
            $this->registry,
            new CascadeWhereResolver($this->registry, 10, $this->logger(), $pkRegistry),
            $config,
            $sample
        );

        $sqlGenerator = new SqlGenerator(
            new TruncateGenerator($this->registry),
            new InsertGenerator($this->registry, 100),
            new SequenceGenerator($this->registry),
            new DeferredUpdateGenerator($this->registry)
        );

        return new DatabaseDumper(
            $fetcher,
            $sqlGenerator,
            $this->fileSystem,
            $this->logger(),
            $this->projectDir,
            $this->dependencyResolver(),
            new RussianFaker(),
            $config,
            new ProductionGuard(new EnvironmentConfig('test')),
            $this->configStore(),
            $policy,
            new SampleReportCollector()
        );
    }

    /**
     * Подключение уже открыто в setUp; отдельный геттер — чтобы анализатор видел, что оно есть.
     */
    private function pdo(): \PDO
    {
        self::assertNotNull($this->pdo);

        return $this->pdo;
    }

    private function dependencyResolver(): TableDependencyResolver
    {
        return new TableDependencyResolver(new ForeignKeyInspector($this->registry), new TopologicalSorter());
    }

    private function verificationRunner(): DumpVerificationRunner
    {
        $reader = new DumpValueReader();

        return new DumpVerificationRunner($reader, [
            new CascadeClosureVerifier($reader),
            new ValueCoverageVerifier(),
            new PiiLeakVerifier(),
            new RowCountVerifier(),
        ]);
    }

    /**
     * Логгер: пакет не поставляет NullLogger, а шум прогона в выводе теста не нужен.
     */
    private function logger(): LoggerInterface
    {
        return $this->createMock(LoggerInterface::class);
    }

    private function removeTree(string $path): void
    {
        $items = scandir($path);
        foreach ($items === false ? [] : $items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $full = $path . '/' . $item;
            if (is_dir($full)) {
                $this->removeTree($full);
            } else {
                @unlink($full);
            }
        }
        @rmdir($path);
    }

    /**
     * @param array<string, mixed> $table запись таблицы из слепка
     *
     * @return array<int, string>
     */
    private function foreignKeysOf(array $table): array
    {
        $out = [];
        foreach (isset($table['foreign_keys']) ? $table['foreign_keys'] : [] as $fk) {
            $out[] = (string) $fk['references_table'];
        }

        return $out;
    }

    /**
     * Ошибки в сообщении провала: без них «нашли ошибку» не сообщает, какую именно.
     *
     * @param array<string, mixed> $report
     */
    private function describe(array $report): string
    {
        $lines = [];
        foreach (isset($report['findings']) ? $report['findings'] : [] as $finding) {
            if (($finding['severity'] ?? '') !== Finding::SEVERITY_ERROR) {
                continue;
            }
            $lines[] = sprintf(
                '%s %s %s',
                (string) ($finding['code'] ?? '?'),
                (string) ($finding['schema'] ?? '') . '.' . (string) ($finding['table'] ?? ''),
                (string) ($finding['message'] ?? '')
            );
        }

        return $lines === [] ? '(список пуст)' : implode('; ', $lines);
    }
}
