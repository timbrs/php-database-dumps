<?php

namespace Timbrs\DatabaseDumps\Platform;

use Timbrs\DatabaseDumps\Contract\DatabaseConnectionInterface;
use Timbrs\DatabaseDumps\Contract\DatabasePlatformInterface;

/**
 * Платформа PostgreSQL
 */
class PostgresPlatform implements DatabasePlatformInterface
{
    public function quoteIdentifier(string $identifier): string
    {
        return '"' . $identifier . '"';
    }

    public function getFullTableName(string $schema, string $table): string
    {
        return $this->quoteIdentifier($schema) . '.' . $this->quoteIdentifier($table);
    }

    public function getTruncateStatement(string $schema, string $table): string
    {
        return 'TRUNCATE TABLE ' . $this->getFullTableName($schema, $table) . ' CASCADE;';
    }

    public function getSequenceResetSql(string $schema, string $table, DatabaseConnectionInterface $connection): string
    {
        $sql = "-- Сброс sequences\n";

        try {
            $sequences = $connection->fetchFirstColumn(
                "SELECT pg_get_serial_sequence(:table_name, column_name) as sequence_name
                 FROM information_schema.columns
                 WHERE table_schema = :schema
                 AND table_name = :table
                 AND column_default LIKE 'nextval%'",
                [
                    'schema' => $schema,
                    'table' => $table,
                    'table_name' => $schema . '.' . $table,
                ]
            );

            foreach ($sequences as $sequence) {
                if ($sequence) {
                    $fullTable = $this->getFullTableName($schema, $table);
                    $sql .= "SELECT setval('{$sequence}', (SELECT COALESCE(MAX(id), 1) FROM {$fullTable}));\n";
                }
            }
        } catch (\Exception $e) {
            $sql .= '-- Ошибка получения sequences: ' . $e->getMessage() . "\n";
        }

        return $sql;
    }

    public function getRandomFunctionSql(): string
    {
        return 'RANDOM()';
    }

    public function getLimitSql(int $limit): string
    {
        return 'LIMIT ' . $limit;
    }
}
