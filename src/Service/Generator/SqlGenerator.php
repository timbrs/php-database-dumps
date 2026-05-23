<?php

namespace Timbrs\DatabaseDumps\Service\Generator;

use Timbrs\DatabaseDumps\Config\TableConfig;

/**
 * Главный генератор SQL дампов
 */
class SqlGenerator
{
    /** @var TruncateGenerator */
    private $truncateGenerator;
    /** @var InsertGenerator */
    private $insertGenerator;
    /** @var SequenceGenerator */
    private $sequenceGenerator;
    /** @var DeferredUpdateGenerator|null */
    private $deferredUpdateGenerator;

    public function __construct(
        TruncateGenerator $truncateGenerator,
        InsertGenerator $insertGenerator,
        SequenceGenerator $sequenceGenerator,
        DeferredUpdateGenerator $deferredUpdateGenerator = null
    ) {
        $this->truncateGenerator = $truncateGenerator;
        $this->insertGenerator = $insertGenerator;
        $this->sequenceGenerator = $sequenceGenerator;
        $this->deferredUpdateGenerator = $deferredUpdateGenerator;
    }

    /**
     * Сгенерировать полный SQL дамп таблицы.
     *
     * @param array<array<string, mixed>> $rows
     */
    public function generate(TableConfig $config, array $rows, ?string $fetchQuery = null): string
    {
        $sql = '';
        foreach ($this->generateChunks($config, $rows, $fetchQuery, count($rows)) as $chunk) {
            $sql .= $chunk;
        }
        return $sql;
    }

    /**
     * Потоковая генерация SQL дампа.
     *
     * @param iterable<array<string, mixed>> $rows массив или Generator (для streaming)
     * @param int|null $rowCountHint Подсказка по количеству строк для шапки (null = "?")
     * @return \Generator<string>
     */
    public function generateChunks(TableConfig $config, iterable $rows, ?string $fetchQuery = null, ?int $rowCountHint = null)
    {
        $schema = $config->getSchema();
        $table = $config->getTable();
        $connectionName = $config->getConnectionName();
        $deferredColumns = $config->getDeferredColumns();

        $header = $this->buildHeader($config, $rowCountHint, $fetchQuery);
        $header .= $this->truncateGenerator->generate($schema, $table, $connectionName);
        $header .= "\n";

        yield $header;

        $this->insertGenerator->setDeferredColumns($deferredColumns);

        foreach ($this->insertGenerator->generateChunks($schema, $table, $rows, $connectionName) as $chunk) {
            yield $chunk;
        }

        $footer = $this->sequenceGenerator->generate($schema, $table, $connectionName);
        if ($footer !== '') {
            yield $footer;
        }

        // Deferred UPDATE (восстановление FK-столбцов после разрыва цикла)
        if ($deferredColumns !== null && $this->deferredUpdateGenerator !== null) {
            $deferredValues = $this->insertGenerator->getCollectedDeferredValues();
            foreach ($this->deferredUpdateGenerator->generateChunks(
                $schema, $table, $deferredColumns, $deferredValues, $connectionName
            ) as $chunk) {
                yield $chunk;
            }
        }

        $this->insertGenerator->setDeferredColumns(null);
    }

    private function buildHeader(TableConfig $config, ?int $rowCount, ?string $fetchQuery): string
    {
        $schema = $config->getSchema();
        $table = $config->getTable();

        $header = "-- Дамп таблицы: {$schema}.{$table}\n";
        $header .= '-- Дата экспорта: ' . date('Y-m-d H:i:s') . "\n";
        if ($rowCount !== null) {
            $header .= "-- Количество записей: {$rowCount}\n";
        }

        if ($config->isPartialExport()) {
            $header .= "-- Режим: partial (limit {$config->getLimit()})\n";
        } else {
            $header .= "-- Режим: full\n";
        }

        // НЕ выводим SELECT с WHERE — может содержать чувствительные значения.
        if ($fetchQuery !== null) {
            $header .= "-- Запрос выполнен через DataFetcher\n";
        }

        $header .= "\n";

        return $header;
    }
}
