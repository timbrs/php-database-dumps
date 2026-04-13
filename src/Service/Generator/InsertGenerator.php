<?php

namespace Timbrs\DatabaseDumps\Service\Generator;

use Timbrs\DatabaseDumps\Contract\ConnectionRegistryInterface;
use Timbrs\DatabaseDumps\Platform\PlatformFactory;

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

    /**
     * @param ConnectionRegistryInterface $registry
     * @param int $batchSize
     */
    public function __construct(ConnectionRegistryInterface $registry, $batchSize = self::DEFAULT_BATCH_SIZE)
    {
        $this->registry = $registry;
        $this->batchSize = max(1, (int) $batchSize);
    }

    /** @var array<int, array{column: string, reference_table: string, reference_column: string}>|null */
    private $deferredColumns;

    /** @var array<int, array{pk_column: string, pk_value: mixed, column: string, value: mixed}> */
    private $collectedDeferredValues = [];

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
     * Получить собранные deferred-значения (после generate/generateChunks)
     *
     * @return array<int, array{pk_column: string, pk_value: mixed, column: string, value: mixed}>
     */
    public function getCollectedDeferredValues(): array
    {
        return $this->collectedDeferredValues;
    }

    /**
     * Сгенерировать INSERT statements с батчингом
     *
     * @param string $schema
     * @param string $table
     * @param array<array<string, mixed>> $rows
     * @param string|null $connectionName
     * @return string
     */
    public function generate(string $schema, string $table, array $rows, ?string $connectionName = null): string
    {
        if (empty($rows)) {
            return "-- Таблица пуста, нет данных для импорта\n";
        }

        $platform = $this->registry->getPlatform($connectionName);
        $connection = $this->registry->getConnection($connectionName);

        $fullTable = $platform->getFullTableName($schema, $table);
        $platformName = $connection->getPlatformName();
        $isOracle = $platformName === PlatformFactory::ORACLE || $platformName === PlatformFactory::OCI;

        if ($isOracle) {
            return $this->generateOracleInserts($fullTable, $rows, $platform, $connection);
        }

        $batches = array_chunk($rows, $this->batchSize);
        $sql = '';
        $batchNum = 1;

        foreach ($batches as $batch) {
            $sql .= "-- Batch {$batchNum} (" . count($batch) . " rows)\n";
            $sql .= $this->generateBatchInsert($fullTable, $batch, $platform, $connection);
            $sql .= "\n";
            $batchNum++;
        }

        return $sql;
    }

    /**
     * Потоковая генерация INSERT statements по батчам (Generator для экономии памяти)
     *
     * @param string $schema
     * @param string $table
     * @param array<array<string, mixed>> $rows
     * @param string|null $connectionName
     * @return \Generator<string>
     */
    public function generateChunks($schema, $table, array $rows, $connectionName = null)
    {
        if (empty($rows)) {
            yield "-- Таблица пуста, нет данных для импорта\n";
            return;
        }

        $platform = $this->registry->getPlatform($connectionName);
        $connection = $this->registry->getConnection($connectionName);

        $fullTable = $platform->getFullTableName($schema, $table);
        $platformName = $connection->getPlatformName();
        $isOracle = $platformName === PlatformFactory::ORACLE || $platformName === PlatformFactory::OCI;

        if ($isOracle) {
            foreach (array_chunk($rows, $this->batchSize) as $chunk) {
                yield $this->generateOracleInserts($fullTable, $chunk, $platform, $connection);
            }
            return;
        }

        $batchNum = 1;
        foreach (array_chunk($rows, $this->batchSize) as $batch) {
            $sql = "-- Batch {$batchNum} (" . count($batch) . " rows)\n";
            $sql .= $this->generateBatchInsert($fullTable, $batch, $platform, $connection);
            $sql .= "\n";
            yield $sql;
            $batchNum++;
        }
    }

    /**
     * Сгенерировать INSERT для одного батча
     *
     * @param string $fullTable
     * @param array<array<string, mixed>> $rows
     * @param \Timbrs\DatabaseDumps\Contract\DatabasePlatformInterface $platform
     * @param \Timbrs\DatabaseDumps\Contract\DatabaseConnectionInterface $connection
     * @return string
     */
    private function generateBatchInsert(string $fullTable, array $rows, $platform, $connection): string
    {
        if (empty($rows)) {
            return '';
        }

        $columns = array_keys($rows[0]);
        $deferredColumnNames = $this->getDeferredColumnNames();
        $columnsList = implode(', ', array_map(function ($col) use ($platform) {
            return $platform->quoteIdentifier($col);
        }, $columns));

        $sql = "INSERT INTO {$fullTable} ({$columnsList}) VALUES\n";

        $values = [];
        foreach ($rows as $row) {
            $escapedValues = [];
            foreach ($row as $col => $value) {
                if (isset($deferredColumnNames[$col])) {
                    // Deferred-столбец: вставляем NULL, сохраняем оригинальное значение
                    $this->collectDeferredValue($row, $col, $value);
                    $escapedValues[] = 'NULL';
                } elseif ($value === null) {
                    $escapedValues[] = 'NULL';
                } elseif (is_bool($value)) {
                    $escapedValues[] = $value ? 'TRUE' : 'FALSE';
                } else {
                    $escapedValues[] = $connection->quote($value);
                }
            }
            $values[] = '(' . implode(', ', $escapedValues) . ')';
        }

        $sql .= implode(",\n", $values) . ";\n";

        return $sql;
    }

    /**
     * Сгенерировать отдельный INSERT на каждую строку (Oracle не поддерживает multi-row INSERT)
     *
     * @param string $fullTable
     * @param array<array<string, mixed>> $rows
     * @param \Timbrs\DatabaseDumps\Contract\DatabasePlatformInterface $platform
     * @param \Timbrs\DatabaseDumps\Contract\DatabaseConnectionInterface $connection
     * @return string
     */
    private function generateOracleInserts(string $fullTable, array $rows, $platform, $connection): string
    {
        $columns = array_keys($rows[0]);
        $deferredColumnNames = $this->getDeferredColumnNames();
        $columnsList = implode(', ', array_map(function ($col) use ($platform) {
            return $platform->quoteIdentifier($col);
        }, $columns));

        $sql = "-- " . count($rows) . " rows\n";

        foreach ($rows as $row) {
            $escapedValues = [];
            foreach ($row as $col => $value) {
                if (isset($deferredColumnNames[$col])) {
                    $this->collectDeferredValue($row, $col, $value);
                    $escapedValues[] = 'NULL';
                } elseif ($value === null) {
                    $escapedValues[] = 'NULL';
                } elseif (is_bool($value)) {
                    $escapedValues[] = $value ? 'TRUE' : 'FALSE';
                } else {
                    $escapedValues[] = $connection->quote($value);
                }
            }
            $sql .= "INSERT INTO {$fullTable} ({$columnsList}) VALUES (" . implode(', ', $escapedValues) . ");\n";
        }

        return $sql;
    }

    /**
     * Получить map имён deferred-столбцов
     *
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
     * Сохранить оригинальное значение deferred-столбца для последующего UPDATE
     *
     * @param array<string, mixed> $row
     * @param string $col
     * @param mixed $value
     */
    private function collectDeferredValue(array $row, string $col, $value): void
    {
        if ($value === null) {
            return; // Уже NULL — UPDATE не нужен
        }

        // Определяем PK-столбец: берём первый столбец строки (convention: id)
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
