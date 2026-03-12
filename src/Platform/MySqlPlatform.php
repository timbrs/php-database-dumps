<?php

namespace Timbrs\DatabaseDumps\Platform;

use Timbrs\DatabaseDumps\Contract\DatabaseConnectionInterface;
use Timbrs\DatabaseDumps\Contract\DatabasePlatformInterface;

/**
 * Платформа MySQL / MariaDB
 */
class MySqlPlatform implements DatabasePlatformInterface
{
    public function quoteIdentifier(string $identifier): string
    {
        return '`' . $identifier . '`';
    }

    public function getFullTableName(string $schema, string $table): string
    {
        return $this->quoteIdentifier($schema) . '.' . $this->quoteIdentifier($table);
    }

    public function getTruncateStatement(string $schema, string $table): string
    {
        $fullTable = $this->getFullTableName($schema, $table);

        return "SET FOREIGN_KEY_CHECKS=0;\nDELETE FROM {$fullTable};";
    }

    public function getSequenceResetSql(string $schema, string $table, DatabaseConnectionInterface $connection): string
    {
        $sql = "-- Сброс AUTO_INCREMENT\n";

        try {
            $fullTable = $this->getFullTableName($schema, $table);
            $sql .= "ALTER TABLE {$fullTable} AUTO_INCREMENT = 1;\n";
        } catch (\Exception $e) {
            $sql .= '-- Ошибка сброса AUTO_INCREMENT: ' . $e->getMessage() . "\n";
        }

        $sql .= "SET FOREIGN_KEY_CHECKS=1;\n";

        return $sql;
    }

    public function getRandomFunctionSql(): string
    {
        return 'RAND()';
    }

    public function getLimitSql(int $limit): string
    {
        return 'LIMIT ' . $limit;
    }
}
