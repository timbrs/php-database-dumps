<?php

namespace Timbrs\DatabaseDumps\Service\Generator;

use Timbrs\DatabaseDumps\Contract\ConnectionRegistryInterface;
use Timbrs\DatabaseDumps\Contract\DatabaseConnectionInterface;
use Timbrs\DatabaseDumps\Contract\DatabasePlatformInterface;

/**
 * Генерация INSERT statements с батчингом
 */
class InsertGenerator
{
    private const DEFAULT_BATCH_SIZE = 1000;

    /** @var ConnectionRegistryInterface */
    private $registry;

    /** @var int<1, max> */
    private $batchSize;

    /** @var array<int, array{column: string, reference_table: string, reference_column: string}>|null */
    private $deferredColumns;

    /** @var array<int, array{pk_column: string, pk_value: mixed, column: string, value: mixed}> */
    private $collectedDeferredValues = [];

    /**
     * @param int $batchSize
     */
    public function __construct(ConnectionRegistryInterface $registry, $batchSize = self::DEFAULT_BATCH_SIZE)
    {
        $this->registry = $registry;
        $this->batchSize = max(1, (int) $batchSize);
    }

    /**
     * Установить deferred-столбцы (будут заменены на NULL в INSERT)
     *
     * @param array<int, array{column: string, reference_table: string, reference_column: string}>|null $deferredColumns
     */
    public function setDeferredColumns(?array $deferredColumns): void
    {
        $this->deferredColumns = $deferredColumns;
        $this->collectedDeferredValues = [];
    }

    /**
     * @return array<int, array{pk_column: string, pk_value: mixed, column: string, value: mixed}>
     */
    public function getCollectedDeferredValues(): array
    {
        return $this->collectedDeferredValues;
    }

    /**
     * Сгенерировать INSERT statements с батчингом (целиком в строку).
     *
     * @param iterable<array<string, mixed>> $rows массив или Generator
     */
    public function generate(string $schema, string $table, iterable $rows, ?string $connectionName = null): string
    {
        $sql = '';
        foreach ($this->generateChunks($schema, $table, $rows, $connectionName) as $chunk) {
            $sql .= $chunk;
        }
        return $sql;
    }

    /**
     * Потоковая генерация INSERT statements по батчам (Generator для экономии памяти).
     *
     * @param iterable<array<string, mixed>> $rows массив или Generator
     * @return \Generator<string>
     */
    public function generateChunks($schema, $table, iterable $rows, $connectionName = null)
    {
        $platform = $this->registry->getPlatform($connectionName);
        $connection = $this->registry->getConnection($connectionName);
        $fullTable = $platform->getFullTableName($schema, $table);

        $batchNum = 1;
        $isFirstBatch = true;
        $supportsMultiRow = $platform->supportsMultiRowInsert();

        foreach ($this->batchedRows($rows) as $batch) {
            if ($isFirstBatch && empty($batch)) {
                yield "-- Таблица пуста, нет данных для импорта\n";
                return;
            }
            $isFirstBatch = false;

            if (empty($batch)) {
                continue;
            }

            $columns = array_keys($batch[0]);
            $deferredColumnNames = $this->getDeferredColumnNames();
            $columnsList = $this->buildColumnsList($columns, $platform);

            $header = "-- Batch {$batchNum} (" . count($batch) . " rows)\n";
            $batchNum++;

            if ($supportsMultiRow) {
                $values = [];
                foreach ($batch as $row) {
                    $values[] = '(' . implode(', ', $this->escapeRow($row, $platform, $connection, $deferredColumnNames)) . ')';
                }
                yield $header
                    . "INSERT INTO {$fullTable} ({$columnsList}) VALUES\n"
                    . implode(",\n", $values) . ";\n\n";
            } else {
                $sql = $header;
                foreach ($batch as $row) {
                    $escaped = $this->escapeRow($row, $platform, $connection, $deferredColumnNames);
                    $sql .= "INSERT INTO {$fullTable} ({$columnsList}) VALUES (" . implode(', ', $escaped) . ");\n";
                }
                yield $sql . "\n";
            }
        }

        if ($isFirstBatch) {
            yield "-- Таблица пуста, нет данных для импорта\n";
        }
    }

    /**
     * Разбить выборку (массив или итератор) на батчи по batchSize строк.
     *
     * @param iterable<array<string, mixed>> $rows
     * @return \Generator<int, array<int, array<string, mixed>>>
     */
    private function batchedRows(iterable $rows): \Generator
    {
        if (is_array($rows)) {
            foreach (array_chunk($rows, $this->batchSize) as $batch) {
                yield $batch;
            }
            return;
        }

        $buffer = [];
        foreach ($rows as $row) {
            $buffer[] = $row;
            if (count($buffer) >= $this->batchSize) {
                yield $buffer;
                $buffer = [];
            }
        }
        if (!empty($buffer)) {
            yield $buffer;
        }
    }

    /**
     * @param array<int, string> $columns
     */
    private function buildColumnsList(array $columns, DatabasePlatformInterface $platform): string
    {
        $parts = [];
        foreach ($columns as $col) {
            $parts[] = $platform->quoteIdentifier($col);
        }
        return implode(', ', $parts);
    }

    /**
     * Экранировать одну строку, учитывая deferred-столбцы и платформо-зависимый boolean.
     *
     * @param array<string, mixed> $row
     * @param array<string, true> $deferredColumnNames
     * @return array<int, string>
     */
    private function escapeRow(
        array $row,
        DatabasePlatformInterface $platform,
        DatabaseConnectionInterface $connection,
        array $deferredColumnNames
    ): array {
        $escaped = [];
        foreach ($row as $col => $value) {
            if (isset($deferredColumnNames[$col])) {
                $this->collectDeferredValue($row, $col, $value);
                $escaped[] = 'NULL';
            } elseif ($value === null) {
                $escaped[] = 'NULL';
            } elseif (is_bool($value)) {
                $escaped[] = $platform->quoteBoolean($value);
            } else {
                $escaped[] = $connection->quote($value);
            }
        }
        return $escaped;
    }

    /**
     * @return array<string, true>
     */
    private function getDeferredColumnNames(): array
    {
        if ($this->deferredColumns === null) {
            return [];
        }
        $names = [];
        foreach ($this->deferredColumns as $dc) {
            $names[$dc['column']] = true;
        }
        return $names;
    }

    /**
     * Сохранить оригинальное значение deferred-столбца для последующего UPDATE.
     *
     * @param array<string, mixed> $row
     * @param mixed $value
     */
    private function collectDeferredValue(array $row, string $col, $value): void
    {
        if ($value === null) {
            return;
        }

        // PK: первый столбец строки (по соглашению — id).
        // Если PK иной, нужна доработка через TableInspector — см. документацию.
        $columns = array_keys($row);
        $pkColumn = $columns[0];

        $this->collectedDeferredValues[] = [
            'pk_column' => $pkColumn,
            'pk_value' => $row[$pkColumn],
            'column' => $col,
            'value' => $value,
        ];
    }
}
