<?php

namespace Timbrs\DatabaseDumps\Service\Importer;

use Timbrs\DatabaseDumps\Config\DumpConfig;
use Timbrs\DatabaseDumps\Contract\ConnectionRegistryInterface;
use Timbrs\DatabaseDumps\Contract\DatabaseConnectionInterface;
use Timbrs\DatabaseDumps\Contract\FileSystemInterface;
use Timbrs\DatabaseDumps\Contract\LoggerInterface;
use Timbrs\DatabaseDumps\Exception\ImportFailedException;
use Timbrs\DatabaseDumps\Platform\PlatformFactory;
use Timbrs\DatabaseDumps\Service\Ai\DbdumpConfigStore;
use Timbrs\DatabaseDumps\Service\Graph\TableDependencyResolver;
use Timbrs\DatabaseDumps\Service\Parser\SqlParser;
use Timbrs\DatabaseDumps\Service\Security\ProductionGuard;

/**
 * Импорт SQL дампов в БД.
 *
 * Особенности:
 * - ProductionGuard блокирует импорт на prod (см. ensureSafeForImport).
 * - FOREIGN_KEY_CHECKS управляется через Platform.disableForeignKeysSql, в try/finally.
 * - Перед чтением файла путь нормализуется через realpath и проверяется на
 *   принадлежность projectDir (защита от symlink-traversal).
 * - Сообщения об ошибках санитизируются (обрезаются после VALUES) — защита
 *   от утечки данных импорта в логи.
 */
class DatabaseImporter
{
    /** Суффиксы каталогов хуков относительно data_dir (полный путь: {data_dir}/before_exec). */
    public const BEFORE_EXEC_DIR = 'before_exec';
    public const AFTER_EXEC_DIR = 'after_exec';

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
    /** @var SchemaValidator|null */
    private $schemaValidator;

    /** @var DbdumpConfigStore|null */
    private $configStore;

    /** @var bool */
    private $ignoreSchemaMismatch = false;

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
        TableDependencyResolver $dependencyResolver,
        SchemaValidator $schemaValidator = null,
        DbdumpConfigStore $configStore = null
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
        $this->schemaValidator = $schemaValidator;
        $this->configStore = $configStore;
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

    public function setIgnoreSchemaMismatch(bool $ignore): void
    {
        $this->ignoreSchemaMismatch = $ignore;
    }

    /**
     * @throws ImportFailedException
     */
    public function import(
        bool $skipBefore = false,
        bool $skipAfter = false,
        ?string $schemaFilter = null,
        ?string $connectionFilter = null
    ): void {
        $this->productionGuard->ensureSafeForImport();

        $connectionNames = $this->resolveConnectionNames($connectionFilter);

        foreach ($connectionNames as $connName) {
            $this->importForConnection($connName, $skipBefore, $skipAfter, $schemaFilter);
        }
    }

    private function importForConnection(
        ?string $connectionName,
        bool $skipBefore,
        bool $skipAfter,
        ?string $schemaFilter
    ): void {
        $label = $connectionName ?? 'default';
        $this->logger->info("Импорт подключения: {$label}");

        $this->transactionManager->transaction(function () use ($connectionName, $skipBefore, $skipAfter, $schemaFilter) {
            if (!$skipBefore && $connectionName === null) {
                $this->logger->info('1. Выполнение before_exec скриптов');
                $this->scriptExecutor->executeScripts($this->projectDir . '/' . $this->dataDir() . '/' . self::BEFORE_EXEC_DIR);
            }

            $this->logger->info('2. Импорт SQL дампов');
            $this->importDumps($schemaFilter, $connectionName);

            if (!$skipAfter && $connectionName === null) {
                $this->logger->info('3. Выполнение after_exec скриптов');
                $this->scriptExecutor->executeScripts($this->projectDir . '/' . $this->dataDir() . '/' . self::AFTER_EXEC_DIR);
            }
        }, $connectionName);
    }

    /**
     * @return array<string|null>
     */
    private function resolveConnectionNames(?string $connectionFilter): array
    {
        if ($connectionFilter === ConnectionRegistryInterface::CONNECTION_ALL) {
            $names = [null];
            foreach (array_keys($this->dumpConfig->getConnectionConfigs()) as $connName) {
                $names[] = $connName;
            }
            return $names;
        }

        if ($connectionFilter !== null) {
            return [$connectionFilter];
        }

        return [null];
    }

    private function importDumps(?string $schemaFilter, ?string $connectionName): void
    {
        $dumpsPath = $this->buildDumpsPath($connectionName);

        if (!$this->fileSystem->isDirectory($dumpsPath)) {
            throw ImportFailedException::dumpsNotFound($dumpsPath);
        }

        $files = $this->fileSystem->findFiles($dumpsPath, '*.sql');
        if (empty($files)) {
            throw ImportFailedException::noDumpsFound($dumpsPath);
        }

        $filteredFiles = $this->filterFilesBySchema($files, $schemaFilter);
        $filteredFiles = $this->sortFilesByDependencies($filteredFiles, $connectionName);

        $connection = $this->registry->getConnection($connectionName);
        $platform = $this->registry->getPlatform($connectionName);
        $platformName = PlatformFactory::canonicalize($connection->getPlatformName());
        $backslashEscapes = $platformName === PlatformFactory::MYSQL;

        $disableFkSql = $platform->disableForeignKeysSql();
        $enableFkSql = $platform->enableForeignKeysSql();

        // Внимание: на MySQL ALTER TABLE ... AUTO_INCREMENT (в SequenceGenerator)
        // выполняет неявный COMMIT внутри транзакции. То же касается TRUNCATE.
        // Мы заменили TRUNCATE на DELETE FROM в MySqlPlatform, но AUTO_INCREMENT
        // всё ещё DDL. Документировано как ограничение: атомарность impport
        // на MySQL не гарантируется при наличии SequenceGenerator-операций.
        if ($disableFkSql !== null) {
            $connection->executeStatement($disableFkSql);
        }

        $total = count($filteredFiles);
        $current = 0;

        try {
            foreach ($filteredFiles as $file) {
                $current++;
                $this->importDumpFile($file, $current, $total, $connection, $connectionName, $backslashEscapes);
            }
        } finally {
            if ($enableFkSql !== null) {
                try {
                    $connection->executeStatement($enableFkSql);
                } catch (\Throwable $e) {
                    $this->logger->warning('Не удалось восстановить FK_CHECKS: ' . $e->getMessage());
                }
            }
        }
    }

