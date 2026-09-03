<?php

namespace Timbrs\DatabaseDumps\Service\Check;

use Timbrs\DatabaseDumps\Config\DumpConfig;
use Timbrs\DatabaseDumps\Config\TableConfig;
use Timbrs\DatabaseDumps\Contract\ConfigLoaderInterface;
use Timbrs\DatabaseDumps\Contract\ConnectionRegistryInterface;
use Timbrs\DatabaseDumps\Contract\DatabaseConnectionInterface;
use Timbrs\DatabaseDumps\Contract\FileSystemInterface;
use Timbrs\DatabaseDumps\Contract\LoggerInterface;
use Timbrs\DatabaseDumps\Service\Ai\DbdumpConfigStore;
use Timbrs\DatabaseDumps\Service\Analysis\CriteriaSqlTester;
use Timbrs\DatabaseDumps\Service\Dumper\SampleQueryBuilder;
use Timbrs\DatabaseDumps\Service\Dumper\TableConfigResolver;
use Timbrs\DatabaseDumps\Service\Importer\DatabaseImporter;
use Timbrs\DatabaseDumps\Service\Validation\AuditFixer;
use Timbrs\DatabaseDumps\Service\Validation\ConfigAuditor;
use Timbrs\DatabaseDumps\Service\Validation\ConfigDocument;
use Timbrs\DatabaseDumps\Service\Validation\Finding;
use Timbrs\DatabaseDumps\Service\Validation\FindingCatalog;
use Timbrs\DatabaseDumps\Service\Validation\InventoryReader;
use Timbrs\DatabaseDumps\Service\Verification\DumpVerificationInput;
use Timbrs\DatabaseDumps\Service\Verification\DumpVerificationRunner;

/**
 * Одна команда check вместо validate / check-criteria / verify-dump / --dry-run: стадии
 * выбираются по тому, что есть под рукой (слепок, живая БД, файлы дампа, scratch-БД),
 * находки собираются в одно пространство кодов (FindingCatalog), отчёт — один JSON.
 *
 *   static — конфиг против слепка (ConfigAuditor), без БД; --fix здесь же;
 *   live   — каждый sample.criterion в БД под statement_timeout: Q-7 падает, Q-8 таймаут,
 *            Q-6 корзина пуста; плюс P-1/P-2 из слепка;
 *   plan   — что и как будет выгружено: режим, where, каскад, корзины;
 *   dump   — что реально легло в файлы (DumpVerificationRunner: V-1…V-8);
 *   import — контрольная заливка в scratch-БД (--import-connection): I-1…I-4.
 */
class CheckRunner
{
    public const STAGES = [
        FindingCatalog::STAGE_STATIC,
        FindingCatalog::STAGE_LIVE,
        FindingCatalog::STAGE_PLAN,
        FindingCatalog::STAGE_DUMP,
        FindingCatalog::STAGE_IMPORT,
    ];

    /** @var ConfigAuditor */
    private $auditor;

    /** @var AuditFixer */
    private $fixer;

    /** @var FileSystemInterface */
    private $fileSystem;

    /** @var ConfigLoaderInterface */
    private $configLoader;

    /** @var ConnectionRegistryInterface */
    private $registry;

    /** @var CriteriaSqlTester */
    private $sqlTester;

    /** @var DumpVerificationRunner */
    private $dumpRunner;

    /** @var DbdumpConfigStore */
    private $store;

    /** @var LoggerInterface */
    private $logger;

    /** @var string */
    private $projectDir;

    /** @var DatabaseImporter|null */
    private $importer;

    public function __construct(
        ConfigAuditor $auditor,
        AuditFixer $fixer,
        FileSystemInterface $fileSystem,
        ConfigLoaderInterface $configLoader,
        ConnectionRegistryInterface $registry,
        CriteriaSqlTester $sqlTester,
        DumpVerificationRunner $dumpRunner,
        DbdumpConfigStore $store,
        LoggerInterface $logger,
        string $projectDir,
        DatabaseImporter $importer = null
    ) {
        $this->auditor = $auditor;
        $this->fixer = $fixer;
        $this->fileSystem = $fileSystem;
        $this->configLoader = $configLoader;
        $this->registry = $registry;
        $this->sqlTester = $sqlTester;
        $this->dumpRunner = $dumpRunner;
        $this->store = $store;
        $this->logger = $logger;
        $this->projectDir = rtrim($projectDir, '/\\');
        $this->importer = $importer;
    }

