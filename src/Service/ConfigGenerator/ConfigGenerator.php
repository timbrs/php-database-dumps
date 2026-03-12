<?php

namespace Timbrs\DatabaseDumps\Service\ConfigGenerator;

use Timbrs\DatabaseDumps\Config\DumpConfig;
use Timbrs\DatabaseDumps\Config\TableConfig;
use Timbrs\DatabaseDumps\Contract\ConnectionRegistryInterface;
use Timbrs\DatabaseDumps\Contract\FileSystemInterface;
use Timbrs\DatabaseDumps\Contract\LoggerInterface;
use Timbrs\DatabaseDumps\Service\Faker\PatternDetector;
use Timbrs\DatabaseDumps\Service\Graph\TableDependencyResolver;
use Symfony\Component\Yaml\Yaml;

/**
 * Генератор dump_config.yaml на основе текущей структуры БД
 */
class ConfigGenerator
{
    public const MODE_ALL = 'all';
    public const MODE_SCHEMA = 'schema';
    public const MODE_NEW = 'new';
    public const MODE_TABLE = 'table';

    /** @var TableInspector */
    private $inspector;

    /** @var ServiceTableFilter */
    private $filter;

    /** @var FileSystemInterface */
    private $fileSystem;

    /** @var LoggerInterface */
    private $logger;

    /** @var ConnectionRegistryInterface */
    private $registry;

    /** @var TableDependencyResolver */
    private $dependencyResolver;

    /** @var ConfigSplitter */
    private $configSplitter;

    /** @var PatternDetector */
    private $patternDetector;

    /** @var bool */
    private $cascadeEnabled;

    /** @var bool */
    private $fakerEnabled;

    /** @var bool */
    private $splitBySchema;

    /** @var string */
    private $mode = self::MODE_ALL;

    /** @var string|null */
    private $modeScope;

    public function __construct(
        TableInspector $inspector,
        ServiceTableFilter $filter,
        FileSystemInterface $fileSystem,
        LoggerInterface $logger,
        ConnectionRegistryInterface $registry,
        TableDependencyResolver $dependencyResolver,
        ConfigSplitter $configSplitter,
        PatternDetector $patternDetector,
        bool $cascadeEnabled = true,
        bool $fakerEnabled = true,
        bool $splitBySchema = true
    ) {
        $this->inspector = $inspector;
        $this->filter = $filter;
        $this->fileSystem = $fileSystem;
        $this->logger = $logger;
        $this->registry = $registry;
        $this->dependencyResolver = $dependencyResolver;
        $this->configSplitter = $configSplitter;
        $this->patternDetector = $patternDetector;
        $this->cascadeEnabled = $cascadeEnabled;
        $this->fakerEnabled = $fakerEnabled;
        $this->splitBySchema = $splitBySchema;
    }

    /**
     * @param bool $enabled
     */
    public function setCascadeEnabled(bool $enabled): void
    {
        $this->cascadeEnabled = $enabled;
    }

    /**
     * @param bool $enabled
     */
    public function setFakerEnabled(bool $enabled): void
    {
        $this->fakerEnabled = $enabled;
    }

    /**
     * @param bool $enabled
     */
    public function setSplitBySchema(bool $enabled): void
    {
        $this->splitBySchema = $enabled;
    }

    /**
     * @param string $mode Режим: MODE_ALL, MODE_SCHEMA, MODE_NEW, MODE_TABLE
     * @param string|null $scope Область действия (имя схемы или schema.table)
     */
    public function setMode(string $mode, ?string $scope = null): void
    {
        $this->mode = $mode;
        $this->modeScope = $scope;
    }

