<?php

namespace Timbrs\DatabaseDumps\Service\ConfigGenerator;

use Timbrs\DatabaseDumps\Contract\ConnectionRegistryInterface;
use Timbrs\DatabaseDumps\Contract\DatabaseConnectionInterface;
use Timbrs\DatabaseDumps\Platform\PlatformFactory;

/**
 * Инспекция БД: список таблиц, подсчёт строк, определение колонки сортировки.
 *
 * Все запросы — параметризованные. Имена схем/таблиц для FROM-clause квотируются
 * через platform.getFullTableName (защита от инъекций через имена идентификаторов).
 */
class TableInspector
{
    /** @var ConnectionRegistryInterface */
    private $registry;

    public function __construct(ConnectionRegistryInterface $registry)
    {
        $this->registry = $registry;
    }

    /**
     * @return array<int, array{table_schema: string, table_name: string}>
     */
    public function listTables(?string $connectionName = null): array
    {
        $connection = $this->registry->getConnection($connectionName);
        return $this->listTablesFor($connection);
    }

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