    /**
     * @param string[] $files
     * @return string[]
     */
    private function sortFilesByDependencies(array $files, ?string $connectionName): array
    {
        $keyToFile = [];
        $tableKeys = [];
        foreach ($files as $filePath) {
            [$schema, $tableName] = $this->extractSchemaAndTable($filePath);
            $key = $schema . '.' . $tableName;
            $keyToFile[$key] = $filePath;
            $tableKeys[] = $key;
        }

        // Тот же второй источник рёбер, что и при выгрузке. Иначе импорт разложит файлы
        // в другом порядке, чем их писали, и разница проявится ровно там, где её труднее
        // всего заметить, — на связях, которых нет в схеме констрейнтом.
        $cascadeByChild = [];
        foreach ($tableKeys as $key) {
            $parts = explode('.', $key, 2);
            if (count($parts) !== 2) {
                continue;
            }
            $raw = $this->dumpConfig->getTableConfig($parts[0], $parts[1]);
            if (is_array($raw) && isset($raw['cascade_from']) && is_array($raw['cascade_from'])) {
                $cascadeByChild[$key] = $raw['cascade_from'];
            }
        }

        $sortedKeys = $this->dependencyResolver->sortForImport(
            $tableKeys,
            $connectionName,
            TableDependencyResolver::cascadeEdges($cascadeByChild)
        );

        $sortedFiles = [];
        $seen = [];
        foreach ($sortedKeys as $key) {
            if (isset($keyToFile[$key]) && !isset($seen[$keyToFile[$key]])) {
                $sortedFiles[] = $keyToFile[$key];
                $seen[$keyToFile[$key]] = true;
            }
        }
        // Файлы, не попавшие в FK-граф — в конец
        foreach ($files as $f) {
            if (!isset($seen[$f])) {
                $sortedFiles[] = $f;
                $seen[$f] = true;
            }
        }
        return $sortedFiles;
    }

    /**
     * @param string[] $files
     * @return string[]
     */
    private function filterFilesBySchema(array $files, ?string $schemaFilter): array
    {
        if ($schemaFilter === null) {
            return $files;
        }

        return array_values(array_filter($files, function (string $filePath) use ($schemaFilter) {
            [$schema] = $this->extractSchemaAndTable($filePath);
            return $schema === $schemaFilter;
        }));
    }

    /**
     * Извлечь {schema, table} из пути database/dumps/{schema}/{table}.sql.
     * Поддерживает оба разделителя для кросс-платформенности.
     *
     * @return array{0: string, 1: string}
     */
    private function extractSchemaAndTable(string $filePath): array
    {
        $normalized = str_replace('\\', '/', $filePath);
        $parts = explode('/', $normalized);
        $count = count($parts);
        $schema = $count >= 2 ? $parts[$count - 2] : '';
        $tableName = basename($filePath, '.sql');
        return [$schema, $tableName];
    }

    private function buildDumpsPath(?string $connectionName): string
    {
        $dumpsDir = $this->projectDir . '/' . $this->dataDir() . '/' . DumpConfig::DUMPS_DIR;
        if ($connectionName !== null) {
            return $dumpsDir . '/' . $connectionName;
        }
        return $dumpsDir;
    }

    private function importDumpFile(
        string $filePath,
        int $current,
        int $total,
        DatabaseConnectionInterface $connection,
        ?string $connectionName,
        bool $backslashEscapes = false
    ): void {
        [$schema, $tableName] = $this->extractSchemaAndTable($filePath);
        $fullName = "{$schema}.{$tableName}";
        $this->logger->info("[{$current}/{$total}] {$fullName} ... ");

        try {
            $sql = $this->fileSystem->read($filePath);

            if ($this->schemaValidator !== null) {
                $dumpColumns = $this->parser->parseColumnList($sql);
                if ($dumpColumns !== null) {
                    $validation = $this->schemaValidator->validate($schema, $tableName, $dumpColumns, $connectionName);
                    if (!$validation->isValid()) {
                        $this->logger->warning(
                            "[{$current}/{$total}] {$fullName} — расхождение схемы: " . $validation->getDescription()
                        );
                        if (!$this->ignoreSchemaMismatch) {
                            $this->logger->warning(
                                "[{$current}/{$total}] {$fullName} — пропущен. Используйте --ignore-schema-mismatch"
                            );
                            return;
                        }
                    }
                }
            }

            $statements = $this->parser->parseFile($sql, $backslashEscapes);

            foreach ($statements as $statement) {
                if (trim($statement) === '') {
                    continue;
                }
                $connection->executeStatement($statement);
            }

            $this->logger->info("[{$current}/{$total}] {$fullName} ... OK");
        } catch (\Throwable $e) {
            $this->logger->error(
                "[{$current}/{$total}] {$fullName} ... ERROR: " . $this->sanitizeMessage($e->getMessage())
            );
            throw $e;
        }
    }

    private function sanitizeMessage(string $msg): string
    {
        return \Timbrs\DatabaseDumps\Util\ErrorMessageSanitizer::sanitize($msg);
    }
}
