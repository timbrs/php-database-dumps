<?php

namespace Timbrs\DatabaseDumps\Service\Dumper;

use Timbrs\DatabaseDumps\Config\DumpConfig;
use Timbrs\DatabaseDumps\Config\TableConfig;
use Timbrs\DatabaseDumps\Contract\ConnectionRegistryInterface;

/**
 * Получение конфигурации таблицы для экспорта
 */
class TableConfigResolver
{
    /** @var DumpConfig */
    private $dumpConfig;

    public function __construct(DumpConfig $dumpConfig)
    {
        $this->dumpConfig = $dumpConfig;
    }

    /**
     * Получить конфигурацию для таблицы
     */
    public function resolve(string $schema, string $table, ?string $connectionName = null): TableConfig
    {
        $config = $this->getEffectiveConfig($connectionName);
        $tableConfig = $config->getTableConfig($schema, $table);

        return TableConfig::fromArray($schema, $table, $tableConfig ?? [], $connectionName);
    }

    /**
     * Получить все таблицы для экспорта из схемы
     *
     * @return array<TableConfig>
     */
    public function resolveAllFromSchema(string $schema, ?string $connectionName = null): array
    {
        $config = $this->getEffectiveConfig($connectionName);
        $tables = [];

        // Full export таблицы
        foreach ($config->getFullExportTables($schema) as $table) {
            $tables[] = TableConfig::fromArray($schema, $table, [], $connectionName);
        }

        // Partial export таблицы
        foreach ($config->getPartialExportTables($schema) as $table => $tableConf) {
            $tables[] = TableConfig::fromArray($schema, $table, $tableConf, $connectionName);
        }

        return $tables;
    }

    /**
     * Получить все таблицы для экспорта (все схемы)
     *
     * @param string|null $schemaFilter Фильтр по схеме
     * @param string|null $connectionFilter Фильтр по подключению (null = дефолтное, 'all' = все)
     * @return array<TableConfig>
     */
    public function resolveAll(?string $schemaFilter = null, ?string $connectionFilter = null): array
    {
        $tables = [];

        if ($connectionFilter === ConnectionRegistryInterface::CONNECTION_ALL) {
            // Дефолтное подключение
            $tables = array_merge($tables, $this->resolveTablesFromConfig($this->dumpConfig, $schemaFilter, null));

            // Все дополнительные подключения
            foreach ($this->dumpConfig->getConnectionConfigs() as $connName => $connConfig) {
                $tables = array_merge($tables, $this->resolveTablesFromConfig($connConfig, $schemaFilter, $connName));
            }
        } elseif ($connectionFilter !== null) {
            // Конкретное подключение
            $connConfig = $this->dumpConfig->getConnectionConfig($connectionFilter);
            if ($connConfig !== null) {
                $tables = $this->resolveTablesFromConfig($connConfig, $schemaFilter, $connectionFilter);
            }
        } else {
            // Дефолтное подключение
            $tables = $this->resolveTablesFromConfig($this->dumpConfig, $schemaFilter, null);
        }

        return $tables;
    }

    /**
     * Получить DumpConfig для данного подключения
     */
    private function getEffectiveConfig(?string $connectionName): DumpConfig
    {
        if ($connectionName === null) {
            return $this->dumpConfig;
        }

        $connConfig = $this->dumpConfig->getConnectionConfig($connectionName);

        return $connConfig ?? $this->dumpConfig;
    }

    /**
     * Получить таблицы из конкретного DumpConfig
     *
     * @return array<TableConfig>
     */
    private function resolveTablesFromConfig(DumpConfig $config, ?string $schemaFilter, ?string $connectionName): array
    {
        $tables = [];
        $addedTables = [];

        // Full export
        foreach ($config->getAllFullExportSchemas() as $schema) {
            if ($schemaFilter && $schema !== $schemaFilter) {
                continue;
            }

            foreach ($config->getFullExportTables($schema) as $table) {
                $key = $schema . '.' . $table;
                if (!isset($addedTables[$key])) {
                    $tables[] = TableConfig::fromArray($schema, $table, [], $connectionName);
                    $addedTables[$key] = true;
                }
            }
        }

        // Partial export
        foreach ($config->getAllPartialExportSchemas() as $schema) {
            if ($schemaFilter && $schema !== $schemaFilter) {
                continue;
            }

            foreach ($config->getPartialExportTables($schema) as $table => $tableConf) {
                $key = $schema . '.' . $table;
                if (!isset($addedTables[$key])) {
                    $tables[] = TableConfig::fromArray($schema, $table, $tableConf, $connectionName);
                    $addedTables[$key] = true;
                }
            }
        }

        return $tables;
    }
}
