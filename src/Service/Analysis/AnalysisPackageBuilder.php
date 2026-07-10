<?php

namespace Timbrs\DatabaseDumps\Service\Analysis;

use Timbrs\DatabaseDumps\Contract\ConnectionRegistryInterface;
use Timbrs\DatabaseDumps\Contract\FileSystemInterface;
use Timbrs\DatabaseDumps\Contract\LoggerInterface;
use Timbrs\DatabaseDumps\Platform\PlatformFactory;
use Timbrs\DatabaseDumps\Service\Ai\DbdumpConfigStore;
use Timbrs\DatabaseDumps\Service\ConfigGenerator\ColumnProfile;
use Timbrs\DatabaseDumps\Service\ConfigGenerator\ColumnStatisticsInspector;
use Timbrs\DatabaseDumps\Service\ConfigGenerator\ServiceTableFilter;
use Timbrs\DatabaseDumps\Service\ConfigGenerator\TableInspector;
use Timbrs\DatabaseDumps\Service\Graph\TableDependencyResolver;

/**
 * Готовит пакет для анализа кода хоста агентом OPENCODE (dbdump-mapper):
 *  - .opencode/agents/dbdump-mapper.md      — готовый агент (read-only код + запись в out/)
 *  - .opencode/commands/dbdump-map.md       — слэш-команда (опционально)
 *  - database/analysis/schema_inventory.json — вход (схемы/таблицы/колонки/FK/профили, БЕЗ значений данных)
 *  - database/analysis/output_schema.json    — JSON-контракт вывода
 *  - database/analysis/RUN.md                — инструкции запуска
 *  - database/analysis/out/                  — каталог для результатов агента
 *
 * Сам прогон OPENCODE пользователь запускает вручную по RUN.md; модуль его не вызывает.
 * Sample-значения PII в инвентарь НЕ кладутся (только типы/кардинальность).
 */
class AnalysisPackageBuilder
{
    /** Суффиксы каталогов анализа относительно data_dir (полный путь: {data_dir}/analysis). */
    public const ANALYSIS_DIR = 'analysis';
    public const OUT_DIR = 'analysis/out';
    public const OPENCODE_AGENTS_DIR = '.opencode/agents';
    public const OPENCODE_COMMANDS_DIR = '.opencode/commands';

    /** @var FileSystemInterface */
    private $fileSystem;

    /** @var ConnectionRegistryInterface */
    private $registry;

    /** @var TableInspector */
    private $inspector;

    /** @var ServiceTableFilter */
    private $filter;

    /** @var TableDependencyResolver */
    private $dependencyResolver;

    /** @var ColumnStatisticsInspector */
    private $statisticsInspector;

    /** @var LoggerInterface */
    private $logger;

    /** @var string */
    private $projectDir;

    /** @var DbdumpConfigStore|null */
    private $configStore;

    public function __construct(
        FileSystemInterface $fileSystem,
        ConnectionRegistryInterface $registry,
        TableInspector $inspector,
        ServiceTableFilter $filter,
        TableDependencyResolver $dependencyResolver,
        ColumnStatisticsInspector $statisticsInspector,
        LoggerInterface $logger,
        string $projectDir,
        DbdumpConfigStore $configStore = null
    ) {
        $this->fileSystem = $fileSystem;
        $this->registry = $registry;
        $this->inspector = $inspector;
        $this->filter = $filter;
        $this->dependencyResolver = $dependencyResolver;
        $this->statisticsInspector = $statisticsInspector;
        $this->logger = $logger;
        $this->projectDir = rtrim($projectDir, '/\\');
        $this->configStore = $configStore;
    }

    /**
     * Базовый каталог данных (относительный): из store, иначе дефолт 'database'.
     */
    private function dataDir(): string
    {
        return $this->configStore !== null
            ? $this->configStore->getDataDir($this->projectDir)
            : DbdumpConfigStore::DEFAULT_DATA_DIR;
    }