    /**
     * Сгенерировать конфигурацию и записать в файл
     *
     * @return array{full: int, partial: int, skipped: int, empty: int}
     */
    public function generate(string $outputPath, int $threshold = 500): array
    {
        $totalStats = ['full' => 0, 'partial' => 0, 'skipped' => 0, 'empty' => 0];
        $config = [];
        $baseConfig = [];
        /** @var array<string, true> $existingTableSet */
        $existingTableSet = [];

        // Загрузка существующего конфига для не-ALL режимов
        if ($this->mode !== self::MODE_ALL) {
            $baseConfig = $this->loadExistingConfigArray($outputPath);

            if ($this->mode === self::MODE_SCHEMA && $this->modeScope !== null) {
                $this->removeSchemaFromConfig($baseConfig, $this->modeScope);
            } elseif ($this->mode === self::MODE_TABLE && $this->modeScope !== null) {
                $parts = explode('.', $this->modeScope, 2);
                $this->removeTableFromConfig($baseConfig, $parts[0], $parts[1]);
            } elseif ($this->mode === self::MODE_NEW) {
                $existingTableSet = $this->buildExistingTableSet($baseConfig);
            }
        }

        // Дефолтное подключение
        $defaultStats = $this->generateForConnection(null, $threshold, $existingTableSet);
        $this->mergeStats($totalStats, $defaultStats['stats']);
        if (!empty($defaultStats[DumpConfig::KEY_FULL_EXPORT])) {
            $config[DumpConfig::KEY_FULL_EXPORT] = $defaultStats[DumpConfig::KEY_FULL_EXPORT];
        }
        if (!empty($defaultStats[DumpConfig::KEY_PARTIAL_EXPORT])) {
            $config[DumpConfig::KEY_PARTIAL_EXPORT] = $defaultStats[DumpConfig::KEY_PARTIAL_EXPORT];
        }
        if (!empty($defaultStats[DumpConfig::KEY_FAKER])) {
            $config[DumpConfig::KEY_FAKER] = $defaultStats[DumpConfig::KEY_FAKER];
        }

        // Дополнительные подключения
        $connectionNames = $this->registry->getNames();
        $defaultName = $this->registry->getDefaultName();
        $connectionConfigs = [];

        foreach ($connectionNames as $connName) {
            if ($connName === $defaultName) {
                continue;
            }

            $this->logger->info("Инспекция подключения: {$connName}");
            $connStats = $this->generateForConnection($connName, $threshold, $existingTableSet);
            $this->mergeStats($totalStats, $connStats['stats']);

            $connConfig = [];
            if (!empty($connStats[DumpConfig::KEY_FULL_EXPORT])) {
                $connConfig[DumpConfig::KEY_FULL_EXPORT] = $connStats[DumpConfig::KEY_FULL_EXPORT];
            }
            if (!empty($connStats[DumpConfig::KEY_PARTIAL_EXPORT])) {
                $connConfig[DumpConfig::KEY_PARTIAL_EXPORT] = $connStats[DumpConfig::KEY_PARTIAL_EXPORT];
            }
            if (!empty($connStats[DumpConfig::KEY_FAKER])) {
                $connConfig[DumpConfig::KEY_FAKER] = $connStats[DumpConfig::KEY_FAKER];
            }

            if (!empty($connConfig)) {
                $connectionConfigs[$connName] = $connConfig;
            }
        }

        if (!empty($connectionConfigs)) {
            $config[DumpConfig::KEY_CONNECTIONS] = $connectionConfigs;
        }

        // Мёрж с существующим конфигом для не-ALL режимов
        if ($this->mode !== self::MODE_ALL && !empty($baseConfig)) {
            $config = $this->mergeGeneratedIntoConfig($baseConfig, $config);
        }

        if ($this->splitBySchema) {
            $this->configSplitter->split($outputPath, $config);
        } else {
            $yaml = Yaml::dump($config, 4, 2);
            $this->fileSystem->write($outputPath, $yaml);
        }

        return $totalStats;
    }

