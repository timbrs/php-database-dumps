<?php

namespace Timbrs\DatabaseDumps\Service\Dumper;

use Timbrs\DatabaseDumps\Config\DumpConfig;
use Timbrs\DatabaseDumps\Config\TableConfig;
use Timbrs\DatabaseDumps\Contract\FakerInterface;
use Timbrs\DatabaseDumps\Contract\FileSystemInterface;
use Timbrs\DatabaseDumps\Contract\LoggerInterface;
use Timbrs\DatabaseDumps\Exception\ExportFailedException;
use Timbrs\DatabaseDumps\Service\Ai\DbdumpConfigStore;
use Timbrs\DatabaseDumps\Service\Db\SafeQueryPolicy;
use Timbrs\DatabaseDumps\Service\Generator\SqlGenerator;
use Timbrs\DatabaseDumps\Service\Graph\TableDependencyResolver;
use Timbrs\DatabaseDumps\Service\Security\ProductionGuard;

/**
 * Экспорт данных из БД в SQL-дампы.
 *
 * Особенности безопасности:
 * - При наличии ProductionGuard в конструкторе и НЕ установленном allowProdExport
 *   экспорт блокируется в prod (защита от утечки PII).
 * - Запись файла атомарная (writeAtomic): дамп никогда не остаётся «половинным»
 *   при падении посреди.
 * - При исключении частично записанный файл удаляется (cleanup).
 */
class DatabaseDumper
{
    /** @var DataFetcher */
    private $dataFetcher;

    /** @var SqlGenerator */
    private $sqlGenerator;

    /** @var FileSystemInterface */
    private $fileSystem;

    /** @var LoggerInterface */
    private $logger;

    /** @var string */
    private $projectDir;

    /** @var TableDependencyResolver */
    private $dependencyResolver;

    /** @var FakerInterface */
    private $faker;

    /** @var DumpConfig */
    private $dumpConfig;

    /** @var ProductionGuard|null */
    private $productionGuard;

    /** @var bool */
    private $allowProdExport = false;

    /** @var DbdumpConfigStore|null */
    private $configStore;

    /** @var SafeQueryPolicy|null */
    private $policy;

    /** @var SampleReportCollector|null */
    private $sampleReport;

    public function __construct(
        DataFetcher $dataFetcher,
        SqlGenerator $sqlGenerator,
        FileSystemInterface $fileSystem,
        LoggerInterface $logger,
        string $projectDir,
        TableDependencyResolver $dependencyResolver,
        FakerInterface $faker,
        DumpConfig $dumpConfig,
        ProductionGuard $productionGuard = null,
        DbdumpConfigStore $configStore = null,
        SafeQueryPolicy $policy = null,
        SampleReportCollector $sampleReport = null
    ) {
        $this->dataFetcher = $dataFetcher;
        $this->sqlGenerator = $sqlGenerator;
        $this->fileSystem = $fileSystem;
        $this->logger = $logger;
        $this->projectDir = $projectDir;
        $this->dependencyResolver = $dependencyResolver;
        $this->faker = $faker;
        $this->dumpConfig = $dumpConfig;
        $this->productionGuard = $productionGuard;
        $this->configStore = $configStore;
        $this->policy = $policy;
        $this->sampleReport = $sampleReport;
    }

    public function setAllowProdExport(bool $allow): void
    {
        $this->allowProdExport = $allow;
    }

    /**
     * Экспортировать одну таблицу.
     */
    public function exportTable(TableConfig $config): void
    {
        $this->guardProd();
        $this->withExportProfile(function () use ($config): void {
            $this->doExportTable($config, null, null);
        });
    }

    /**
     * Экспортировать список таблиц (с учётом FK-зависимостей).
     *
     * @param array<TableConfig> $tables
     */
    public function exportAll(array $tables): void
    {
        if (empty($tables)) {
            return;
        }

        $this->guardProd();
        $this->withExportProfile(function () use ($tables): void {
            $this->doExportAll($tables);
        });
    }

    /**
     * Профиль сессии export на время выгрузки: длинный statement_timeout вместо разведочного,
     * без бюджета запросов. После — обратно, каким был.
     */
    private function withExportProfile(callable $export): void
    {
        if ($this->policy === null) {
            $export();
            return;
        }
        $previous = $this->policy->getProfile();
        $this->policy->setProfile(SafeQueryPolicy::PROFILE_EXPORT);
        try {
            $export();
        } finally {
            $this->policy->setProfile($previous);
        }
    }

