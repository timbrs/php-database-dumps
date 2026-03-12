<?php

namespace Timbrs\DatabaseDumps\Service\Generator;

use Timbrs\DatabaseDumps\Contract\ConnectionRegistryInterface;
use Timbrs\DatabaseDumps\Platform\PlatformFactory;

/**
 * Генерация INSERT statements с батчингом
 */
class InsertGenerator
{
    private const BATCH_SIZE = 1000;

    /** @var ConnectionRegistryInterface */
    private $registry;

    public function __construct(ConnectionRegistryInterface $registry)
    {
        $this->registry = $registry;
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

        $batches = array_chunk($rows, self::BATCH_SIZE);
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
            foreach (array_chunk($rows, self::BATCH_SIZE) as $chunk) {
                yield $this->generateOracleInserts($fullTable, $chunk, $platform, $connection);
            }
            return;
        }

        $batchNum = 1;
        foreach (array_chunk($rows, self::BATCH_SIZE) as $batch) {
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
        $columnsList = implode(', ', array_map(function ($col) use ($platform) {
            return $platform->quoteIdentifier($col);
        }, $columns));

        $sql = "INSERT INTO {$fullTable} ({$columnsList}) VALUES\n";

        $values = [];
        foreach ($rows as $row) {
            $escapedValues = [];
            foreach ($row as $value) {
                if ($value === null) {
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
        $columnsList = implode(', ', array_map(function ($col) use ($platform) {
            return $platform->quoteIdentifier($col);
        }, $columns));

        $sql = "-- " . count($rows) . " rows\n";

        foreach ($rows as $row) {
            $escapedValues = [];
            foreach ($row as $value) {
                if ($value === null) {
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
}