    /**
     * @param array{
     *     config_path: string,
     *     inventory_path: string,
     *     schemas?: array<int, string>,
     *     stages?: array<int, string>|null,
     *     fix?: bool,
     *     tables?: array<int, string>|null,
     *     connection?: string|null,
     *     import_connection?: string|null
     * } $options
     */
    public function run(array $options): CheckReport
    {
        $configPath = $options['config_path'];
        $inventoryPath = $options['inventory_path'];
        $schemas = array_values($options['schemas'] ?? []);
        $requested = isset($options['stages']) && $options['stages'] !== null && $options['stages'] !== []
            ? array_values(array_unique($options['stages']))
            : null;
        $tables = isset($options['tables']) && $options['tables'] !== null ? $this->tableSet($options['tables']) : null;
        $connectionName = $options['connection'] ?? null;
        $importConnection = $options['import_connection'] ?? null;

        $report = new CheckReport([
            'config_path' => $configPath,
            'inventory_path' => $inventoryPath,
            'schema_filter' => $schemas,
            'table_filter' => $tables !== null ? array_keys($tables) : null,
            'stages_requested' => $requested,
        ]);

        $wants = function (string $stage) use ($requested): bool {
            return $requested === null || in_array($stage, $requested, true);
        };

        $inventory = new InventoryReader($this->fileSystem, $inventoryPath);

        // static
        if ($wants(FindingCatalog::STAGE_STATIC)) {
            $this->runStatic($report, $configPath, $inventory, $schemas, !empty($options['fix']), $tables);
        }

        $dumpConfig = null;
        $configError = null;
        try {
            $dumpConfig = $this->configLoader->load($configPath);
        } catch (\Throwable $e) {
            $configError = $e->getMessage();
        }

        // live
        if ($wants(FindingCatalog::STAGE_LIVE)) {
            if ($dumpConfig === null) {
                $report->addStage(FindingCatalog::STAGE_LIVE, false, 'конфиг не загрузился: ' . $configError, 0, null, []);
            } else {
                $this->runLive($report, $dumpConfig, $inventory, $schemas, $tables, $connectionName, $requested !== null);
            }
        }

        // plan
        if ($wants(FindingCatalog::STAGE_PLAN)) {
            if ($dumpConfig === null) {
                $report->addStage(FindingCatalog::STAGE_PLAN, false, 'конфиг не загрузился: ' . $configError, 0, null, []);
            } else {
                $this->runPlan($report, $dumpConfig, $schemas, $tables, $connectionName);
            }
        }

        // dump
        if ($wants(FindingCatalog::STAGE_DUMP)) {
            if ($dumpConfig === null) {
                $report->addStage(FindingCatalog::STAGE_DUMP, false, 'конфиг не загрузился: ' . $configError, 0, null, []);
            } else {
                $this->runDump($report, $dumpConfig, $inventory, $schemas, $tables, $connectionName);
            }
        }

        // import
        if ($wants(FindingCatalog::STAGE_IMPORT)) {
            $this->runImport($report, $schemas, $importConnection);
        }

        return $report;
    }

    /**
     * @param array<int, string>          $schemas
     * @param array<string, true>|null    $tables
     */
    private function runStatic(CheckReport $report, string $configPath, InventoryReader $inventory, array $schemas, bool $fix, ?array $tables): void
    {
        $started = microtime(true);
        $extra = [];
        try {
            $result = $this->auditor->audit($configPath, $inventory, $schemas);
            if ($fix) {
                $fixReport = $this->fixer->fix(ConfigDocument::load($this->fileSystem, $configPath), $result->getFindings());
                $extra['fix'] = [
                    'applied' => $fixReport['applied'],
                    'skipped' => $fixReport['skipped'],
                    'files' => $fixReport['files'],
                    'by_code' => $fixReport['by_code'],
                    'errors' => $fixReport['errors'],
                ];
                if ($fixReport['applied'] > 0) {
                    // Конфиг изменился — находки и код возврата должны отражать состояние ПОСЛЕ правок.
                    $inventory = new InventoryReader($this->fileSystem, $inventory->getPath());
                    $result = $this->auditor->audit($configPath, $inventory, $schemas);
                }
            }
        } catch (\Throwable $e) {
            $report->addStage(FindingCatalog::STAGE_STATIC, false, 'аудит не отработал: ' . $e->getMessage(), $this->ms($started), null, []);

            return;
        }

        $report->setCoverage($result->getCoverage());
        $meta = $result->getMeta();
        $extra['inventory_present'] = !empty($meta['inventory_present']);
        $extra['inventory_generated_at'] = $meta['inventory_generated_at'] ?? null;
        $extra['schemas_checked'] = $meta['schemas_checked'] ?? [];

        $report->addStage(
            FindingCatalog::STAGE_STATIC,
            true,
            null,
            $this->ms($started),
            null,
            $this->filterByTables($result->getFindings(), $tables),
            $extra
        );
    }

