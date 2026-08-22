<?php

namespace Timbrs\DatabaseDumps\Service\Validation;

use Timbrs\DatabaseDumps\Config\TableConfig;
use Timbrs\DatabaseDumps\Service\Graph\TopologicalSorter;

/**
 * Всё, что нужно правилам аудита, посчитанное один раз: разобранный конфиг, слепок схемы,
 * собранные TableConfig (вместе с текстами исключений там, где собрать не вышло) и
 * фактический порядок экспорта.
 *
 * Фильтр по схемам (`-s`) сужает только то, ЧТО докладывается: граф каскадов и порядок
 * экспорта всегда считаются по всему конфигу, иначе родитель из соседней схемы выглядел бы
 * невыгружаемым.
 */
class AuditContext
{
    /** @var ConfigDocument */
    private $config;

    /** @var InventoryReader */
    private $inventory;

    /** @var array<string, bool> Схемы в области отчёта (пусто — все) */
    private $scope;

    /** @var array<string, TableConfig> «schema.table» => конфиг таблицы */
    private $tableConfigs = [];

    /** @var array<string, string> «schema.table» => текст исключения TableConfig */
    private $tableConfigErrors = [];

    /** @var array<string, int>|null «schema.table» => позиция в порядке экспорта */
    private $exportOrder;

    /**
     * @param array<int, string> $schemaFilter пустой массив — без фильтра
     */
    public function __construct(ConfigDocument $config, InventoryReader $inventory, array $schemaFilter = [])
    {
        $this->config = $config;
        $this->inventory = $inventory;
        $this->scope = [];
        foreach ($schemaFilter as $schema) {
            $this->scope[$schema] = true;
        }

        $this->buildTableConfigs();
    }

    public function config(): ConfigDocument
    {
        return $this->config;
    }

    public function inventory(): InventoryReader
    {
        return $this->inventory;
    }

    /**
     * Есть ли фильтр по схемам.
     */
    public function isFiltered(): bool
    {
        return !empty($this->scope);
    }

    public function inScope(?string $schema): bool
    {
        if (empty($this->scope) || $schema === null) {
            return true;
        }
        return isset($this->scope[$schema]);
    }

    /**
     * Схемы, о которых надо докладывать: объединение схем конфига и слепка, суженное фильтром.
     *
     * @return array<int, string>
     */
    public function scopedSchemas(): array
    {
        $schemas = [];
        foreach ($this->config->getSchemas() as $schema) {
            $schemas[$schema] = true;
        }
        foreach ($this->inventory->schemas() as $schema) {
            $schemas[$schema] = true;
        }
        $names = [];
        foreach (array_keys($schemas) as $schema) {
            if ($this->inScope((string) $schema)) {
                $names[] = (string) $schema;
            }
        }
        sort($names);
        return $names;
    }

    /**
     * Все схемы конфига и слепка без учёта фильтра.
     *
     * @return array<int, string>
     */
    public function allSchemas(): array
    {
        $schemas = [];
        foreach ($this->config->getSchemas() as $schema) {
            $schemas[$schema] = true;
        }
        foreach ($this->inventory->schemas() as $schema) {
            $schemas[$schema] = true;
        }
        $names = array_keys($schemas);
        sort($names);
        return $names;
    }

    public function tableConfig(string $schema, string $table): ?TableConfig
    {
        $key = $schema . '.' . $table;
        return isset($this->tableConfigs[$key]) ? $this->tableConfigs[$key] : null;
    }

    /**
     * Текст исключения, с которым TableConfig отверг таблицу (находка S-2).
     */
    public function tableConfigError(string $schema, string $table): ?string
    {
        $key = $schema . '.' . $table;
        return isset($this->tableConfigErrors[$key]) ? $this->tableConfigErrors[$key] : null;
    }

    /**
     * @return array<string, string> «schema.table» => текст исключения
     */
    public function tableConfigErrors(): array
    {
        return $this->tableConfigErrors;
    }

    /**
     * Колонки таблицы из слепка (пустой массив — таблицы в слепке нет).
     *
     * @return array<int, string>
     */
    public function knownColumns(string $schema, string $table): array
    {
        return $this->inventory->columns($schema, $table);
    }

    /**
     * Выгружается ли таблица (в любой из секций конфига).
     */
    public function isExported(string $schema, string $table): bool
    {
        $modes = $this->config->getTableModes($schema);
        return isset($modes[$table]);
    }

    /**
     * Позиция таблицы в фактическом порядке экспорта или null, если она не выгружается.
     *
     * Порядок воспроизводится тем же TopologicalSorter, что и в DatabaseDumper, но граф
     * строится по FK из слепка, а не из живой БД. В базе без FK-констрейнтов рёбер нет
     * вовсе, и порядок вырождается в алфавитный — на этом держится правило G-4.
     */
    public function exportPosition(string $schema, string $table): ?int
    {
        $order = $this->exportOrder();
        $key = $schema . '.' . $table;
        return isset($order[$key]) ? $order[$key] : null;
    }

    /**
     * @return array<string, int> «schema.table» => позиция
     */
    public function exportOrder(): array
    {
        if ($this->exportOrder !== null) {
            return $this->exportOrder;
        }

        $keys = array_keys($this->config->getExportedTableKeys());
        $keySet = array_flip($keys);

        $adjacency = [];
        foreach ($keys as $key) {
            $adjacency[$key] = [];
        }
        foreach ($keys as $childKey) {
            $parts = explode('.', $childKey, 2);
            if (count($parts) !== 2) {
                continue;
            }
            foreach ($this->inventory->foreignKeys($parts[0], $parts[1]) as $fk) {
                $parentKey = $fk['references_table'];
                if ($parentKey === $childKey || !isset($keySet[$parentKey])) {
                    continue;
                }
                if (!in_array($parentKey, $adjacency[$childKey], true)) {
                    $adjacency[$childKey][] = $parentKey;
                }
            }
        }

        $sorter = new TopologicalSorter();
        $sorted = $sorter->sortWithCycleBreaking($adjacency)->getSorted();

        $order = [];
        foreach ($sorted as $position => $key) {
            $order[$key] = $position;
        }
        $this->exportOrder = $order;

        return $this->exportOrder;
    }

    /**
     * Собрать TableConfig по каждой таблице конфига, превратив отказ в запись об ошибке.
     * Правила дальше работают с готовыми объектами и не разбирают YAML заново.
     */
    private function buildTableConfigs(): void
    {
        foreach ($this->config->getSchemas() as $schema) {
            foreach ($this->config->getFullExport($schema) as $table) {
                $this->buildOne($schema, $table, []);
            }
            foreach ($this->config->getPartialExport($schema) as $table => $conf) {
                $this->buildOne($schema, (string) $table, $conf);
            }
        }
    }

    /**
     * @param array<string, mixed> $conf
     */
    private function buildOne(string $schema, string $table, array $conf): void
    {
        $key = $schema . '.' . $table;
        if (isset($this->tableConfigs[$key]) && empty($conf)) {
            // full_export уже дал объект; partial_export с настройками важнее (случай S-3).
            return;
        }
        try {
            $this->tableConfigs[$key] = TableConfig::fromArray($schema, $table, $conf);
            unset($this->tableConfigErrors[$key]);
        } catch (\Throwable $e) {
            $this->tableConfigErrors[$key] = $e->getMessage();
        }
    }
}
