<?php

namespace Timbrs\DatabaseDumps\Platform;

use Timbrs\DatabaseDumps\Contract\DatabaseConnectionInterface;
use Timbrs\DatabaseDumps\Contract\LoggerInterface;

/**
 * Платформа MySQL / MariaDB
 */
class MySqlPlatform extends AbstractPlatform
{
    /** @var LoggerInterface|null */
    private $logger;

    public function __construct(LoggerInterface $logger = null)
    {
        $this->logger = $logger;
    }

    protected function getIdentifierQuote(): string
    {
        return '`';
    }

    /**
     * MySQL: TRUNCATE = неявный COMMIT, что ломает атомарность импорта.
     * Используем DELETE FROM в одной транзакции с импортом.
     * FOREIGN_KEY_CHECKS управляется централизованно в DatabaseImporter (try/finally).
     */
    public function getTruncateStatement(string $schema, string $table): string
    {
        $fullTable = $this->getFullTableName($schema, $table);

        return "DELETE FROM {$fullTable};";
    }

    public function getSequenceResetSql(string $schema, string $table, DatabaseConnectionInterface $connection): string
    {
        $sql = "-- Сброс AUTO_INCREMENT\n";

        try {
            $fullTable = $this->getFullTableName($schema, $table);

            // Найти AUTO_INCREMENT-колонку (если есть)
            $autoIncColumn = $connection->fetchAllAssociative(
                "SELECT COLUMN_NAME AS column_name
                 FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = :schema
                   AND TABLE_NAME = :table
                   AND EXTRA LIKE '%auto_increment%'
                 LIMIT 1",
                ['schema' => $schema, 'table' => $table]
            );

            if (empty($autoIncColumn)) {
                $sql .= "-- AUTO_INCREMENT-колонка не найдена\n";
                return $sql;
            }

            $column = $autoIncColumn[0]['column_name'];
            $quotedColumn = $this->quoteIdentifier($column);

            // ALTER TABLE ... AUTO_INCREMENT = N (без подзапросов в MySQL).
            // Рассчитываем следующее значение из текущих данных.
            $maxRow = $connection->fetchAllAssociative(
                "SELECT COALESCE(MAX({$quotedColumn}), 0) + 1 AS next_value FROM {$fullTable}"
            );
            $nextValue = isset($maxRow[0]['next_value']) ? (int) $maxRow[0]['next_value'] : 1;

            $sql .= "ALTER TABLE {$fullTable} AUTO_INCREMENT = {$nextValue};\n";
        } catch (\Exception $e) {
            if ($this->logger !== null) {
                $this->logger->warning(
                    'Ошибка сброса AUTO_INCREMENT для ' . $schema . '.' . $table . ': ' . $e->getMessage()
                );
            }
            $sql .= "-- Не удалось сбросить AUTO_INCREMENT (детали в логе)\n";
        }

        return $sql;
    }

    public function getRandomFunctionSql(): string
    {
        return 'RAND()';
    }

    public function quoteBoolean(bool $value): string
    {
        return $value ? '1' : '0';
    }

    public function disableForeignKeysSql(): ?string
    {
        return 'SET FOREIGN_KEY_CHECKS=0';
    }

    public function enableForeignKeysSql(): ?string
    {
        return 'SET FOREIGN_KEY_CHECKS=1';
    }
}
