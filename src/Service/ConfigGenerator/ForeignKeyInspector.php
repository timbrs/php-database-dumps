<?php

namespace Timbrs\DatabaseDumps\Service\ConfigGenerator;

use Timbrs\DatabaseDumps\Contract\ConnectionRegistryInterface;
use Timbrs\DatabaseDumps\Platform\PlatformFactory;

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
        $platform = $connection->getPlatformName();

        if ($platform === PlatformFactory::POSTGRESQL || $platform === PlatformFactory::PGSQL) {
            $sql = "SELECT
    tc.constraint_name,
    tc.table_schema AS source_schema,
    tc.table_name AS source_table,
    kcu.column_name AS source_column,
    ccu.table_schema AS target_schema,
    ccu.table_name AS target_table,
    ccu.column_name AS target_column
FROM information_schema.table_constraints tc
JOIN information_schema.key_column_usage kcu
    ON tc.constraint_name = kcu.constraint_name
    AND tc.table_schema = kcu.table_schema
JOIN information_schema.constraint_column_usage ccu
    ON ccu.constraint_name = tc.constraint_name
    AND ccu.table_schema = tc.constraint_schema
WHERE tc.constraint_type = 'FOREIGN KEY'
    AND tc.table_schema NOT IN ('pg_catalog', 'information_schema')
ORDER BY tc.table_schema, tc.table_name, tc.constraint_name";
        } elseif ($platform === PlatformFactory::ORACLE || $platform === PlatformFactory::OCI) {
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
ORDER BY c.owner, c.table_name, c.constraint_name";
        } else {
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
    AND tc.TABLE_SCHEMA NOT IN ('information_schema', 'mysql', 'performance_schema', 'sys')
ORDER BY tc.TABLE_SCHEMA, tc.TABLE_NAME, tc.CONSTRAINT_NAME";
        }

        /** @var array<int, array{constraint_name: string, source_schema: string, source_table: string, source_column: string, target_schema: string, target_table: string, target_column: string}> $results */
        $results = $connection->fetchAllAssociative($sql);

        // Filter out self-referential FKs
        return array_values(array_filter($results, function ($row) {
            return !($row['source_table'] === $row['target_table'] && $row['source_schema'] === $row['target_schema']);
        }));
    }
}