    /**
     * @param array<int, string>       $schemas
     * @param array<string, true>|null $tables
     */
    private function runLive(
        CheckReport $report,
        DumpConfig $dumpConfig,
        InventoryReader $inventory,
        array $schemas,
        ?array $tables,
        ?string $connectionName,
        bool $explicit
    ): void {
        $started = microtime(true);
        try {
            $connection = $this->registry->getConnection($connectionName);
            $this->ping($connection);
        } catch (\Throwable $e) {
            $report->addStage(
                FindingCatalog::STAGE_LIVE,
                false,
                ($explicit ? 'БД недоступна: ' : 'БД недоступна, стадия пропущена: ') . $this->shortError($e->getMessage()),
                $this->ms($started),
                null,
                []
            );

            return;
        }

        $platform = $this->registry->getPlatform($connectionName);
        $findings = [];
        $tested = 0;
        $queries = 1;

        foreach ($this->resolveTables($dumpConfig, $schemas, $tables, $connectionName) as $config) {
            if (!$config->hasSample()) {
                continue;
            }
            $schema = $config->getSchema();
            $table = $config->getTable();
            $fullTable = $platform->getFullTableName($schema, $table);
            $sample = $config->getSample() ?? [];
            $criteria = isset($sample[TableConfig::SAMPLE_KEY_CRITERIA]) && is_array($sample[TableConfig::SAMPLE_KEY_CRITERIA])
                ? $sample[TableConfig::SAMPLE_KEY_CRITERIA]
                : [];

            foreach ($criteria as $criterion) {
                if (!is_array($criterion) || !isset($criterion[TableConfig::CRITERION_KEY_WHERE])) {
                    continue;
                }
                $name = isset($criterion[TableConfig::CRITERION_KEY_NAME]) ? (string) $criterion[TableConfig::CRITERION_KEY_NAME] : '?';
                $where = (string) $criterion[TableConfig::CRITERION_KEY_WHERE];
                $limit = isset($criterion[TableConfig::CRITERION_KEY_LIMIT]) ? (int) $criterion[TableConfig::CRITERION_KEY_LIMIT] : 1;
                $tested++;
                $queries++;

                $error = $this->sqlTester->test($schema, $table, $where, $connectionName);
                if ($error !== null) {
                    $isTimeout = CriteriaSqlTester::isTimeoutError($error);
                    $findings[] = $isTimeout
                        ? Finding::warning('Q-8', sprintf('критерий «%s» не уложился в statement_timeout — на экспорте будет так же медленно', $name), $schema, $table, null, false, ['criterion' => $name, 'where' => $where, 'error' => $error])
                        : Finding::error('Q-7', sprintf('критерий «%s» падает в БД: %s', $name, $error), $schema, $table, null, false, ['criterion' => $name, 'where' => $where, 'error' => $error]);
                    continue;
                }

                // Сколько строк даст корзина (не больше её квоты): ноль — вида данных в дампе не будет.
                $base = SampleQueryBuilder::combineWhere($config->getWhere(), null);
                $conditions = $base !== null ? "({$base}) AND ({$where})" : "({$where})";
                $probe = sprintf(
                    'SELECT COUNT(*) AS c FROM (SELECT 1 AS one FROM %s WHERE %s %s) probe',
                    $fullTable,
                    $conditions,
                    $platform->getLimitSql(max(1, $limit))
                );
                $queries++;
                try {
                    $rows = $connection->fetchAllAssociative($probe);
                } catch (\Throwable $e) {
                    $findings[] = Finding::warning('Q-8', sprintf('корзину «%s» не удалось посчитать: %s', $name, $this->shortError($e->getMessage())), $schema, $table, null, false, ['criterion' => $name, 'where' => $where]);
                    continue;
                }
                $count = $rows !== [] && isset($rows[0]['c']) ? (int) $rows[0]['c'] : 0;
                if ($count === 0) {
                    $findings[] = Finding::warning(
                        'Q-6',
                        sprintf('корзина «%s» не ловит ни одной строки в БД%s — этого вида данных в дампе не будет', $name, $config->getCascadeFrom() !== null ? ' (без учёта каскада)' : ''),
                        $schema,
                        $table,
                        null,
                        false,
                        ['criterion' => $name, 'where' => $where, 'rows' => 0]
                    );
                }
            }
        }

        foreach ($inventory->warnings() as $warning) {
            $code = (string) $warning['code'];
            $entry = FindingCatalog::get($code);
            if ($entry === null) {
                continue;
            }
            $schema = isset($warning['schema']) ? (string) $warning['schema'] : null;
            $table = isset($warning['table']) ? (string) $warning['table'] : null;
            if ($schemas !== [] && ($schema === null || !in_array($schema, $schemas, true))) {
                continue;
            }
            $findings[] = Finding::warning(
                $code,
                isset($warning['message']) ? (string) $warning['message'] : $entry['title'],
                $schema,
                $table,
                isset($warning['column']) ? (string) $warning['column'] : null
            );
        }

        $report->addStage(
            FindingCatalog::STAGE_LIVE,
            true,
            null,
            $this->ms($started),
            $queries,
            $this->filterByTables($findings, $tables),
            ['criteria_tested' => $tested]
        );
    }

