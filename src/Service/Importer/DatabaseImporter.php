<?php

namespace Timbrs\DatabaseDumps\Service\Importer;

use Timbrs\DatabaseDumps\Config\DumpConfig;
use Timbrs\DatabaseDumps\Contract\ConnectionRegistryInterface;
use Timbrs\DatabaseDumps\Contract\FileSystemInterface;
use Timbrs\DatabaseDumps\Contract\LoggerInterface;
use Timbrs\DatabaseDumps\Exception\ImportFailedException;
use Timbrs\DatabaseDumps\Platform\PlatformFactory;
use Timbrs\DatabaseDumps\Service\Graph\TableDependencyResolver;
use Timbrs\DatabaseDumps\Service\Parser\SqlParser;
use Timbrs\DatabaseDumps\Service\Security\ProductionGuard;

/**
 * Импорт SQL дампов в БД
 */
class DatabaseImporter
{
    public const BEFORE_EXEC_DIR = 'database/before_exec';
    public const AFTER_EXEC_DIR = 'database/after_exec';

    /** @var ConnectionRegistryInterface */
    private $registry;
    /** @var DumpConfig */
    private $dumpConfig;
    /** @var FileSystemInterface */
    private $fileSystem;
    /** @var ProductionGuard */
    private $productionGuard;
    /** @var TransactionManager */
    private $transactionManager;
    /** @var ScriptExecutor */
    private $scriptExecutor;
    /** @var SqlParser */
    private $parser;
    /** @var LoggerInterface */
    private $logger;
    /** @var string */
    private $projectDir;

    /** @var TableDependencyResolver */
    private $dependencyResolver;

    public function __construct(
        ConnectionRegistryInterface $registry,
        DumpConfig $dumpConfig,
        FileSystemInterface $fileSystem,
        ProductionGuard $productionGuard,
        TransactionManager $transactionManager,
        ScriptExecutor $scriptExecutor,
        SqlParser $parser,
        LoggerInterface $logger,
        string $projectDir,
        TableDependencyResolver $dependencyResolver
    ) {
        $this->registry = $registry;
        $this->dumpConfig = $dumpConfig;
        $this->fileSystem = $fileSystem;
        $this->productionGuard = $productionGuard;
        $this->transactionManager = $transactionManager;
        $this->scriptExecutor = $scriptExecutor;
        $this->parser = $parser;
        $this->logger = $logger;
        $this->projectDir = $projectDir;
        $this->dependencyResolver = $dependencyResolver;
    }

    /**
     * Импортировать дампы
     *
     * @param bool $skipBefore Пропустить before_exec скрипты
     * @param bool $skipAfter Пропустить after_exec скрипты
     * @param string|null $schemaFilter Фильтр по схеме
     * @param string|null $connectionFilter Фильтр по подключению (null = дефолтное, 'all' = все)
     * @throws ImportFailedException
     */
    public function import(
        bool $skipBefore = false,
        bool $skipAfter = false,
        ?string $schemaFilter = null,
        ?string $connectionFilter = null
    ): void {
        // 1. Проверка окружения (защита от prod)
        $this->productionGuard->ensureSafeForImport();

        // 2. Определить подключения для импорта
        $connectionNames = $this->resolveConnectionNames($connectionFilter);

        // 3. Per-connection import с транзакциями
        foreach ($connectionNames as $connName) {
            $this->importForConnection($connName, $skipBefore, $skipAfter, $schemaFilter);
        }
    }

    /**
     * Импорт для одного подключения
     */
    private function importForConnection(
        ?string $connectionName,
        bool $skipBefore,
        bool $skipAfter,
        ?string $schemaFilter
    ): void {
        $label = $connectionName ?? 'default';
        $this->logger->info("Импорт подключения: {$label}");

        $this->transactionManager->transaction(function () use ($connectionName, $skipBefore, $skipAfter, $schemaFilter) {
            // Before exec скрипты — на дефолтном подключении
            if (!$skipBefore && $connectionName === null) {
                $this->logger->info('1. Выполнение before_exec скриптов');
                $this->scriptExecutor->executeScripts($this->projectDir . '/' . self::BEFORE_EXEC_DIR);
            }

            // Импорт дампов
            $this->logger->info('2. Импорт SQL дампов');
            $this->importDumps($schemaFilter, $connectionName);

            // After exec скрипты — на дефолтном подключении
            if (!$skipAfter && $connectionName === null) {
                $this->logger->info('3. Выполнение after_exec скриптов');
                $this->scriptExecutor->executeScripts($this->projectDir . '/' . self::AFTER_EXEC_DIR);
            }
        }, $connectionName);
    }

    /**
     * Определить список подключений для импорта
     *
     * @return array<string|null>
     */
    private function resolveConnectionNames(?string $connectionFilter): array
    {
        if ($connectionFilter === ConnectionRegistryInterface::CONNECTION_ALL) {
            $names = [null]; // дефолтное
            foreach (array_keys($this->dumpConfig->getConnectionConfigs()) as $connName) {
                $names[] = $connName;
            }
            return $names;
        }

        if ($connectionFilter !== null) {
            return [$connectionFilter];
        }

        return [null]; // дефолтное
    }