    /**
     * Сгенерировать конфигурацию для одного подключения
     *
     * @param array<string, true> $existingTableSet
     * @return array{full_export: array<string, array<string>>, partial_export: array<string, array<string, array<string, mixed>>>, faker: array<string, array<string, array<string, string>>>, stats: array{full: int, partial: int, skipped: int, empty: int}}
     */
    private function generateForConnection(?string $connectionName, int $threshold, array $existingTableSet = []): array
    {
        $stats = ['full' => 0, 'partial' => 0, 'skipped' => 0, 'empty' => 0];

        /** @var array<string, array<string>> $fullExport */
        $fullExport = [];

        /** @var array<string, array<string, array<string, mixed>>> $partialExport */
        $partialExport = [];

        /** @var array<string, array<string, array<string, string>>> $fakerSection */
        $fakerSection = [];

        /** @var array<array{schema: string, table: string}> $nonEmptyTables */
        $nonEmptyTables = [];

        $tables = $this->inspector->listTables($connectionName);

        // Фильтрация таблиц по режиму
        if ($this->mode === self::MODE_SCHEMA && $this->modeScope !== null) {
            $scope = $this->modeScope;
            $tables = array_values(array_filter($tables, function ($tableInfo) use ($scope) {
                return $tableInfo['table_schema'] === $scope;
            }));
        } elseif ($this->mode === self::MODE_TABLE && $this->modeScope !== null) {
            $scope = $this->modeScope;
            $tables = array_values(array_filter($tables, function ($tableInfo) use ($scope) {
                return $tableInfo['table_schema'] . '.' . $tableInfo['table_name'] === $scope;
            }));
        }

        $total = count($tables);
        $current = 0;

        foreach ($tables as $tableInfo) {
            $current++;
            $schema = $tableInfo['table_schema'];
            $table = $tableInfo['table_name'];
            $prefix = "[{$current}/{$total}] {$schema}.{$table}";

            if ($this->filter->shouldIgnore($table)) {
                $this->logger->info("{$prefix} ... SKIP (служебная)");
                $stats['skipped']++;
                continue;
            }

            // Для MODE_NEW: пропускаем таблицы, уже присутствующие в конфиге
            if ($this->mode === self::MODE_NEW) {
                $tableKey = $schema . '.' . $table;
                if ($connectionName !== null) {
                    $tableKey = $connectionName . ':' . $tableKey;
                }
                if (isset($existingTableSet[$tableKey])) {
                    $this->logger->info("{$prefix} ... SKIP (уже в конфигурации)");
                    $stats['skipped']++;
                    continue;
                }
            }

            $count = $this->inspector->countRows($schema, $table, $connectionName);

            if ($count === 0) {
                $this->logger->info("{$prefix} ... SKIP (пустая)");
                $stats['empty']++;
                continue;
            }

            $nonEmptyTables[] = ['schema' => $schema, 'table' => $table];

            if ($count <= $threshold) {
                $this->logger->info("{$prefix} ... full_export ({$count} строк)");
                if (!isset($fullExport[$schema])) {
                    $fullExport[$schema] = [];
                }
                $fullExport[$schema][] = $table;
                $stats['full']++;
            } else {
                $orderBy = $this->inspector->detectOrderColumn($schema, $table, $connectionName);
                $this->logger->info("{$prefix} ... partial_export ({$count} строк, limit: {$threshold})");
                if (!isset($partialExport[$schema])) {
                    $partialExport[$schema] = [];
                }
                $partialExport[$schema][$table] = [
                    TableConfig::KEY_LIMIT => $threshold,
                    TableConfig::KEY_ORDER_BY => $orderBy,
                    TableConfig::KEY_WHERE => '1=1',
                ];
                $stats['partial']++;
            }
        }

        // Обогащение cascade_from из FK графа
        if ($this->cascadeEnabled) {
            $this->logger->info('Построение графа FK зависимостей...');
            $graph = $this->dependencyResolver->getDependencyGraph($connectionName);
            $this->addCascadeFromConfig($partialExport, $fullExport, $graph, $connectionName);
        }

        // Детекция паттернов faker
        if ($this->fakerEnabled) {
            $fakerTotal = count($nonEmptyTables);
            $fakerCurrent = 0;
            foreach ($nonEmptyTables as $tableInfo) {
                $fakerCurrent++;
                $schema = $tableInfo['schema'];
                $table = $tableInfo['table'];
                $this->logger->info("[{$fakerCurrent}/{$fakerTotal}] Анализ таблицы: {$schema}.{$table}");
                $patterns = $this->patternDetector->detect($schema, $table, $connectionName);
                if (!empty($patterns)) {
                    if (!isset($fakerSection[$schema])) {
                        $fakerSection[$schema] = [];
                    }
                    $fakerSection[$schema][$table] = $patterns;
                    $this->logger->info("  Обнаружены паттерны: " . implode(', ', array_map(
                        function ($col, $pat) {
                            return "{$col} => {$pat}";
                        },
                        array_keys($patterns),
                        array_values($patterns)
                    )));
                }
            }
        }

        return [
            DumpConfig::KEY_FULL_EXPORT => $fullExport,
            DumpConfig::KEY_PARTIAL_EXPORT => $partialExport,
            DumpConfig::KEY_FAKER => $fakerSection,
            'stats' => $stats,
        ];
    }

