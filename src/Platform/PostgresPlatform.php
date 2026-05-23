<?php

namespace Timbrs\DatabaseDumps\Platform;

use Timbrs\DatabaseDumps\Contract\DatabaseConnectionInterface;
use Timbrs\DatabaseDumps\Contract\LoggerInterface;

/**
 * Платформа PostgreSQL
 */
class PostgresPlatform extends AbstractPlatform
{
    /** @var LoggerInterface|null */
    private $logger;

    public function __construct(LoggerInterface $logger = null)
    {
        $this->logger = $logger;
    }

    protected function getIdentifierQuote(): string
    {
        return '"';
    }

    public function getTruncateStatement(string $schema, string $table): string
    {
        return 'TRUNCATE TABLE ' . $this->getFullTableName($schema, $table) . ' CASCADE;';
    }

    /**
     * Генерирует SETVAL для всех sequences таблицы (включая IDENTITY-колонки PG 10+).
     *
     * - Идентификатор колонки подставляется через quoteIdentifier (защита от инъекций).
     * - Имя sequence экранируется через одинарные кавычки (по правилам Postgres).
     * - MAX берётся по фактической PK/serial-колонке, а не по жёсткому 'id'.
     */
    public function getSequenceResetSql(string $schema, string $table, DatabaseConnectionInterface $connection): string
    {
        $sql = "-- Сброс sequences\n";

        try {
            // Запрос покрывает SERIAL/BIGSERIAL и GENERATED ... AS IDENTITY (PG 10+).
            $rows = $connection->fetchAllAssociative(
                "SELECT column_name AS column_name,
                        pg_get_serial_sequence(:table_name, column_name) AS sequence_name
                 FROM information_schema.columns
                 WHERE table_schema = :schema
                   AND table_name = :table
                   AND (column_default LIKE 'nextval(%' OR is_identity = 'YES')",
                [
                    'schema' => $schema,
                    'table' => $table,
                    'table_name' => $schema . '.' . $table,
                ]
            );

            $fullTable = $this->getFullTableName($schema, $table);

            foreach ($rows as $row) {
                $sequenceName = isset($row['sequence_name']) ? $row['sequence_name'] : null;
                $columnName = isset($row['column_name']) ? $row['column_name'] : null;

                if (!$sequenceName || !$columnName) {
                    continue;
                }

                $quotedColumn = $this->quoteIdentifier($columnName);
                // Экранируем одиночную кавычку в имени sequence (защита от инъекции через имена)
                $escapedSequence = str_replace("'", "''", $sequenceName);

                $sql .= "SELECT setval('{$escapedSequence}', "
                    . "(SELECT COALESCE(MAX({$quotedColumn}), 1) FROM {$fullTable}));\n";
            }
        } catch (\Exception $e) {
            if ($this->logger !== null) {
                $this->logger->warning(
                    'Ошибка получения sequences для ' . $schema . '.' . $table . ': ' . $e->getMessage()
                );
            }
            // В сам SQL пишем только статичный комментарий — без сообщения исключения,
            // чтобы не утекали детали драйвера/данных в файлы дампа.
            $sql .= "-- Не удалось получить список sequences (детали в логе)\n";
        }

        return $sql;
    }

    public function getRandomFunctionSql(): string
    {
        return 'RANDOM()';
    }
}