    /**
     * @param array<int, string>       $schemas
     * @param array<string, true>|null $tables
     */
    private function runPlan(CheckReport $report, DumpConfig $dumpConfig, array $schemas, ?array $tables, ?string $connectionName): void
    {
        $started = microtime(true);
        $rows = [];
        foreach ($this->resolveTables($dumpConfig, $schemas, $tables, $connectionName) as $config) {
            $sample = $config->getSample();
            $parents = [];
            foreach ($config->getCascadeFrom() ?? [] as $entry) {
                $parents[] = $entry['parent'] . ' (' . $entry['fk_column'] . ' → ' . $entry['parent_column'] . ')';
            }
            $buckets = [];
            foreach ($sample[TableConfig::SAMPLE_KEY_CRITERIA] ?? [] as $criterion) {
                if (is_array($criterion)) {
                    $buckets[] = ['name' => $criterion[TableConfig::CRITERION_KEY_NAME] ?? '?', 'limit' => $criterion[TableConfig::CRITERION_KEY_LIMIT] ?? null];
                }
            }
            $rows[] = [
                'table' => $config->getFullTableName(),
                'mode' => $config->isFullExport() ? 'full' : 'partial',
                'limit' => $config->getLimit(),
                'where' => $config->getWhere(),
                'order_by' => $config->getOrderBy(),
                'cascade_from' => $parents,
                'criteria' => $buckets,
                'stratify_by' => TableConfig::stratifyColumns($sample),
                'stratify' => count(TableConfig::stratifySpecs($sample)),
                'stratify_via' => count(TableConfig::stratifyVia($sample)),
            ];
        }

        $report->addStage(FindingCatalog::STAGE_PLAN, true, null, $this->ms($started), null, [], ['tables' => $rows]);
    }

    /**
     * @param array<int, string>       $schemas
     * @param array<string, true>|null $tables
     */
    private function runDump(
        CheckReport $report,
        DumpConfig $dumpConfig,
        InventoryReader $inventory,
        array $schemas,
        ?array $tables,
        ?string $connectionName
    ): void {
        $started = microtime(true);
        $dumpsRoot = $this->projectDir . '/' . $this->store->getDataDir($this->projectDir) . '/' . DumpConfig::DUMPS_DIR;
        if (!is_dir($dumpsRoot)) {
            $report->addStage(FindingCatalog::STAGE_DUMP, false, 'каталога дампов нет: ' . $dumpsRoot . ' — сначала app:dbdump:export', $this->ms($started), null, []);

            return;
        }

        $configs = $this->resolveTables($dumpConfig, $schemas, $tables, $connectionName);
        try {
            $result = $this->dumpRunner->run(new DumpVerificationInput(
                $dumpsRoot,
                $configs,
                $inventory->exists() ? $inventory : null,
                $dumpConfig
            ));
        } catch (\Throwable $e) {
            $report->addStage(FindingCatalog::STAGE_DUMP, false, 'проверка дампов не отработала: ' . $e->getMessage(), $this->ms($started), null, []);

            return;
        }

        $report->addStage(
            FindingCatalog::STAGE_DUMP,
            true,
            null,
            $this->ms($started),
            null,
            $result['findings'],
            ['dumps_root' => $dumpsRoot, 'verifiers' => $result['stats'], 'inventory_used' => $inventory->exists()]
        );
    }