    /**
     * Обогатить partial_export записями cascade_from на основе FK графа.
     *
     * Также проверяет full_export таблицы, имеющие FK-родителей в partial_export,
     * и добавляет им cascade_from (перемещая в partial_export).
     *
     * @param array<string, array<string, array<string, mixed>>> &$partialExport
     * @param array<string, array<string>> &$fullExport
     * @param array<string, array<string, array{source_column: string, target_column: string}>> $graph
     * @param string|null $connectionName
     */
    private function addCascadeFromConfig(array &$partialExport, array &$fullExport, array $graph, ?string $connectionName): void
    {
        // Построить set-ы для быстрого lookup
        /** @var array<string, true> $fullExportSet */
        $fullExportSet = [];
        foreach ($fullExport as $schema => $tables) {
            foreach ($tables as $table) {
                $fullExportSet[$schema . '.' . $table] = true;
            }
        }

        /** @var array<string, true> $partialExportSet */
        $partialExportSet = [];
        foreach ($partialExport as $schema => $tables) {
            foreach ($tables as $table => $conf) {
                $partialExportSet[$schema . '.' . $table] = true;
            }
        }

        // 1. Для каждой partial_export таблицы — добавить cascade_from от partial-родителей
        foreach ($partialExport as $schema => $tables) {
            foreach ($tables as $table => $conf) {
                $childKey = $schema . '.' . $table;
                if (!isset($graph[$childKey])) {
                    continue;
                }

                $cascadeEntries = [];
                foreach ($graph[$childKey] as $parentKey => $columns) {
                    // Пропускаем full_export родителей (все данные присутствуют)
                    if (isset($fullExportSet[$parentKey])) {
                        continue;
                    }
                    // Добавляем только если родитель в partial_export
                    if (isset($partialExportSet[$parentKey])) {
                        $cascadeEntries[] = [
                            'parent' => $parentKey,
                            'fk_column' => $columns['source_column'],
                            'parent_column' => $columns['target_column'],
                        ];
                    }
                }

                if (!empty($cascadeEntries)) {
                    $partialExport[$schema][$table][TableConfig::KEY_CASCADE_FROM] = $cascadeEntries;
                }
            }
        }

        // 2. Проверить full_export таблицы с FK-родителями в partial_export
        foreach ($fullExport as $schema => $tables) {
            foreach ($tables as $index => $table) {
                $childKey = $schema . '.' . $table;
                if (!isset($graph[$childKey])) {
                    continue;
                }

                $cascadeEntries = [];
                foreach ($graph[$childKey] as $parentKey => $columns) {
                    if (isset($partialExportSet[$parentKey])) {
                        $cascadeEntries[] = [
                            'parent' => $parentKey,
                            'fk_column' => $columns['source_column'],
                            'parent_column' => $columns['target_column'],
                        ];
                    }
                }

                if (!empty($cascadeEntries)) {
                    // Переместить из full_export в partial_export с cascade_from
                    unset($fullExport[$schema][$index]);
                    $fullExport[$schema] = array_values($fullExport[$schema]);
                    if (empty($fullExport[$schema])) {
                        unset($fullExport[$schema]);
                    }

                    if (!isset($partialExport[$schema])) {
                        $partialExport[$schema] = [];
                    }
                    $partialExport[$schema][$table] = [
                        TableConfig::KEY_CASCADE_FROM => $cascadeEntries,
                    ];
                }
            }
        }
    }

    /**
     * Загрузить существующий конфиг из файла, резолвить includes
     *
     * @return array<string, mixed>
     */
    private function loadExistingConfigArray(string $path): array
    {
        if (!$this->fileSystem->exists($path)) {
            return [];
        }

        $content = $this->fileSystem->read($path);
        $config = Yaml::parse($content);

        if (!is_array($config)) {
            return [];
        }

        $configDir = dirname($path);

        return $this->resolveConfigIncludes($config, $configDir);
    }

