<?php

namespace Timbrs\DatabaseDumps\Service\Dumper;

use Timbrs\DatabaseDumps\Config\DumpConfig;
use Timbrs\DatabaseDumps\Config\TableConfig;
use Timbrs\DatabaseDumps\Contract\ConnectionRegistryInterface;

/**
 * Загрузка данных из таблицы.
 *
 * Поддерживает потоковую выборку (iterate) для больших таблиц — гарантирует
 * константное потребление памяти независимо от размера таблицы.
 */
class DataFetcher
{
    /** @var ConnectionRegistryInterface */
    private $registry;

    /** @var CascadeWhereResolver */
    private $cascadeResolver;

    /** @var DumpConfig */
    private $dumpConfig;

    /** @var SampleQueryBuilder|null */
    private $sampleQueryBuilder;

    /** @var string|null */
    private $lastQuery;

    public function __construct(
        ConnectionRegistryInterface $registry,
        CascadeWhereResolver $cascadeResolver,
        DumpConfig $dumpConfig,
        SampleQueryBuilder $sampleQueryBuilder = null
    ) {
        $this->registry = $registry;
        $this->cascadeResolver = $cascadeResolver;
        $this->dumpConfig = $dumpConfig;
        $this->sampleQueryBuilder = $sampleQueryBuilder;
    }

    public function getLastQuery(): ?string
    {
        return $this->lastQuery;
    }

    /**
     * Загрузить ВСЕ строки в память (для маленьких таблиц и совместимости).
     *
     * Для больших таблиц предпочитайте iterate().
     *
     * @return array<array<string, mixed>>
     */
    public function fetch(TableConfig $config): array
    {
        $sql = $this->buildSql($config);
        $this->lastQuery = $sql;

        $connection = $this->registry->getConnection($config->getConnectionName());
        return $connection->fetchAllAssociative($sql);
    }

    /**
     * Стримовая выборка — yield строк по одной без загрузки всей выборки в память.
     *
     * @return \Generator<int, array<string, mixed>>
     */
    public function iterate(TableConfig $config): \Generator
    {
        $sql = $this->buildSql($config);
        $this->lastQuery = $sql;

        $connection = $this->registry->getConnection($config->getConnectionName());
        foreach ($connection->iterateAssociative($sql) as $row) {
            yield $row;
        }
    }

    /**
     * Сформировать SELECT для таблицы (WHERE + cascade WHERE + ORDER BY + LIMIT).
     */
    private function buildSql(TableConfig $config): string
    {
        // Выборка по именованным критериям (sample) — двухфазная дедупликация.
        if ($config->hasSample()) {
            if ($this->sampleQueryBuilder === null) {
                throw new \RuntimeException(sprintf(
                    'Таблица %s использует sample-выборку, но SampleQueryBuilder не сконфигурирован.',
                    $config->getFullTableName()
                ));
            }
            return $this->sampleQueryBuilder->build($config);
        }

        $platform = $this->registry->getPlatform($config->getConnectionName());

        $fullTable = $platform->getFullTableName($config->getSchema(), $config->getTable());
        $sql = "SELECT * FROM {$fullTable}";

        $cascadeWhere = null;
        if ($config->getCascadeFrom() !== null) {
            $cascadeWhere = $this->cascadeResolver->resolve($config, $this->dumpConfig);
        }

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

        return $sql;
    }
}
