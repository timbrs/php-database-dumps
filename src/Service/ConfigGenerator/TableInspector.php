<?php

namespace Timbrs\DatabaseDumps\Service\ConfigGenerator;

use Timbrs\DatabaseDumps\Contract\ConnectionRegistryInterface;
use Timbrs\DatabaseDumps\Contract\DatabaseConnectionInterface;
use Timbrs\DatabaseDumps\Platform\PlatformFactory;
use Timbrs\DatabaseDumps\Service\Db\PgStatsReader;
use Timbrs\DatabaseDumps\Service\Db\RowEstimate;

/**
 * Инспекция БД: список таблиц, подсчёт строк, определение колонки сортировки.
 *
 * Строки считаются двумя способами. countRows() — точный COUNT(*), полный проход по таблице;
 * на боевой базе он допустим только для небольших таблиц. estimateRows() — оценка из каталога
 * (pg_class, information_schema.tables, all_tables), дёшево для любой таблицы. Кто из них
 * уместен — решает RowCounter.
 *
 * Все запросы — параметризованные. Имена схем/таблиц для FROM-clause квотируются
 * через platform.getFullTableName (защита от инъекций через имена идентификаторов).
 */
class TableInspector
{
    /** @var ConnectionRegistryInterface */
    private $registry;

    /** @var PgStatsReader|null */
    private $statsReader;

    public function __construct(ConnectionRegistryInterface $registry, PgStatsReader $statsReader = null)
    {
        $this->registry = $registry;
        $this->statsReader = $statsReader;
    }

    /**
     * @return array<int, array{table_schema: string, table_name: string}>
     */
    public function listTables(?string $connectionName = null): array
    {
        $connection = $this->registry->getConnection($connectionName);
        return $this->listTablesFor($connection);
    }

    /**
     * Точное число строк: COUNT(*) — полный проход по таблице.
     */
    public function countRows(string $schema, string $table, ?string $connectionName = null): int
    {
        $connection = $this->registry->getConnection($connectionName);
        $platform = $this->registry->getPlatform($connectionName);

        // Безопасное квотирование через platform (удваивает внутренние кавычки)
        $fullTable = $platform->getFullTableName($schema, $table);
        $sql = "SELECT COUNT(*) AS cnt FROM {$fullTable}";

        $rows = $connection->fetchAllAssociative($sql);
        $row = array_change_key_case($rows[0] ?? [], CASE_LOWER);

        return (int) ($row['cnt'] ?? 0);
    }

    /**
     * Оценка числа строк из каталога — без чтения самой таблицы.
     *
     * PostgreSQL: pg_class.reltuples / pg_stat_user_tables.n_live_tup (одним батч-запросом на
     * всё подключение); MySQL: information_schema.tables.table_rows; Oracle: all_tables.num_rows.
     * Если статистики нет (таблица ни разу не анализировалась), размер неизвестен — и это
     * ответ, а не повод посчитать точно.
     */
    public function estimateRows(string $schema, string $table, ?string $connectionName = null): RowEstimate
    {
        $connection = $this->registry->getConnection($connectionName);
        $platformName = PlatformFactory::canonicalize($connection->getPlatformName());

        if ($platformName === PlatformFactory::POSTGRESQL) {
            $stats = $this->statsReader()->readTableStats($connectionName);

            return PgStatsReader::estimateRows($stats[$schema . '.' . $table] ?? null);
        }

        if ($platformName === PlatformFactory::ORACLE) {
            $rows = $connection->fetchAllAssociative(
                "SELECT num_rows FROM all_tables WHERE owner = :owner AND table_name = :table",
                ['owner' => strtoupper($schema), 'table' => strtoupper($table)]
            );

            return $this->estimateFromRow($rows, 'num_rows', RowEstimate::SOURCE_ALL_TABLES);
        }

        $rows = $connection->fetchAllAssociative(
            "SELECT table_rows FROM information_schema.tables WHERE table_schema = :schema AND table_name = :table",
            ['schema' => $schema, 'table' => $table]
        );

        return $this->estimateFromRow($rows, 'table_rows', RowEstimate::SOURCE_INFORMATION_SCHEMA);
    }