    /**
     * Резолвить секцию includes в плоскую структуру
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function resolveConfigIncludes(array $data, string $configDir): array
    {
        if (isset($data[DumpConfig::KEY_INCLUDES]) && is_array($data[DumpConfig::KEY_INCLUDES])) {
            foreach ($data[DumpConfig::KEY_INCLUDES] as $schema => $relativePath) {
                $filePath = $configDir . '/' . $relativePath;
                if (!$this->fileSystem->exists($filePath)) {
                    continue;
                }

                $schemaContent = $this->fileSystem->read($filePath);
                $schemaData = Yaml::parse($schemaContent);

                if (!is_array($schemaData)) {
                    continue;
                }

                if (isset($schemaData[DumpConfig::KEY_FULL_EXPORT])) {
                    if (!isset($data[DumpConfig::KEY_FULL_EXPORT])) {
                        $data[DumpConfig::KEY_FULL_EXPORT] = [];
                    }
                    $data[DumpConfig::KEY_FULL_EXPORT][$schema] = $schemaData[DumpConfig::KEY_FULL_EXPORT];
                }
                if (isset($schemaData[DumpConfig::KEY_PARTIAL_EXPORT])) {
                    if (!isset($data[DumpConfig::KEY_PARTIAL_EXPORT])) {
                        $data[DumpConfig::KEY_PARTIAL_EXPORT] = [];
                    }
                    $data[DumpConfig::KEY_PARTIAL_EXPORT][$schema] = $schemaData[DumpConfig::KEY_PARTIAL_EXPORT];
                }
                if (isset($schemaData[DumpConfig::KEY_FAKER])) {
                    if (!isset($data[DumpConfig::KEY_FAKER])) {
                        $data[DumpConfig::KEY_FAKER] = [];
                    }
                    $data[DumpConfig::KEY_FAKER][$schema] = $schemaData[DumpConfig::KEY_FAKER];
                }
            }
            unset($data[DumpConfig::KEY_INCLUDES]);
        }

        if (isset($data[DumpConfig::KEY_CONNECTIONS]) && is_array($data[DumpConfig::KEY_CONNECTIONS])) {
            foreach ($data[DumpConfig::KEY_CONNECTIONS] as $connName => &$connData) {
                if (is_array($connData)) {
                    $connData = $this->resolveConfigIncludes($connData, $configDir);
                }
            }
            unset($connData);
        }

        return $data;
    }

    /**
     * Удалить схему из всех секций конфига (включая connections)
     *
     * @param array<string, mixed> &$config
     */
    private function removeSchemaFromConfig(array &$config, string $schema): void
    {
        if (isset($config[DumpConfig::KEY_FULL_EXPORT][$schema])) {
            unset($config[DumpConfig::KEY_FULL_EXPORT][$schema]);
            if (empty($config[DumpConfig::KEY_FULL_EXPORT])) {
                unset($config[DumpConfig::KEY_FULL_EXPORT]);
            }
        }

        if (isset($config[DumpConfig::KEY_PARTIAL_EXPORT][$schema])) {
            unset($config[DumpConfig::KEY_PARTIAL_EXPORT][$schema]);
            if (empty($config[DumpConfig::KEY_PARTIAL_EXPORT])) {
                unset($config[DumpConfig::KEY_PARTIAL_EXPORT]);
            }
        }

        if (isset($config[DumpConfig::KEY_FAKER][$schema])) {
            unset($config[DumpConfig::KEY_FAKER][$schema]);
            if (empty($config[DumpConfig::KEY_FAKER])) {
                unset($config[DumpConfig::KEY_FAKER]);
            }
        }

        if (isset($config[DumpConfig::KEY_CONNECTIONS]) && is_array($config[DumpConfig::KEY_CONNECTIONS])) {
            foreach ($config[DumpConfig::KEY_CONNECTIONS] as $connName => &$connData) {
                if (is_array($connData)) {
                    $this->removeSchemaFromConfig($connData, $schema);
                    if (empty($connData)) {
                        unset($config[DumpConfig::KEY_CONNECTIONS][$connName]);
                    }
                }
            }
            unset($connData);
            if (empty($config[DumpConfig::KEY_CONNECTIONS])) {
                unset($config[DumpConfig::KEY_CONNECTIONS]);
            }
        }
    }

