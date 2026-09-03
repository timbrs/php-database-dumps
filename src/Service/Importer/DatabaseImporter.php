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
use Timbrs\DatabaseDumps\Service\Db\SafeQueryPolicy;
use Timbrs\DatabaseDumps\Service\Graph\TableDependencyResolver;
use Timbrs\DatabaseDumps\Service\Parser\SqlParser;
use Timbrs\DatabaseDumps\Service\Security\ProductionGuard;
use Timbrs\DatabaseDumps\Service\Validation\Finding;
use Timbrs\DatabaseDumps\Service\Verification\DumpValueReader;

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
 * - Результат — ImportReport: пропущенные таблицы (I-1), расхождение числа строк
 *   с файлом (I-2), отставшие sequence (I-3), нарушенные внешние ключи (I-4).
 *   Раньше «пропущен из-за схемы» был предупреждением в логе и exit 0.
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

    /** @var SafeQueryPolicy|null */
    private $policy;

    /** @var DumpValueReader|null */
    private $valueReader;

    /** @var bool */
    private $ignoreSchemaMismatch = false;

    /** @var ImportReport */
    private $report;

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
        DbdumpConfigStore $configStore = null,
        SafeQueryPolicy $policy = null,
        DumpValueReader $valueReader = null
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
        $this->policy = $policy;
        $this->valueReader = $valueReader;
        $this->report = new ImportReport();
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

    /** Отчёт последнего импорта (в том числе прерванного исключением). */
    public function getReport(): ImportReport
    {
        return $this->report;
    }

    /**
     * @throws ImportFailedException
     */
    public function import(
        bool $skipBefore = false,
        bool $skipAfter = false,
        ?string $schemaFilter = null,
        ?string $connectionFilter = null
    ): ImportReport {
        $this->productionGuard->ensureSafeForImport();
        $this->report = new ImportReport();

        $connectionNames = $this->resolveConnectionNames($connectionFilter);

        // Профиль сессии import: без statement_timeout — TRUNCATE и INSERT ждут столько, сколько
        // надо; разведочный таймаут здесь уронил бы заливку большого словаря.
        $previousProfile = $this->policy !== null ? $this->policy->getProfile() : null;
        if ($this->policy !== null) {
            $this->policy->setProfile(SafeQueryPolicy::PROFILE_IMPORT);
        }
        try {
            foreach ($connectionNames as $connName) {
                $this->importForConnection($connName, $skipBefore, $skipAfter, $schemaFilter);
            }
        } finally {
            if ($this->policy !== null && $previousProfile !== null) {
                $this->policy->setProfile($previousProfile);
            }
        }

        return $this->report;
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

        // Основное подключение внутри пакета — это `null`, а зовут его везде «default»
        // (`$connectionName ?? 'default'` в логах, отчётах и реестре). Снаружи об этом знать
        // неоткуда: и документация, и ранбуки пишут `--import-connection=default`, а импорт шёл
        // искать дампы в подкаталог `dumps/default`, которого не бывает. Имя из `connections:`
        // при этом сильнее: если подключение с таким именем и вправду настроено, берётся оно.
        if ($connectionFilter !== null) {
            $isConfigured = array_key_exists($connectionFilter, $this->dumpConfig->getConnectionConfigs());

            return [$connectionFilter === 'default' && !$isConfigured ? null : $connectionFilter];
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

            if ($platformName === PlatformFactory::POSTGRESQL) {
                $this->checkDeferredConstraints($connection);
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
     * Извлечь {schema, table} из пути {data_dir}/dumps/{schema}/{table}.sql.
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
                            $this->report->add(Finding::error(
                                ImportReport::CODE_SCHEMA_MISMATCH,
                                sprintf('%s пропущен: колонки дампа расходятся со схемой БД — %s', $fullName, $validation->getDescription()),
                                $schema,
                                $tableName,
                                null,
                                false,
                                ['description' => $validation->getDescription()]
                            ));
                            $this->report->tableSkipped();
                            return;
                        }
                        $this->report->add(Finding::warning(
                            ImportReport::CODE_SCHEMA_MISMATCH,
                            sprintf('%s импортирован при расхождении схемы (--ignore-schema-mismatch): %s', $fullName, $validation->getDescription()),
                            $schema,
                            $tableName,
                            null,
                            false,
                            ['description' => $validation->getDescription()]
                        ));
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

            $fileRows = $this->countDumpRows($filePath);
            $this->report->tableImported($fileRows ?? 0);
            if ($fileRows !== null) {
                $this->checkRowCount($connection, $schema, $tableName, $fileRows);
                $this->checkSequences($connection, $schema, $tableName);
            }

            $this->logger->info("[{$current}/{$total}] {$fullName} ... OK");
        } catch (\Throwable $e) {
            if ($this->isForeignKeyViolation($e)) {
                $this->report->add(Finding::error(
                    ImportReport::CODE_FOREIGN_KEY,
                    sprintf('%s: внешний ключ нарушен — в дампе есть строки без родителя: %s', $fullName, $this->sanitizeMessage($e->getMessage())),
                    $schema,
                    $tableName
                ));
            }
            $this->logger->error(
                "[{$current}/{$total}] {$fullName} ... ERROR: " . $this->sanitizeMessage($e->getMessage())
            );
            throw $e;
        }
    }

    /**
     * Число строк в файле дампа; null — ридер не задан или файл недоступен по этому пути
     * (например, файловая система подменена).
     */
    private function countDumpRows(string $filePath): ?int
    {
        if ($this->valueReader === null || !is_file($filePath)) {
            return null;
        }
        try {
            $result = $this->valueReader->scan($filePath, [], function (array $row): void {
            });
        } catch (\Throwable $e) {
            $this->logger->warning('Не удалось посчитать строки дампа ' . basename($filePath) . ': ' . $e->getMessage());

            return null;
        }

        return $result['rows'];
    }

    /**
     * I-2: после заливки в таблице должно быть ровно столько строк, сколько в файле —
     * TRUNCATE перед INSERT это гарантирует, и расхождение значит, что часть INSERT'ов
     * не применилась (или до импорта таблицу наполнил before_exec).
     */
    private function checkRowCount(DatabaseConnectionInterface $connection, string $schema, string $table, int $fileRows): void
    {
        try {
            $rows = $connection->fetchAllAssociative(
                sprintf('SELECT COUNT(*) AS c FROM %s', $this->quoteTable($connection, $schema, $table))
            );
        } catch (\Throwable $e) {
            $this->logger->warning("Не удалось посчитать строки {$schema}.{$table} после импорта: " . $e->getMessage());

            return;
        }
        if ($rows === [] || !isset($rows[0]['c'])) {
            return;
        }
        $inDb = (int) $rows[0]['c'];
        if ($inDb === $fileRows) {
            return;
        }
        $this->report->add(Finding::error(
            ImportReport::CODE_ROW_COUNT,
            sprintf('%s.%s: после импорта в таблице %d строк, в файле дампа %d', $schema, $table, $inDb, $fileRows),
            $schema,
            $table,
            null,
            false,
            ['db_rows' => $inDb, 'dump_rows' => $fileRows]
        ));
    }

    /**
     * I-3 (PostgreSQL): sequence, привязанная к колонке таблицы, должна быть не меньше
     * максимума колонки — иначе первый же INSERT приложения упрётся в дубликат ключа.
     */
    private function checkSequences(DatabaseConnectionInterface $connection, string $schema, string $table): void
    {
        if (PlatformFactory::canonicalize($connection->getPlatformName()) !== PlatformFactory::POSTGRESQL) {
            return;
        }
        try {
            $owned = $connection->fetchAllAssociative(
                'SELECT a.attname AS column_name, sn.nspname AS seq_schema, s.relname AS seq_name'
                . ' FROM pg_depend d'
                . ' JOIN pg_class s ON s.oid = d.objid AND s.relkind = \'S\''
                . ' JOIN pg_namespace sn ON sn.oid = s.relnamespace'
                . ' JOIN pg_class t ON t.oid = d.refobjid'
                . ' JOIN pg_namespace n ON n.oid = t.relnamespace'
                . ' JOIN pg_attribute a ON a.attrelid = t.oid AND a.attnum = d.refobjsubid'
                . ' WHERE d.deptype IN (\'a\', \'i\') AND n.nspname = :schema AND t.relname = :table',
                ['schema' => $schema, 'table' => $table]
            );
            foreach ($owned as $row) {
                $column = (string) $row['column_name'];
                $sequence = $this->quoteTable($connection, (string) $row['seq_schema'], (string) $row['seq_name']);
                $state = $connection->fetchAllAssociative(
                    sprintf('SELECT last_value, is_called FROM %s', $sequence)
                );
                $max = $connection->fetchAllAssociative(sprintf(
                    'SELECT MAX(%s) AS m FROM %s',
                    $this->quoteIdentifier($connection, $column),
                    $this->quoteTable($connection, $schema, $table)
                ));
                // isset отсеивает и пустую таблицу: MAX() по ней — NULL.
                if ($state === [] || $max === [] || !isset($max[0]['m'])) {
                    continue;
                }
                $lastValue = (int) $state[0]['last_value'];
                $isCalled = filter_var($state[0]['is_called'] ?? false, FILTER_VALIDATE_BOOLEAN);
                $next = $isCalled ? $lastValue + 1 : $lastValue;
                $maxValue = (int) $max[0]['m'];
                if ($next > $maxValue) {
                    continue;
                }
                $this->report->add(Finding::warning(
                    ImportReport::CODE_SEQUENCE,
                    sprintf(
                        '%s.%s.%s: sequence %s.%s выдаст следующим %d, а в колонке уже есть %d — новые строки упрутся в дубликат ключа',
                        $schema,
                        $table,
                        $column,
                        $row['seq_schema'],
                        $row['seq_name'],
                        $next,
                        $maxValue
                    ),
                    $schema,
                    $table,
                    $column,
                    false,
                    ['sequence' => $row['seq_schema'] . '.' . $row['seq_name'], 'next_value' => $next, 'max_value' => $maxValue]
                ));
            }
        } catch (\Throwable $e) {
            $this->logger->warning("Не удалось проверить sequence {$schema}.{$table}: " . $e->getMessage());
        }
    }

    /**
     * I-4 (PostgreSQL): отложенные (DEFERRABLE INITIALLY DEFERRED) внешние ключи проверяются
     * только при COMMIT — форсируем проверку внутри транзакции, чтобы нарушение было
     * названо по таблице, а не «commit failed».
     */
    private function checkDeferredConstraints(DatabaseConnectionInterface $connection): void
    {
        try {
            $deferred = $connection->fetchAllAssociative(
                'SELECT COUNT(*) AS c FROM pg_constraint WHERE contype = \'f\' AND condeferrable'
            );
        } catch (\Throwable $e) {
            $this->logger->warning('Не удалось прочитать список отложенных констрейнтов: ' . $e->getMessage());

            return;
        }
        if ($deferred === [] || (int) ($deferred[0]['c'] ?? 0) === 0) {
            return;
        }
        try {
            $connection->executeStatement('SET CONSTRAINTS ALL IMMEDIATE');
        } catch (\Throwable $e) {
            $this->report->add(Finding::error(
                ImportReport::CODE_FOREIGN_KEY,
                'отложенный внешний ключ нарушен — в дампе есть строки без родителя: ' . $this->sanitizeMessage($e->getMessage())
            ));
            throw $e;
        }
    }

    private function isForeignKeyViolation(\Throwable $e): bool
    {
        for ($current = $e; $current !== null; $current = $current->getPrevious()) {
            if (method_exists($current, 'getSQLState') && (string) $current->getSQLState() === '23503') {
                return true;
            }
            if ($current instanceof \PDOException && isset($current->errorInfo[0]) && (string) $current->errorInfo[0] === '23503') {
                return true;
            }
            if ((string) $current->getCode() === '23503' || (int) $current->getCode() === 1452) {
                return true;
            }
            if (preg_match('/violates foreign key constraint|foreign key constraint fails|SQLSTATE\[23503\]|ORA-02291/i', $current->getMessage()) === 1) {
                return true;
            }
        }

        return false;
    }

    private function quoteTable(DatabaseConnectionInterface $connection, string $schema, string $table): string
    {
        return $this->quoteIdentifier($connection, $schema) . '.' . $this->quoteIdentifier($connection, $table);
    }

    private function quoteIdentifier(DatabaseConnectionInterface $connection, string $identifier): string
    {
        $quote = PlatformFactory::canonicalize($connection->getPlatformName()) === PlatformFactory::MYSQL ? '`' : '"';

        return $quote . str_replace($quote, $quote . $quote, $identifier) . $quote;
    }

    private function sanitizeMessage(string $msg): string
    {
        return \Timbrs\DatabaseDumps\Util\ErrorMessageSanitizer::sanitize($msg);
    }
}
