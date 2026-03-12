<?php

namespace Timbrs\DatabaseDumps\Service\Dumper;

use Timbrs\DatabaseDumps\Config\DumpConfig;
use Timbrs\DatabaseDumps\Config\TableConfig;
use Timbrs\DatabaseDumps\Contract\FakerInterface;
use Timbrs\DatabaseDumps\Contract\FileSystemInterface;
use Timbrs\DatabaseDumps\Contract\LoggerInterface;
use Timbrs\DatabaseDumps\Exception\ExportFailedException;
use Timbrs\DatabaseDumps\Service\Generator\SqlGenerator;
use Timbrs\DatabaseDumps\Service\Graph\TableDependencyResolver;

/**
 * Экспорт данных из БД в SQL дампы
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

    public function __construct(
        DataFetcher $dataFetcher,
        SqlGenerator $sqlGenerator,
        FileSystemInterface $fileSystem,
        LoggerInterface $logger,
        string $projectDir,
        TableDependencyResolver $dependencyResolver,
        FakerInterface $faker,
        DumpConfig $dumpConfig
    ) {
        $this->dataFetcher = $dataFetcher;
        $this->sqlGenerator = $sqlGenerator;
        $this->fileSystem = $fileSystem;
        $this->logger = $logger;
        $this->projectDir = $projectDir;
        $this->dependencyResolver = $dependencyResolver;
        $this->faker = $faker;
        $this->dumpConfig = $dumpConfig;
    }

    /**
     * Экспортировать таблицу
     */
    public function exportTable(TableConfig $config): void
    {
        $this->doExportTable($config, null, null);
    }

    /**
     * Экспортировать все таблицы
     *
     * @param array<TableConfig> $tables
     */
    public function exportAll(array $tables): void
    {
        if (empty($tables)) {
            return;
        }

        // Sort by FK dependencies (parents first)
        $tableKeys = array_map(function (TableConfig $t) {
            return $t->getFullTableName();
        }, $tables);

        $connectionName = $tables[0]->getConnectionName();

        try {
            $sortedKeys = $this->dependencyResolver->sortForExport($tableKeys, $connectionName);
            $tableMap = [];
            foreach ($tables as $t) {
                $tableMap[$t->getFullTableName()] = $t;
            }
            $tables = [];
            foreach ($sortedKeys as $key) {
                if (isset($tableMap[$key])) {
                    $tables[] = $tableMap[$key];
                }
            }
        } catch (\RuntimeException $e) {
            // Cycle detected - fall back to original order, log warning
            $this->logger->warning('Цикл FK зависимостей, используется исходный порядок: ' . $e->getMessage());
        }

        $total = count($tables);
        $current = 0;

        foreach ($tables as $config) {
            $current++;
            $this->doExportTable($config, $current, $total);
        }
    }

    /**
     * @param int|null $current Номер текущей таблицы (null = одиночный экспорт)
     * @param int|null $total Общее количество таблиц
     */
    private function doExportTable(TableConfig $config, ?int $current, ?int $total): void
    {
        $prefix = ($current !== null && $total !== null)
            ? "[{$current}/{$total}] "
            : '';
        $tableName = $config->getFullTableName();

        try {
            // 1. Загрузка данных
            $rows = $this->dataFetcher->fetch($config);

            // 2. Применение фейкера (замена ПД)
            $fakerTableConfig = $this->dumpConfig->getFakerConfig()->getTableFaker(
                $config->getSchema(),
                $config->getTable()
            );
            if ($fakerTableConfig !== null) {
                $rows = $this->faker->apply(
                    $config->getSchema(),
                    $config->getTable(),
                    $fakerTableConfig,
                    $rows
                );
            }

            // 3. Потоковая генерация SQL и запись на диск
            $filename = $this->buildDumpPath($config);
            $this->ensureDirectoryExists(dirname($filename));

            $fetchQuery = $this->dataFetcher->getLastQuery();

            $first = true;
            foreach ($this->sqlGenerator->generateChunks($config, $rows, $fetchQuery) as $chunk) {
                if ($first) {
                    $this->fileSystem->write($filename, $chunk);
                    $first = false;
                } else {
                    $this->fileSystem->append($filename, $chunk);
                }
            }

            $size = $this->fileSystem->getFileSize($filename);
            $this->logger->info("{$prefix}{$tableName} ... OK ({$this->formatBytes($size)})");
        } catch (\Exception $e) {
            $this->logger->error("{$prefix}{$tableName} ... ERROR: " . $e->getMessage());
            throw ExportFailedException::fromException($tableName, $e);
        }
    }

    /**
     * Построить путь к dump-файлу
     *
     * Дефолтное подключение: database/dumps/{schema}/{table}.sql
     * Именованное подключение: database/dumps/{connection}/{schema}/{table}.sql
     */
    private function buildDumpPath(TableConfig $config): string
    {
        $connectionName = $config->getConnectionName();

        $dumpsDir = DumpConfig::DUMPS_DIR;

        if ($connectionName !== null) {
            return $this->projectDir . "/{$dumpsDir}/{$connectionName}/{$config->getSchema()}/{$config->getTable()}.sql";
        }

        return $this->projectDir . "/{$dumpsDir}/{$config->getSchema()}/{$config->getTable()}.sql";
    }

    /**
     * Убедиться что директория существует
     */
    private function ensureDirectoryExists(string $directory): void
    {
        if (!$this->fileSystem->exists($directory)) {
            $this->fileSystem->createDirectory($directory);
        }
    }

    /**
     * Форматировать байты в читаемый формат
     */
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
}