    /**
     * Удалить таблицу из всех секций конфига (включая connections)
     *
     * @param array<string, mixed> &$config
     */
    private function removeTableFromConfig(array &$config, string $schema, string $table): void
    {
        if (isset($config[DumpConfig::KEY_FULL_EXPORT][$schema])
            && is_array($config[DumpConfig::KEY_FULL_EXPORT][$schema])
        ) {
            $key = array_search($table, $config[DumpConfig::KEY_FULL_EXPORT][$schema], true);
            if ($key !== false) {
                array_splice($config[DumpConfig::KEY_FULL_EXPORT][$schema], (int) $key, 1);
                if (empty($config[DumpConfig::KEY_FULL_EXPORT][$schema])) {
                    unset($config[DumpConfig::KEY_FULL_EXPORT][$schema]);
                }
            }
            if (empty($config[DumpConfig::KEY_FULL_EXPORT])) {
                unset($config[DumpConfig::KEY_FULL_EXPORT]);
            }
        }

        if (isset($config[DumpConfig::KEY_PARTIAL_EXPORT][$schema][$table])) {
            unset($config[DumpConfig::KEY_PARTIAL_EXPORT][$schema][$table]);
            if (empty($config[DumpConfig::KEY_PARTIAL_EXPORT][$schema])) {
                unset($config[DumpConfig::KEY_PARTIAL_EXPORT][$schema]);
            }
            if (isset($config[DumpConfig::KEY_PARTIAL_EXPORT]) && empty($config[DumpConfig::KEY_PARTIAL_EXPORT])) {
                unset($config[DumpConfig::KEY_PARTIAL_EXPORT]);
            }
        }

        if (isset($config[DumpConfig::KEY_FAKER][$schema][$table])) {
            unset($config[DumpConfig::KEY_FAKER][$schema][$table]);
            if (empty($config[DumpConfig::KEY_FAKER][$schema])) {
                unset($config[DumpConfig::KEY_FAKER][$schema]);
            }
            if (isset($config[DumpConfig::KEY_FAKER]) && empty($config[DumpConfig::KEY_FAKER])) {
                unset($config[DumpConfig::KEY_FAKER]);
            }
        }

        if (isset($config[DumpConfig::KEY_CONNECTIONS]) && is_array($config[DumpConfig::KEY_CONNECTIONS])) {
            foreach ($config[DumpConfig::KEY_CONNECTIONS] as $connName => &$connData) {
                if (is_array($connData)) {
                    $this->removeTableFromConfig($connData, $schema, $table);
                    if (empty($connData)) {
                        unset($config[DumpConfig::KEY_CONNECTIONS][$connName]);
                    }
                }
            }
            unset($connData);
            if (empty($config[DumpConfig::KEY_CONNECTIONS])) {
                unset($config[DumpConfig::KEY_CONNECTIONS]);
            }
        }
    }

    /**
     * Собрать множество существующих таблиц из конфига
     *
     * @param array<string, mixed> $config
     * @return array<string, true>
     */
    private function buildExistingTableSet(array $config): array
    {
        $set = [];

        if (isset($config[DumpConfig::KEY_FULL_EXPORT]) && is_array($config[DumpConfig::KEY_FULL_EXPORT])) {
            foreach ($config[DumpConfig::KEY_FULL_EXPORT] as $schema => $tables) {
                if (!is_array($tables)) {
                    continue;
                }
                foreach ($tables as $table) {
                    $set[$schema . '.' . $table] = true;
                }
            }
        }

        if (isset($config[DumpConfig::KEY_PARTIAL_EXPORT]) && is_array($config[DumpConfig::KEY_PARTIAL_EXPORT])) {
            foreach ($config[DumpConfig::KEY_PARTIAL_EXPORT] as $schema => $tables) {
                if (!is_array($tables)) {
                    continue;
                }
                foreach ($tables as $table => $conf) {
                    $set[$schema . '.' . $table] = true;
                }
            }
        }

        if (isset($config[DumpConfig::KEY_CONNECTIONS]) && is_array($config[DumpConfig::KEY_CONNECTIONS])) {
            foreach ($config[DumpConfig::KEY_CONNECTIONS] as $connName => $connData) {
                if (!is_array($connData)) {
                    continue;
                }
                if (isset($connData[DumpConfig::KEY_FULL_EXPORT]) && is_array($connData[DumpConfig::KEY_FULL_EXPORT])) {
                    foreach ($connData[DumpConfig::KEY_FULL_EXPORT] as $schema => $tables) {
                        if (!is_array($tables)) {
                            continue;
                        }
                        foreach ($tables as $table) {
                            $set[$connName . ':' . $schema . '.' . $table] = true;
                        }
                    }
                }
                if (isset($connData[DumpConfig::KEY_PARTIAL_EXPORT]) && is_array($connData[DumpConfig::KEY_PARTIAL_EXPORT])) {
                    foreach ($connData[DumpConfig::KEY_PARTIAL_EXPORT] as $schema => $tables) {
                        if (!is_array($tables)) {
                            continue;
                        }
                        foreach ($tables as $table => $conf) {
                            $set[$connName . ':' . $schema . '.' . $table] = true;
                        }
                    }
                }
            }
        }

        return $set;
    }