    /**
     * @param array<TableConfig> $tables
     */
    private function doExportAll(array $tables): void
    {
        $tableKeys = [];
        foreach ($tables as $t) {
            $tableKeys[] = $t->getFullTableName();
        }

        $connectionName = $tables[0]->getConnectionName();

        // Второй источник рёбер: в базе без FK-констрейнтов связи живут только в конфиге,
        // и без них порядок выгрузки вырождается в алфавитный (находка G-4 валидатора).
        $cascadeByChild = [];
        foreach ($tables as $t) {
            $cascade = $t->getCascadeFrom();
            if ($cascade !== null && $cascade !== []) {
                $cascadeByChild[$t->getFullTableName()] = $cascade;
            }
        }

        $sortResult = $this->dependencyResolver->sortForExportWithResult(
            $tableKeys,
            $connectionName,
            TableDependencyResolver::cascadeEdges($cascadeByChild)
        );
        $sortedKeys = $sortResult->getSorted();

        $tableMap = [];
        foreach ($tables as $t) {
            $tableMap[$t->getFullTableName()] = $t;
        }

        if ($sortResult->hasDeferredEdges()) {
            $this->logger->info(
                'Обнаружены циклические FK-зависимости, разорваны рёбра: '
                . count($sortResult->getDeferredEdges())
            );
            foreach ($sortResult->getDeferredEdges() as $edge) {
                $sourceKey = $edge['source'];
                if (!isset($tableMap[$sourceKey]) || $edge['source_column'] === '') {
                    continue;
                }
                $existing = $tableMap[$sourceKey];
                $currentDeferred = $existing->getDeferredColumns() ?? [];
                $currentDeferred[] = [
                    'column' => $edge['source_column'],
                    'reference_table' => $edge['target'],
                    'reference_column' => $edge['target_column'],
                ];
                $tableMap[$sourceKey] = $existing->withDeferredColumns($currentDeferred);
            }
        }

        $ordered = [];
        foreach ($sortedKeys as $key) {
            if (isset($tableMap[$key])) {
                $ordered[] = $tableMap[$key];
            }
        }

        if ($this->sampleReport !== null) {
            $this->sampleReport->clear();
        }

        $total = count($ordered);
        $current = 0;
        foreach ($ordered as $config) {
            $current++;
            $this->doExportTable($config, $current, $total);
        }

        $this->writeSampleReport();
    }

    /**
     * Ход выборки по критериям — {data_dir}/analysis/sample-report.json: корзины, строки,
     * усечения. По нему видно, какой вид данных в дамп не попал, без единого значения ПД.
     */
    private function writeSampleReport(): void
    {
        $report = $this->sampleReport;
        if ($report === null || $report->isEmpty()) {
            return;
        }
        $dir = $this->projectDir . '/' . $this->dataDir() . '/analysis';
        $this->ensureDirectoryExists($dir);
        $payload = $report->toArray();
        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $path = $dir . '/sample-report.json';
        $this->fileSystem->write($path, ($json === false ? '{}' : $json) . "\n");

        $summary = $payload['summary'];
        $this->logger->info(sprintf(
            'sample-report.json: таблиц %d, пустых корзин %d, усечено потолком %d — %s',
            $summary['tables'],
            $summary['empty_buckets'],
            $summary['truncated_by_cap'],
            $path
        ));
    }

    private function guardProd(): void
    {
        if ($this->productionGuard !== null) {
            $this->productionGuard->ensureSafeForExport($this->allowProdExport);
        }
    }

