<?php

namespace Timbrs\DatabaseDumps\Service\Dumper;

use Timbrs\DatabaseDumps\Config\DumpConfig;
use Timbrs\DatabaseDumps\Config\TableConfig;
use Timbrs\DatabaseDumps\Contract\ConnectionRegistryInterface;

/**
 * Загрузка данных из таблицы
 */
class DataFetcher
{
    /** @var ConnectionRegistryInterface */
    private $registry;

    /** @var CascadeWhereResolver */
    private $cascadeResolver;

    /** @var DumpConfig */
    private $dumpConfig;

    /** @var string|null */
    private $lastQuery;

    public function __construct(
        ConnectionRegistryInterface $registry,
        CascadeWhereResolver $cascadeResolver,
        DumpConfig $dumpConfig
    ) {
        $this->registry = $registry;
        $this->cascadeResolver = $cascadeResolver;
        $this->dumpConfig = $dumpConfig;
    }

    /**
     * Получить последний выполненный SQL-запрос
     *
     * @return string|null
     */
    public function getLastQuery(): ?string
    {
        return $this->lastQuery;
    }

    /**
     * Загрузить данные из таблицы
     *
     * @return array<array<string, mixed>>
     */
    public function fetch(TableConfig $config): array
    {
        $connectionName = $config->getConnectionName();
        $connection = $this->registry->getConnection($connectionName);
        $platform = $this->registry->getPlatform($connectionName);

        $fullTable = $platform->getFullTableName($config->getSchema(), $config->getTable());
        $sql = "SELECT * FROM {$fullTable}";

        // Resolve cascade WHERE if configured
        $cascadeWhere = null;
        if ($config->getCascadeFrom() !== null) {
            $cascadeWhere = $this->cascadeResolver->resolve($config, $this->dumpConfig);
        }

        // Build WHERE clause
        $existingWhere = $config->getWhere();
        if ($existingWhere !== null && $cascadeWhere !== null) {
            $sql .= " WHERE ({$existingWhere}) AND ({$cascadeWhere})";
        } elseif ($cascadeWhere !== null) {
            $sql .= " WHERE {$cascadeWhere}";
        } elseif ($existingWhere !== null) {
            $sql .= " WHERE {$existingWhere}";
        }

        if ($config->getOrderBy()) {
            $sql .= " ORDER BY {$config->getOrderBy()}";
        }

        if ($config->getLimit()) {
            $sql .= ' ' . $platform->getLimitSql($config->getLimit());
        }

        $this->lastQuery = $sql;

        return $connection->fetchAllAssociative($sql);
    }
}