    /**
     * Мёрж сгенерированных данных в существующий конфиг
     *
     * @param array<string, mixed> $existing
     * @param array<string, mixed> $generated
     * @return array<string, mixed>
     */
    private function mergeGeneratedIntoConfig(array $existing, array $generated): array
    {
        $result = $existing;

        if (!empty($generated[DumpConfig::KEY_FULL_EXPORT])) {
            if (!isset($result[DumpConfig::KEY_FULL_EXPORT])) {
                $result[DumpConfig::KEY_FULL_EXPORT] = [];
            }
            foreach ($generated[DumpConfig::KEY_FULL_EXPORT] as $schema => $tables) {
                if (!isset($result[DumpConfig::KEY_FULL_EXPORT][$schema])) {
                    $result[DumpConfig::KEY_FULL_EXPORT][$schema] = [];
                }
                $result[DumpConfig::KEY_FULL_EXPORT][$schema] = array_values(
                    array_unique(array_merge($result[DumpConfig::KEY_FULL_EXPORT][$schema], $tables))
                );
            }
        }

        if (!empty($generated[DumpConfig::KEY_PARTIAL_EXPORT])) {
            if (!isset($result[DumpConfig::KEY_PARTIAL_EXPORT])) {
                $result[DumpConfig::KEY_PARTIAL_EXPORT] = [];
            }
            foreach ($generated[DumpConfig::KEY_PARTIAL_EXPORT] as $schema => $tables) {
                if (!isset($result[DumpConfig::KEY_PARTIAL_EXPORT][$schema])) {
                    $result[DumpConfig::KEY_PARTIAL_EXPORT][$schema] = [];
                }
                foreach ($tables as $table => $conf) {
                    $result[DumpConfig::KEY_PARTIAL_EXPORT][$schema][$table] = $conf;
                }
            }
        }

        if (!empty($generated[DumpConfig::KEY_FAKER])) {
            if (!isset($result[DumpConfig::KEY_FAKER])) {
                $result[DumpConfig::KEY_FAKER] = [];
            }
            foreach ($generated[DumpConfig::KEY_FAKER] as $schema => $tables) {
                if (!isset($result[DumpConfig::KEY_FAKER][$schema])) {
                    $result[DumpConfig::KEY_FAKER][$schema] = [];
                }
                foreach ($tables as $table => $columns) {
                    $result[DumpConfig::KEY_FAKER][$schema][$table] = $columns;
                }
            }
        }

        if (!empty($generated[DumpConfig::KEY_CONNECTIONS])) {
            if (!isset($result[DumpConfig::KEY_CONNECTIONS])) {
                $result[DumpConfig::KEY_CONNECTIONS] = [];
            }
            foreach ($generated[DumpConfig::KEY_CONNECTIONS] as $connName => $connData) {
                if (!isset($result[DumpConfig::KEY_CONNECTIONS][$connName])) {
                    $result[DumpConfig::KEY_CONNECTIONS][$connName] = [];
                }
                $result[DumpConfig::KEY_CONNECTIONS][$connName] = $this->mergeGeneratedIntoConfig(
                    $result[DumpConfig::KEY_CONNECTIONS][$connName],
                    $connData
                );
            }
        }

        return $result;
    }

    /**
     * @param array{full: int, partial: int, skipped: int, empty: int} $total
     * @param array{full: int, partial: int, skipped: int, empty: int} $add
     */
    private function mergeStats(array &$total, array $add): void
    {
        $total['full'] += $add['full'];
        $total['partial'] += $add['partial'];
        $total['skipped'] += $add['skipped'];
        $total['empty'] += $add['empty'];
    }
}