    private function doExportTable(TableConfig $config, ?int $current, ?int $total): void
    {
        $prefix = ($current !== null && $total !== null) ? "[{$current}/{$total}] " : '';
        $tableName = $config->getFullTableName();

        $filename = $this->buildDumpPath($config);
        $tmpPath = $filename . '.tmp.' . bin2hex(random_bytes(4));

        try {
            $this->ensureDirectoryExists(dirname($filename));

            // Streaming-выборка
            $rows = $this->dataFetcher->iterate($config);

            // Faker (если настроен) — применяется ПОСЛЕ выборки.
            // Faker::apply работает с массивом, поэтому при наличии faker мы
            // буферизуем по batchSize (через генератор SqlGenerator + InsertGenerator)
            $fakerTableConfig = $this->dumpConfig->getFakerConfig()->getTableFaker(
                $config->getSchema(),
                $config->getTable()
            );
            if ($fakerTableConfig !== null) {
                $rows = $this->applyFakerStreaming(
                    $config->getSchema(),
                    $config->getTable(),
                    $fakerTableConfig,
                    $rows
                );
            }

            $fetchQuery = $this->dataFetcher->getLastQuery();

            // Пишем сначала в .tmp, по успеху — rename в финальный путь.
            $first = true;
            foreach ($this->sqlGenerator->generateChunks($config, $rows, $fetchQuery, null) as $chunk) {
                if ($first) {
                    $this->fileSystem->write($tmpPath, $chunk);
                    $first = false;
                } else {
                    $this->fileSystem->append($tmpPath, $chunk);
                }
            }

            // Атомарный rename .tmp → .sql
            if (file_exists($tmpPath)) {
                if (!@rename($tmpPath, $filename)) {
                    throw new \RuntimeException("Не удалось переименовать {$tmpPath} → {$filename}");
                }
            }

            $size = $this->fileSystem->getFileSize($filename);
            $this->logger->info("{$prefix}{$tableName} ... OK ({$this->formatBytes($size)})");
        } catch (\Exception $e) {
            // Cleanup незавершённого файла, чтобы импорт не подобрал битый дамп
            if (file_exists($tmpPath)) {
                @unlink($tmpPath);
            }
            $this->logger->error("{$prefix}{$tableName} ... ERROR: " . $this->sanitizeErrorMessage($e));
            throw ExportFailedException::fromException($tableName, $e);
        }
    }

    /**
     * Стримовая обёртка над Faker::apply.
     *
     * Faker работает с массивом строк, чтобы согласовать значения внутри row;
     * мы передаём небольшими батчами по 500 строк, сохраняя константное потребление памяти.
     *
     * @param array<string, string> $fakerTableConfig
     * @param iterable<array<string, mixed>> $rows
     * @return \Generator<int, array<string, mixed>>
     */
    private function applyFakerStreaming(string $schema, string $table, array $fakerTableConfig, iterable $rows): \Generator
    {
        $buffer = [];
        $bufferSize = 500;
        foreach ($rows as $row) {
            $buffer[] = $row;
            if (count($buffer) >= $bufferSize) {
                foreach ($this->faker->apply($schema, $table, $fakerTableConfig, $buffer) as $r) {
                    yield $r;
                }
                $buffer = [];
            }
        }
        if (!empty($buffer)) {
            foreach ($this->faker->apply($schema, $table, $fakerTableConfig, $buffer) as $r) {
                yield $r;
            }
        }
    }

    private function buildDumpPath(TableConfig $config): string
    {
        $connectionName = $config->getConnectionName();
        $dumpsDir = $this->dataDir() . '/' . DumpConfig::DUMPS_DIR;

        if ($connectionName !== null) {
            return $this->projectDir . "/{$dumpsDir}/{$connectionName}/{$config->getSchema()}/{$config->getTable()}.sql";
        }

        return $this->projectDir . "/{$dumpsDir}/{$config->getSchema()}/{$config->getTable()}.sql";
    }

    /**
     * Базовый каталог данных (относительный): из store, иначе DbdumpConfigStore::DEFAULT_DATA_DIR.
     */
    private function dataDir(): string
    {
        return $this->configStore !== null
            ? $this->configStore->getDataDir($this->projectDir)
            : DbdumpConfigStore::DEFAULT_DATA_DIR;
    }

    private function ensureDirectoryExists(string $directory): void
    {
        if (!$this->fileSystem->exists($directory)) {
            $this->fileSystem->createDirectory($directory);
        }
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 2) . ' MB';
        }
        if ($bytes >= 1024) {
            return round($bytes / 1024, 2) . ' KB';
        }
        return $bytes . ' bytes';
    }

    private function sanitizeErrorMessage(\Throwable $e): string
    {
        return \Timbrs\DatabaseDumps\Util\ErrorMessageSanitizer::sanitize($e->getMessage());
    }
}