    /**
     * @param array<int, string> $schemas
     */
    private function runImport(CheckReport $report, array $schemas, ?string $importConnection): void
    {
        $started = microtime(true);
        if ($importConnection === null || $importConnection === '') {
            $report->addStage(FindingCatalog::STAGE_IMPORT, false, 'scratch-БД не указана (--import-connection=<имя>)', 0, null, []);

            return;
        }
        if ($this->importer === null) {
            $report->addStage(FindingCatalog::STAGE_IMPORT, false, 'импортёр не сконфигурирован', 0, null, []);

            return;
        }

        $findings = [];
        $extra = [];
        try {
            $importReport = $this->importer->import(true, true, $schemas[0] ?? null, $importConnection);
            $findings = $importReport->getFindings();
            $extra = [
                'connection' => $importConnection,
                'tables_imported' => $importReport->getTablesImported(),
                'tables_skipped' => $importReport->getTablesSkipped(),
                'rows_loaded' => $importReport->getRowsLoaded(),
            ];
        } catch (\Throwable $e) {
            $findings = $this->importer->getReport()->getFindings();
            $findings[] = Finding::error('X-1', 'импорт в scratch-БД упал (транзакция откачена): ' . $this->shortError($e->getMessage()));
            $extra = ['connection' => $importConnection, 'failed' => true];
        }

        $report->addStage(FindingCatalog::STAGE_IMPORT, true, null, $this->ms($started), null, $findings, $extra);
    }

    /**
     * Конфиги таблиц по фильтрам схем и таблиц; таблица с битым конфигом пропускается —
     * о ней уже сказала static-стадия (S-2).
     *
     * @param array<int, string>       $schemas
     * @param array<string, true>|null $tables
     * @return array<int, TableConfig>
     */
    private function resolveTables(DumpConfig $dumpConfig, array $schemas, ?array $tables, ?string $connectionName): array
    {
        $resolver = new TableConfigResolver($dumpConfig);
        $configs = [];
        try {
            if ($schemas === []) {
                $configs = $resolver->resolveAll(null, $connectionName);
            } else {
                foreach ($schemas as $schema) {
                    foreach ($resolver->resolveAll($schema, $connectionName) as $config) {
                        $configs[] = $config;
                    }
                }
            }
        } catch (\Throwable $e) {
            $this->logger->warning('check: конфиг таблиц разрешён не полностью: ' . $e->getMessage());
        }

        if ($tables === null) {
            return $configs;
        }

        return array_values(array_filter($configs, function (TableConfig $config) use ($tables): bool {
            return isset($tables[strtolower($config->getFullTableName())]);
        }));
    }

    /**
     * @param array<int, Finding>      $findings
     * @param array<string, true>|null $tables
     * @return array<int, Finding>
     */
    private function filterByTables(array $findings, ?array $tables): array
    {
        if ($tables === null) {
            return $findings;
        }

        return array_values(array_filter($findings, function (Finding $finding) use ($tables): bool {
            if ($finding->getSchema() === null || $finding->getTable() === null) {
                return true;
            }

            return isset($tables[strtolower($finding->getSchema() . '.' . $finding->getTable())]);
        }));
    }

    /**
     * @param array<int, string> $tables
     * @return array<string, true>
     */
    private function tableSet(array $tables): array
    {
        $set = [];
        foreach ($tables as $table) {
            $table = strtolower(trim((string) $table));
            if ($table !== '') {
                $set[$table] = true;
            }
        }

        return $set;
    }

    private function ping(DatabaseConnectionInterface $connection): void
    {
        try {
            $connection->fetchAllAssociative('SELECT 1');
        } catch (\Throwable $e) {
            // Oracle: SELECT без FROM не бывает.
            $connection->fetchAllAssociative('SELECT 1 FROM DUAL');
        }
    }

    private function ms(float $started): int
    {
        return (int) round((microtime(true) - $started) * 1000);
    }

    private function shortError(string $message): string
    {
        $line = strtok($message, "\n");
        $line = $line === false ? $message : $line;
        $collapsed = preg_replace('/\s+/', ' ', trim($line));
        $line = $collapsed === null ? $line : $collapsed;

        return strlen($line) > 200 ? substr($line, 0, 200) . '…' : $line;
    }
}
