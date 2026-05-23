<?php

namespace Timbrs\DatabaseDumps\Service\ConfigGenerator;

use Timbrs\DatabaseDumps\Contract\ConnectionRegistryInterface;
use Timbrs\DatabaseDumps\Platform\PlatformFactory;

/**
 * Сбор информации о FK-связях между таблицами для всех поддерживаемых СУБД.
 *
 * Особенности:
 * - PostgreSQL: используется JOIN через kcu.position_in_unique_constraint и
 *   ccu.column_name по позиции — это корректно работает для composite FK
 *   (без декартова произведения).
 * - MySQL: добавлен фильтр REFERENCED_TABLE_NAME IS NOT NULL (защита от попадания
 *   PK/UNIQUE в результат на отдельных сборках).
 * - Oracle: JOIN по cc.position = rc.position сохраняет соответствие колонок.
 * - getForeignKeyNullability: один батч-запрос вместо N+1 (защита от лавины
 *   round-trip на больших схемах).
 */
class ForeignKeyInspector
{
    /** @var ConnectionRegistryInterface */
    private $registry;

    public function __construct(ConnectionRegistryInterface $registry)
    {
        $this->registry = $registry;
    }

    /**
     * @return array<int, array{constraint_name: string, source_schema: string, source_table: string, source_column: string, target_schema: string, target_table: string, target_column: string}>
     */
    public function getForeignKeys(?string $connectionName = null): array
    {
        $connection = $this->registry->getConnection($connectionName);
        $platform = PlatformFactory::canonicalize($connection->getPlatformName());

        if ($platform === PlatformFactory::POSTGRESQL) {
            // Используем kcu.position_in_unique_constraint для сопоставления
            // колонок source/target по позиции — корректно для composite FK.
            $sql = "SELECT
    tc.constraint_name AS constraint_name,
    tc.table_schema AS source_schema,
    tc.table_name AS source_table,
    kcu.column_name AS source_column,
    fk.target_schema AS target_schema,
    fk.target_table AS target_table,
    fk.target_column AS target_column
FROM information_schema.table_constraints tc
JOIN information_schema.key_column_usage kcu
    ON tc.constraint_name = kcu.constraint_name
    AND tc.table_schema = kcu.table_schema
JOIN (
    SELECT
        rc.constraint_name,
        rc.constraint_schema,
        kcu2.table_schema AS target_schema,
        kcu2.table_name AS target_table,
        kcu2.column_name AS target_column,
        kcu2.ordinal_position AS target_position
    FROM information_schema.referential_constraints rc
    JOIN information_schema.key_column_usage kcu2
        ON kcu2.constraint_name = rc.unique_constraint_name
        AND kcu2.constraint_schema = rc.unique_constraint_schema
) fk
    ON fk.constraint_name = tc.constraint_name
    AND fk.constraint_schema = tc.constraint_schema
    AND fk.target_position = kcu.position_in_unique_constraint
WHERE tc.constraint_type = 'FOREIGN KEY'
    AND tc.table_schema NOT IN ('pg_catalog', 'information_schema')
ORDER BY tc.table_schema, tc.table_name, tc.constraint_name, kcu.ordinal_position";
            $rows = $connection->fetchAllAssociative($sql);
        } elseif ($platform === PlatformFactory::ORACLE) {
            $sql = "SELECT
    LOWER(c.constraint_name) AS constraint_name,
    LOWER(c.owner) AS source_schema,
    LOWER(c.table_name) AS source_table,
    LOWER(cc.column_name) AS source_column,
    LOWER(r.owner) AS target_schema,
    LOWER(r.table_name) AS target_table,
    LOWER(rc.column_name) AS target_column
FROM all_constraints c
JOIN all_cons_columns cc ON c.constraint_name = cc.constraint_name AND c.owner = cc.owner
JOIN all_constraints r ON c.r_constraint_name = r.constraint_name AND c.r_owner = r.owner
JOIN all_cons_columns rc ON r.constraint_name = rc.constraint_name AND r.owner = rc.owner AND cc.position = rc.position
WHERE c.constraint_type = 'R'
    AND c.owner NOT IN ('SYS','SYSTEM','OUTLN','DBSNMP','APPQOSSYS','WMSYS','CTXSYS','XDB','ORDDATA','ORDSYS','MDSYS','OLAPSYS')
ORDER BY c.owner, c.table_name, c.constraint_name, cc.position";
            $rows = $connection->fetchAllAssociative($sql);
        } else {
            // MySQL/MariaDB: добавлен IS NOT NULL для устойчивости.
            $sql = "SELECT
    tc.CONSTRAINT_NAME AS constraint_name,
    tc.TABLE_SCHEMA AS source_schema,
    tc.TABLE_NAME AS source_table,
    kcu.COLUMN_NAME AS source_column,
    kcu.REFERENCED_TABLE_SCHEMA AS target_schema,
    kcu.REFERENCED_TABLE_NAME AS target_table,
    kcu.REFERENCED_COLUMN_NAME AS target_column
FROM information_schema.TABLE_CONSTRAINTS tc
JOIN information_schema.KEY_COLUMN_USAGE kcu
    ON tc.CONSTRAINT_NAME = kcu.CONSTRAINT_NAME
    AND tc.TABLE_SCHEMA = kcu.TABLE_SCHEMA
    AND tc.TABLE_NAME = kcu.TABLE_NAME
WHERE tc.CONSTRAINT_TYPE = 'FOREIGN KEY'
    AND kcu.REFERENCED_TABLE_NAME IS NOT NULL
    AND tc.TABLE_SCHEMA NOT IN ('information_schema', 'mysql', 'performance_schema', 'sys')
ORDER BY tc.TABLE_SCHEMA, tc.TABLE_NAME, tc.CONSTRAINT_NAME, kcu.ORDINAL_POSITION";
            $rows = $connection->fetchAllAssociative($sql);
        }

        $results = [];
        foreach ($rows as $row) {
            $results[] = [
                'constraint_name' => (string) ($row['constraint_name'] ?? ''),
                'source_schema' => (string) ($row['source_schema'] ?? ''),
                'source_table' => (string) ($row['source_table'] ?? ''),
                'source_column' => (string) ($row['source_column'] ?? ''),
                'target_schema' => (string) ($row['target_schema'] ?? ''),
                'target_table' => (string) ($row['target_table'] ?? ''),
                'target_column' => (string) ($row['target_column'] ?? ''),
            ];
        }
        return $results;
    }