    /**
     * Сформировать пакет. Возвращает список записанных путей и карту пер-схемных
     * инвентарей (для запуска OPENCODE по чанку на схему).
     *
     * @return array{paths: array<int, string>, tables: int, schema_files: array<string, string>}
     */
    public function build(?string $connectionName = null): array
    {
        $dataDir = $this->dataDir();
        $analysisDir = $this->projectDir . '/' . $dataDir . '/' . self::ANALYSIS_DIR;
        $outDir = $this->projectDir . '/' . $dataDir . '/' . self::OUT_DIR;
        $agentsDir = $this->projectDir . '/' . self::OPENCODE_AGENTS_DIR;
        $commandsDir = $this->projectDir . '/' . self::OPENCODE_COMMANDS_DIR;

        foreach ([$analysisDir, $outDir, $agentsDir, $commandsDir] as $dir) {
            $this->ensureDir($dir);
        }

        $inventory = $this->buildInventory($connectionName);
        $tableCount = $this->countTables($inventory);

        $paths = [];

        $jsonFlags = JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

        // Полный инвентарь (обзор / маленькие БД).
        $inventoryJson = json_encode($inventory, $jsonFlags);
        $invPath = $analysisDir . '/schema_inventory.json';
        $this->fileSystem->write($invPath, $inventoryJson === false ? '{}' : $inventoryJson);
        $paths[] = $invPath;

        // Пер-схемный инвентарь — чтобы прогонять OPENCODE по чанку на схему и не
        // переполнять контекст 128k на больших БД (агент получает -f только своей схемы).
        $schemaFiles = [];
        $schemas = isset($inventory['schemas']) && is_array($inventory['schemas']) ? $inventory['schemas'] : [];
        foreach ($schemas as $schemaName => $schemaData) {
            $schemaName = (string) $schemaName;
            // Защита от path traversal: имя схемы из БД используется как часть имени файла.
            if (!preg_match('/^[A-Za-z_][A-Za-z0-9_$]*$/', $schemaName)) {
                $this->logger->warning("Пропущен пер-схемный инвентарь: небезопасное имя схемы '{$schemaName}'");
                continue;
            }
            $schemaInventory = $inventory;
            $schemaInventory['schemas'] = [$schemaName => $schemaData];
            $schemaJson = json_encode($schemaInventory, $jsonFlags);
            $p = $analysisDir . '/schema_inventory.' . $schemaName . '.json';
            $this->fileSystem->write($p, $schemaJson === false ? '{}' : $schemaJson);
            $paths[] = $p;
            $schemaFiles[$schemaName] = $p;
        }

        $contract = $this->loadResource('output_schema.json');
        if ($contract !== null) {
            $p = $analysisDir . '/output_schema.json';
            $this->fileSystem->write($p, $contract);
            $paths[] = $p;
        }

        $agent = $this->loadResource('dbdump-mapper.md');
        if ($agent !== null) {
            $p = $agentsDir . '/dbdump-mapper.md';
            $this->fileSystem->write($p, $agent);
            $paths[] = $p;
        }

        $command = $this->loadResource('dbdump-map.command.md');
        if ($command !== null) {
            $p = $commandsDir . '/dbdump-map.md';
            $this->fileSystem->write($p, $command);
            $paths[] = $p;
        }

        $run = $this->loadResource('RUN.md');
        if ($run !== null) {
            $p = $analysisDir . '/RUN.md';
            $this->fileSystem->write($p, $run);
            $paths[] = $p;
        }

        // .gitkeep, чтобы каталог out/ существовал в репозитории до прогона агента.
        $this->fileSystem->write($outDir . '/.gitkeep', '');

        $this->logger->info(sprintf('Пакет анализа подготовлен: %d таблиц, %d файлов', $tableCount, count($paths)));

        return ['paths' => $paths, 'tables' => $tableCount, 'schema_files' => $schemaFiles];
    }

