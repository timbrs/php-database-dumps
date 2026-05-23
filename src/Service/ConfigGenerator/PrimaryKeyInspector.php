<?php

namespace Timbrs\DatabaseDumps\Service\ConfigGenerator;

use Timbrs\DatabaseDumps\Contract\ConnectionRegistryInterface;
use Timbrs\DatabaseDumps\Platform\PlatformFactory;

/**
 * Определение колонок первичного ключа таблицы для всех поддерживаемых СУБД.
 *
 * Нужен для дедупликации строк при выборке по именованным критериям
 * (SampleQueryBuilder): по PK строится IN-список в фазе 2.
 *
 * Источники:
 * - PostgreSQL/MySQL: information_schema.table_constraints + key_column_usage.
 * - Oracle: all_constraints + all_cons_columns (constraint_type = 'P').
 *
 * Параметризованные запросы; имена схем/таблиц передаются как bound-параметры.
 */
class PrimaryKeyInspector
{
    /** @var ConnectionRegistryInterface */
    private $registry;

    /** @var array<string, array<int, string>> Кэш по ключу "conn:schema.table" */
    private $cache = [];

    public function __construct(ConnectionRegistryInterface $registry)
    {
        $this->registry = $registry;
    }

    /**
     * Получить упорядоченный список колонок первичного ключа.
     *
     * @return array<int, string> Имена колонок (в порядке следования в ключе). Пустой массив — PK не найден.
     */
    public function getPrimaryKeyColumns(string $schema, string $table, ?string $connectionName = null): array
    {
        $cacheKey = ($connectionName ?? '__default__') . ':' . $schema . '.' . $table;
        if (isset($this->cache[$cacheKey])) {
            return $this->cache[$cacheKey];
        }

        $connection = $this->registry->getConnection($connectionName);
        $platform = PlatformFactory::canonicalize($connection->getPlatformName());

        if ($platform === PlatformFactory::POSTGRESQL) {
            $sql = "SELECT kcu.column_name AS column_name
                    FROM information_schema.table_constraints tc
                    JOIN information_schema.key_column_usage kcu
                        ON tc.constraint_name = kcu.constraint_name
                        AND tc.table_schema = kcu.table_schema
                    WHERE tc.constraint_type = 'PRIMARY KEY'
                        AND tc.table_schema = :schema
                        AND tc.table_name = :table
                    ORDER BY kcu.ordinal_position";
            $params = ['schema' => $schema, 'table' => $table];
        } elseif ($platform === PlatformFactory::ORACLE) {
            $sql = "SELECT LOWER(cc.column_name) AS column_name
                    FROM all_constraints c
                    JOIN all_cons_columns cc
                        ON c.constraint_name = cc.constraint_name AND c.owner = cc.owner
                    WHERE c.constraint_type = 'P'
                        AND c.owner = :owner
                        AND c.table_name = :tbl
                    ORDER BY cc.position";
            $params = ['owner' => strtoupper($schema), 'tbl' => strtoupper($table)];
        } else {
            // MySQL/MariaDB
            $sql = "SELECT kcu.COLUMN_NAME AS column_name
                    FROM information_schema.TABLE_CONSTRAINTS tc
                    JOIN information_schema.KEY_COLUMN_USAGE kcu
                        ON tc.CONSTRAINT_NAME = kcu.CONSTRAINT_NAME
                        AND tc.TABLE_SCHEMA = kcu.TABLE_SCHEMA
                        AND tc.TABLE_NAME = kcu.TABLE_NAME
                    WHERE tc.CONSTRAINT_TYPE = 'PRIMARY KEY'
                        AND tc.TABLE_SCHEMA = :schema
                        AND tc.TABLE_NAME = :tbl
                    ORDER BY kcu.ORDINAL_POSITION";
            $params = ['schema' => $schema, 'tbl' => $table];
        }

        $rows = $connection->fetchAllAssociative($sql, $params);

        $columns = [];
        foreach ($rows as $row) {
            $normalized = array_change_key_case($row, CASE_LOWER);
            $value = $normalized['column_name'] ?? null;
            if ($value !== null && $value !== '') {
                $columns[] = (string) $value;
            }
        }

        $this->cache[$cacheKey] = $columns;
        return $columns;
    }
}
