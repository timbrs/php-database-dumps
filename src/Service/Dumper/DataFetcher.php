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
 *
 * Каскад (cascade_from) вычисляется до выбора способа выборки: обычной выборке он
 * добавляется в WHERE, выборке по критериям (sample) — в базовое условие каждой корзины.
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
     * Колонки таблицы, на которые ссылаются дети через cascade_from (parent_column) —
     * их SampleQueryBuilder выбирает в фазе 1 и кладёт в реестр вместе с PK, чтобы
     * ребёнок с `parent_column: core_id` нашёл именно выбранные core_id, а не подзапрос.
     * Смотрятся все схемы и все подключения конфига.
     *
     * @return array<int, string>
     */
    public function referencedColumns(TableConfig $config): array
    {
        $key = $config->getFullTableName();
        $columns = [];

        $dumpConfigs = [$this->dumpConfig];
        foreach ($this->dumpConfig->getConnectionConfigs() as $connectionConfig) {
            $dumpConfigs[] = $connectionConfig;
        }

        foreach ($dumpConfigs as $dumpConfig) {
            foreach ($dumpConfig->getAllPartialExportSchemas() as $schema) {
                foreach ($dumpConfig->getPartialExportTables($schema) as $raw) {
                    if (!is_array($raw) || !isset($raw[TableConfig::KEY_CASCADE_FROM]) || !is_array($raw[TableConfig::KEY_CASCADE_FROM])) {
                        continue;
                    }
                    foreach ($raw[TableConfig::KEY_CASCADE_FROM] as $cascade) {
                        if (!is_array($cascade) || ($cascade['parent'] ?? null) !== $key || !isset($cascade['parent_column'])) {
                            continue;
                        }
                        $column = (string) $cascade['parent_column'];
                        $columns[strtolower($column)] = $column;
                    }
                }
            }
        }

        return array_values($columns);
    }

    /**
     * Сформировать SELECT для таблицы (WHERE + cascade WHERE + ORDER BY + LIMIT).
     */
    private function buildSql(TableConfig $config): string
    {
        $cascadeWhere = null;
        if ($config->getCascadeFrom() !== null) {
            $cascadeWhere = $this->cascadeResolver->resolve($config, $this->dumpConfig);
        }

        // Выборка по именованным критериям (sample) — двухфазная дедупликация; каскад
        // входит в базовое условие каждой корзины.
        if ($config->hasSample()) {
            if ($this->sampleQueryBuilder === null) {
                throw new \RuntimeException(sprintf(
                    'Таблица %s использует sample-выборку, но SampleQueryBuilder не сконфигурирован.',
                    $config->getFullTableName()
                ));
            }
            return $this->sampleQueryBuilder->build(
                $config,
                $cascadeWhere,
                $this->referencedColumns($config),
                $this->defaultPerValue()
            );
        }

        $platform = $this->registry->getPlatform($config->getConnectionName());

        $fullTable = $platform->getFullTableName($config->getSchema(), $config->getTable());
        $sql = "SELECT * FROM {$fullTable}";

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

    /**
     * settings.sample.per_value — квота stratify_by по умолчанию для всех таблиц.
     */
    private function defaultPerValue(): ?int
    {
        $settings = $this->dumpConfig->getSettings();
        if (isset($settings['sample']) && is_array($settings['sample']) && isset($settings['sample']['per_value'])) {
            return (int) $settings['sample']['per_value'];
        }

        return null;
    }
}