    /**
     * Импортировать дампы из директории
     */
    private function importDumps(?string $schemaFilter, ?string $connectionName): void
    {
        $dumpsPath = $this->buildDumpsPath($connectionName);

        if (!$this->fileSystem->isDirectory($dumpsPath)) {
            throw ImportFailedException::dumpsNotFound($dumpsPath);
        }

        // Поиск всех .sql файлов
        $files = $this->fileSystem->findFiles($dumpsPath, '*.sql');

        if (empty($files)) {
            throw ImportFailedException::noDumpsFound($dumpsPath);
        }

        // Сортировка для предсказуемого порядка (фолбэк)
        sort($files);

        // Фильтрация по схеме
        $filteredFiles = $this->filterFilesBySchema($files, $schemaFilter);

        // Топологическая сортировка по FK зависимостям
        $filteredFiles = $this->sortFilesByDependencies($filteredFiles, $connectionName);

        $total = count($filteredFiles);
        $current = 0;

        $connection = $this->registry->getConnection($connectionName);
        $platformName = $connection->getPlatformName();
        $backslashEscapes = $platformName === PlatformFactory::MYSQL
            || $platformName === PlatformFactory::MARIADB;

        $isMysql = $platformName === PlatformFactory::MYSQL
            || $platformName === PlatformFactory::MARIADB;

        if ($isMysql) {
            $connection->executeStatement('SET FOREIGN_KEY_CHECKS=0');
        }

        try {
            foreach ($filteredFiles as $file) {
                $current++;
                $this->importDumpFile($file, $current, $total, $connection, $backslashEscapes);
            }
        } finally {
            if ($isMysql) {
                $connection->executeStatement('SET FOREIGN_KEY_CHECKS=1');
            }
        }
    }

    /**
     * Отсортировать файлы по FK зависимостям (родители первыми).
     *
     * При циклических зависимостях — фолбэк на исходный порядок.
     *
     * @param string[] $files
     * @return string[]
     */
    private function sortFilesByDependencies(array $files, ?string $connectionName): array
    {
        // Построить маппинг "schema.table" => filePath
        $keyToFile = [];
        $tableKeys = [];
        foreach ($files as $filePath) {
            $pathParts = explode(DIRECTORY_SEPARATOR, $filePath);
            $schema = $pathParts[count($pathParts) - 2] ?? '';
            $tableName = basename($filePath, '.sql');
            $key = $schema . '.' . $tableName;

            $keyToFile[$key] = $filePath;
            $tableKeys[] = $key;
        }

        try {
            $sortedKeys = $this->dependencyResolver->sortForImport($tableKeys, $connectionName);
        } catch (\RuntimeException $e) {
            $this->logger->warning('Циклическая зависимость при сортировке импорта: ' . $e->getMessage() . '. Используется алфавитный порядок.');
            return $files;
        }

        // Переупорядочить файлы по отсортированным ключам
        $sortedFiles = [];
        foreach ($sortedKeys as $key) {
            if (isset($keyToFile[$key])) {
                $sortedFiles[] = $keyToFile[$key];
            }
        }

        // Добавить файлы, не попавшие в ключи (на всякий случай)
        $sortedSet = array_flip($sortedFiles);
        foreach ($files as $file) {
            if (!isset($sortedSet[$file])) {
                $sortedFiles[] = $file;
            }
        }

        return $sortedFiles;
    }

    /**
     * Фильтрация файлов по схеме
     *
     * @param string[] $files
     * @return string[]
     */
    private function filterFilesBySchema(array $files, ?string $schemaFilter): array
    {
        if ($schemaFilter === null) {
            return $files;
        }

        return array_values(array_filter($files, function (string $filePath) use ($schemaFilter) {
            $pathParts = explode(DIRECTORY_SEPARATOR, $filePath);
            $schema = $pathParts[count($pathParts) - 2] ?? '';
            return $schema === $schemaFilter;
        }));
    }

    /**
     * Построить путь к директории дампов
     */
    private function buildDumpsPath(?string $connectionName): string
    {
        if ($connectionName !== null) {
            return $this->projectDir . '/' . DumpConfig::DUMPS_DIR . '/' . $connectionName;
        }

        return $this->projectDir . '/' . DumpConfig::DUMPS_DIR;
    }

    /**
     * Импортировать один файл дампа
     *
     * @param \Timbrs\DatabaseDumps\Contract\DatabaseConnectionInterface $connection
     * @param bool $backslashEscapes
     */
    private function importDumpFile(string $filePath, int $current, int $total, $connection, $backslashEscapes = false): void
    {
        // Извлечение schema из пути: database/dumps/{schema}/{table}.sql
        $pathParts = explode(DIRECTORY_SEPARATOR, $filePath);
        $schema = $pathParts[count($pathParts) - 2] ?? '';
        $tableName = basename($filePath, '.sql');

        $fullName = "{$schema}.{$tableName}";
        $this->logger->info("[{$current}/{$total}] {$fullName} ... ");

        try {
            $sql = $this->fileSystem->read($filePath);
            $statements = $this->parser->parseFile($sql, $backslashEscapes);

            foreach ($statements as $statement) {
                if (!empty(trim($statement))) {
                    $connection->executeStatement($statement);
                }
            }

            $this->logger->info("[{$current}/{$total}] {$fullName} ... OK");
        } catch (\Exception $e) {
            $this->logger->error("[{$current}/{$total}] {$fullName} ... ERROR: " . $e->getMessage());
            throw $e;
        }
    }
}
