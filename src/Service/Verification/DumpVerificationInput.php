<?php

namespace Timbrs\DatabaseDumps\Service\Verification;

use Timbrs\DatabaseDumps\Config\DumpConfig;
use Timbrs\DatabaseDumps\Config\TableConfig;
use Timbrs\DatabaseDumps\Service\Validation\InventoryReader;

/**
 * Всё, что нужно проверкам выгруженных дампов: каталог дампов, разрешённые конфиги таблиц,
 * слепок схемы (если есть) и конфиг выгрузки ради секции faker.
 */
class DumpVerificationInput
{
    /** @var string */
    private $dumpsRoot;

    /** @var array<int, TableConfig> */
    private $tables;

    /** @var array<string, TableConfig> «schema.table» => конфиг */
    private $index = [];

    /** @var InventoryReader|null */
    private $inventory;

    /** @var DumpConfig|null */
    private $dumpConfig;

    /**
     * @param array<int, TableConfig> $tables
     */
    public function __construct(
        string $dumpsRoot,
        array $tables,
        InventoryReader $inventory = null,
        DumpConfig $dumpConfig = null
    ) {
        $this->dumpsRoot = $dumpsRoot;
        $this->tables = $tables;
        foreach ($tables as $config) {
            $this->index[$config->getFullTableName()] = $config;
        }
        $this->inventory = $inventory;
        $this->dumpConfig = $dumpConfig;
    }

    public function getDumpsRoot(): string
    {
        return $this->dumpsRoot;
    }

    /**
     * @return array<int, TableConfig>
     */
    public function getTables(): array
    {
        return $this->tables;
    }

    public function tableByKey(string $key): ?TableConfig
    {
        return $this->index[$key] ?? null;
    }

    public function getInventory(): ?InventoryReader
    {
        return $this->inventory;
    }

    public function getDumpConfig(): ?DumpConfig
    {
        return $this->dumpConfig;
    }

    public function pathFor(TableConfig $config): string
    {
        return DumpLocator::path($this->dumpsRoot, $config);
    }

    /**
     * Faker-маппинг таблицы (колонка => паттерн) с учётом подключения; [] — не задан.
     *
     * @return array<string, string>
     */
    public function fakerFor(TableConfig $config): array
    {
        if ($this->dumpConfig === null) {
            return [];
        }
        $dumpConfig = $this->dumpConfig;
        $connection = $config->getConnectionName();
        if ($connection !== null) {
            $dumpConfig = $this->dumpConfig->getConnectionConfig($connection);
            if ($dumpConfig === null) {
                return [];
            }
        }

        return $dumpConfig->getFakerConfig()->getTableFaker($config->getSchema(), $config->getTable()) ?? [];
    }
}
