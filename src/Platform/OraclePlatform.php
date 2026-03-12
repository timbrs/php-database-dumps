<?php

namespace Timbrs\DatabaseDumps\Platform;

use Timbrs\DatabaseDumps\Contract\DatabaseConnectionInterface;
use Timbrs\DatabaseDumps\Contract\DatabasePlatformInterface;

/**
 * Платформа Oracle (12c+)
 */
class OraclePlatform implements DatabasePlatformInterface
{
    public function quoteIdentifier(string $identifier): string
    {
        return '"' . strtoupper($identifier) . '"';
    }

    public function getFullTableName(string $schema, string $table): string
    {
        return $this->quoteIdentifier($schema) . '.' . $this->quoteIdentifier($table);
    }

    public function getTruncateStatement(string $schema, string $table): string
    {
        return 'DELETE FROM ' . $this->getFullTableName($schema, $table) . ';';
    }

    public function getSequenceResetSql(string $schema, string $table, DatabaseConnectionInterface $connection): string
    {
        $fullTable = $this->getFullTableName($schema, $table);

        return "-- Сброс sequences для Oracle не поддерживается автоматически\n"
            . "-- Используйте after_exec/ скрипты для сброса sequences таблицы {$fullTable}\n";
    }

    public function getRandomFunctionSql(): string
    {
        return 'DBMS_RANDOM.VALUE';
    }

    public function getLimitSql(int $limit): string
    {
        return 'FETCH FIRST ' . $limit . ' ROWS ONLY';
    }
}