    /**
     * Построить инвентарь схемы (БЕЗ значений данных).
     *
     * @return array<string, mixed>
     */
    public function buildInventory(?string $connectionName = null): array
    {
        $connection = $this->registry->getConnection($connectionName);
        $platform = PlatformFactory::canonicalize($connection->getPlatformName());

        $graph = $this->dependencyResolver->getDependencyGraph($connectionName);
        $tables = $this->inspector->listTables($connectionName);

        $total = count($tables);
        $this->logger->info(sprintf(
            'Сбор инвентаря БД: %d таблиц (подсчёт строк + профилирование колонок — на больших БД это долго)',
            $total
        ));
        $current = 0;

        $schemas = [];
        foreach ($tables as $tableInfo) {
            $current++;
            $schema = $tableInfo['table_schema'];
            $table = $tableInfo['table_name'];

            if ($this->filter->shouldIgnore($table)) {
                $this->logger->info("[{$current}/{$total}] {$schema}.{$table} ... SKIP (служебная)");
                continue;
            }

            $this->logger->info("[{$current}/{$total}] {$schema}.{$table} ... инвентаризация");

            $rowCount = $this->inspector->countRows($schema, $table, $connectionName);
            $profiles = $this->statisticsInspector->profileTable($schema, $table, $connectionName);

            if (!isset($schemas[$schema])) {
                $schemas[$schema] = ['tables' => []];
            }

            $schemas[$schema]['tables'][$table] = [
                'row_count' => $rowCount,
                'columns' => $this->buildColumns($profiles),
                'foreign_keys' => $this->buildForeignKeys($graph, $schema . '.' . $table),
                'profiles' => $this->buildSafeProfiles($profiles),
            ];
        }

        return [
            'generated_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'database_platform' => $platform,
            'connection' => $connectionName ?? 'default',
            'note' => 'Значения данных (PII) не включены — только типы, кардинальность и категориальность.',
            'schemas' => $schemas,
        ];
    }

    /**
     * @param array<int, ColumnProfile> $profiles
     * @return array<int, array{name: string, type: string, nullable: bool}>
     */
    private function buildColumns(array $profiles): array
    {
        $columns = [];
        foreach ($profiles as $p) {
            $columns[] = [
                'name' => $p->getColumn(),
                'type' => $p->getDataType(),
                'nullable' => $p->isNullable(),
            ];
        }
        return $columns;
    }

    /**
     * Безопасные ключи профиля — только метаданные (тип/кардинальность/категориальность),
     * БЕЗ значений данных. КЛЮЧЕВАЯ ГАРАНТИЯ: PII (top_values и любые иные сырые значения)
     * НЕ уходят во внешний агент OPENCODE.
     *
     * Используется whitelist (а не unset top_values): даже если ColumnProfile::toArray()
     * в будущем начнёт отдавать новые value-несущие ключи, они не утекут в инвентарь.
     */
    private const SAFE_PROFILE_KEYS = [
        'column',
        'data_type',
        'nullable',
        'null_fraction',
        'distinct_count',
        'distinct_capped',
        'categorical',
    ];

    /**
     * Профили только из безопасных метаданных (whitelist). Значения данных (top_values
     * и любые прочие) НЕ выгружаются.
     *
     * @param array<int, ColumnProfile> $profiles
     * @return array<int, array<string, mixed>>
     */
    private function buildSafeProfiles(array $profiles): array
    {
        $result = [];
        foreach ($profiles as $p) {
            $arr = $p->toArray();
            $safe = [];
            foreach (self::SAFE_PROFILE_KEYS as $key) {
                if (array_key_exists($key, $arr)) {
                    $safe[$key] = $arr[$key];
                }
            }
            $result[] = $safe;
        }
        return $result;
    }

    /**
     * @param array<string, array<string, array{source_column: string, target_column: string}>> $graph
     * @return array<int, array{column: string, references_table: string, references_column: string}>
     */
    private function buildForeignKeys(array $graph, string $tableKey): array
    {
        if (!isset($graph[$tableKey])) {
            return [];
        }
        $fks = [];
        foreach ($graph[$tableKey] as $parentKey => $columns) {
            $fks[] = [
                'column' => $columns['source_column'],
                'references_table' => $parentKey,
                'references_column' => $columns['target_column'],
            ];
        }
        return $fks;
    }

    /**
     * @param array<string, mixed> $inventory
     */
    private function countTables(array $inventory): int
    {
        $count = 0;
        if (isset($inventory['schemas']) && is_array($inventory['schemas'])) {
            foreach ($inventory['schemas'] as $schema) {
                if (isset($schema['tables']) && is_array($schema['tables'])) {
                    $count += count($schema['tables']);
                }
            }
        }
        return $count;
    }

    private function ensureDir(string $dir): void
    {
        if (!$this->fileSystem->exists($dir)) {
            $this->fileSystem->createDirectory($dir);
        }
    }

    /**
     * @return string|null
     */
    protected function loadResource(string $name)
    {
        $path = dirname(__DIR__, 2) . '/Resources/opencode/' . $name;
        if (!is_file($path)) {
            return null;
        }
        $content = @file_get_contents($path);
        return $content === false ? null : $content;
    }
}