    /**
     * Получить is_nullable для FK-столбцов. Один батч-запрос (нет N+1).
     *
     * @param array<int, array{source_schema: string, source_table: string, source_column: string}> $foreignKeys
     * @return array<string, bool> key = "schema.table.column", value = is_nullable
     */
    public function getForeignKeyNullability(array $foreignKeys, ?string $connectionName = null): array
    {
        if (empty($foreignKeys)) {
            return [];
        }

        $connection = $this->registry->getConnection($connectionName);
        $platform = PlatformFactory::canonicalize($connection->getPlatformName());

        $columns = [];
        foreach ($foreignKeys as $fk) {
            $key = $fk['source_schema'] . '.' . $fk['source_table'] . '.' . $fk['source_column'];
            $columns[$key] = $fk;
        }

        $schemas = [];
        foreach ($columns as $fk) {
            $schemas[$fk['source_schema']] = true;
        }
        $schemasList = array_keys($schemas);

        $result = [];

        if ($platform === PlatformFactory::ORACLE) {
            // Oracle: один запрос с IN-списком owner'ов
            $owners = [];
            foreach ($schemasList as $s) {
                $owners[] = strtoupper($s);
            }
            $placeholders = [];
            $params = [];
            foreach ($owners as $i => $val) {
                $key = 'o' . $i;
                $placeholders[] = ':' . $key;
                $params[$key] = $val;
            }
            $sql = "SELECT LOWER(owner) AS owner, LOWER(table_name) AS table_name,
                           LOWER(column_name) AS column_name, nullable
                    FROM all_tab_columns
                    WHERE owner IN (" . implode(',', $placeholders) . ")";
            $rows = $connection->fetchAllAssociative($sql, $params);

            $byKey = [];
            foreach ($rows as $row) {
                $k = $row['owner'] . '.' . $row['table_name'] . '.' . $row['column_name'];
                $byKey[$k] = ($row['nullable'] === 'Y');
            }
            foreach ($columns as $key => $_) {
                $result[$key] = $byKey[$key] ?? false;
            }
        } else {
            // PG/MySQL: один запрос через IN-список schemas
            $placeholders = [];
            $params = [];
            foreach ($schemasList as $i => $val) {
                $key = 's' . $i;
                $placeholders[] = ':' . $key;
                $params[$key] = $val;
            }
            $sql = "SELECT table_schema, table_name, column_name, is_nullable
                    FROM information_schema.columns
                    WHERE table_schema IN (" . implode(',', $placeholders) . ")";
            $rows = $connection->fetchAllAssociative($sql, $params);

            $byKey = [];
            foreach ($rows as $row) {
                // Doctrine/Laravel/PDO могут возвращать разный регистр ключей
                $s = $row['table_schema'] ?? ($row['TABLE_SCHEMA'] ?? '');
                $t = $row['table_name'] ?? ($row['TABLE_NAME'] ?? '');
                $c = $row['column_name'] ?? ($row['COLUMN_NAME'] ?? '');
                $n = $row['is_nullable'] ?? ($row['IS_NULLABLE'] ?? 'NO');
                $k = $s . '.' . $t . '.' . $c;
                $byKey[$k] = ($n === 'YES' || $n === 'yes');
            }
            foreach ($columns as $key => $_) {
                $result[$key] = $byKey[$key] ?? false;
            }
        }

        return $result;
    }
}