    /**
     * Определить колонку для ORDER BY (с направлением DESC).
     * Приоритет: updated_at → update_at → created_at → create_at → id → первая колонка.
     */
    public function detectOrderColumn(string $schema, string $table, ?string $connectionName = null): string
    {
        $connection = $this->registry->getConnection($connectionName);
        $platformName = PlatformFactory::canonicalize($connection->getPlatformName());

        if ($platformName === PlatformFactory::ORACLE) {
            $sql = "SELECT LOWER(column_name) AS column_name FROM all_tab_columns
                    WHERE owner = :owner AND table_name = :table
                    ORDER BY column_id";
            $params = ['owner' => strtoupper($schema), 'table' => strtoupper($table)];
        } else {
            $sql = "SELECT column_name FROM information_schema.columns
                    WHERE table_schema = :schema AND table_name = :table
                    ORDER BY ordinal_position";
            $params = ['schema' => $schema, 'table' => $table];
        }

        $rows = $connection->fetchAllAssociative($sql, $params);

        $columns = [];
        foreach ($rows as $row) {
            $value = $row['column_name'] ?? ($row['COLUMN_NAME'] ?? null);
            if ($value !== null) {
                $columns[] = (string) $value;
            }
        }

        $priority = ['updated_at', 'update_at', 'created_at', 'create_at', 'id'];

        foreach ($priority as $candidate) {
            if (in_array($candidate, $columns, true)) {
                return $candidate . ' DESC';
            }
        }

        if (!empty($columns)) {
            return $columns[0] . ' DESC';
        }

        return 'id DESC';
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    private function estimateFromRow(array $rows, string $column, string $source): RowEstimate
    {
        $row = array_change_key_case($rows[0] ?? [], CASE_LOWER);
        if (!isset($row[$column]) || !is_numeric($row[$column])) {
            return RowEstimate::unknown();
        }

        return new RowEstimate((int) $row[$column], true, $source);
    }

    private function statsReader(): PgStatsReader
    {
        if ($this->statsReader === null) {
            $this->statsReader = new PgStatsReader($this->registry);
        }

        return $this->statsReader;
    }

    /**
     * @return array<int, array{table_schema: string, table_name: string}>
     */
    private function listTablesFor(DatabaseConnectionInterface $connection): array
    {
        $platform = PlatformFactory::canonicalize($connection->getPlatformName());

        if ($platform === PlatformFactory::POSTGRESQL) {
            $sql = "SELECT table_schema, table_name FROM information_schema.tables
                    WHERE table_schema NOT IN ('pg_catalog', 'information_schema')
                      AND table_type = 'BASE TABLE'
                    ORDER BY table_schema, table_name";
        } elseif ($platform === PlatformFactory::ORACLE) {
            $sql = "SELECT LOWER(owner) AS table_schema, LOWER(table_name) AS table_name FROM all_tables
                    WHERE owner NOT IN ('SYS','SYSTEM','OUTLN','DBSNMP','APPQOSSYS','WMSYS','CTXSYS','XDB','ORDDATA','ORDSYS','MDSYS','OLAPSYS')
                    ORDER BY owner, table_name";
        } else {
            $sql = "SELECT table_schema, table_name FROM information_schema.tables
                    WHERE table_schema NOT IN ('information_schema', 'mysql', 'performance_schema', 'sys')
                      AND table_type = 'BASE TABLE'
                    ORDER BY table_schema, table_name";
        }

        $rows = $connection->fetchAllAssociative($sql);

        // Нормализация регистра ключей (Doctrine/Laravel/PDO дают разный регистр)
        $result = [];
        foreach ($rows as $row) {
            $normalized = array_change_key_case($row, CASE_LOWER);
            $result[] = [
                'table_schema' => (string) ($normalized['table_schema'] ?? ''),
                'table_name' => (string) ($normalized['table_name'] ?? ''),
            ];
        }
        return $result;
    }
}
