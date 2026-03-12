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

    public function __construct(
        TruncateGenerator $truncateGenerator,
        InsertGenerator $insertGenerator,
        SequenceGenerator $sequenceGenerator
    ) {
        $this->truncateGenerator = $truncateGenerator;
        $this->insertGenerator = $insertGenerator;
        $this->sequenceGenerator = $sequenceGenerator;
    }

    /**
     * Сгенерировать полный SQL дамп таблицы
     *
     * @param TableConfig $config
     * @param array<array<string, mixed>> $rows
     * @param string|null $fetchQuery SQL-запрос, использованный для выборки данных
     * @return string
     */
    public function generate(TableConfig $config, array $rows, ?string $fetchQuery = null): string
    {
        $schema = $config->getSchema();
        $table = $config->getTable();
        $connectionName = $config->getConnectionName();

        $sql = $this->buildHeader($config, count($rows), $fetchQuery);

        // TRUNCATE
        $sql .= $this->truncateGenerator->generate($schema, $table, $connectionName);
        $sql .= "\n";

        // INSERT
        $sql .= $this->insertGenerator->generate($schema, $table, $rows, $connectionName);

        // Sequence reset
        $sql .= $this->sequenceGenerator->generate($schema, $table, $connectionName);

        return $sql;
    }

    /**
     * Потоковая генерация SQL дампа таблицы (Generator для экономии памяти)
     *
     * @param TableConfig $config
     * @param array<array<string, mixed>> $rows
     * @param string|null $fetchQuery SQL-запрос, использованный для выборки данных
     * @return \Generator<string>
     */
    public function generateChunks(TableConfig $config, array $rows, ?string $fetchQuery = null)
    {
        $schema = $config->getSchema();
        $table = $config->getTable();
        $connectionName = $config->getConnectionName();

        $header = $this->buildHeader($config, count($rows), $fetchQuery);

        $header .= $this->truncateGenerator->generate($schema, $table, $connectionName);
        $header .= "\n";

        yield $header;

        foreach ($this->insertGenerator->generateChunks($schema, $table, $rows, $connectionName) as $chunk) {
            yield $chunk;
        }

        $footer = $this->sequenceGenerator->generate($schema, $table, $connectionName);
        if ($footer !== '') {
            yield $footer;
        }
    }

    /**
     * Сформировать шапку SQL-дампа
     *
     * @param TableConfig $config
     * @param int $rowCount
     * @param string|null $fetchQuery
     * @return string
     */
    private function buildHeader(TableConfig $config, int $rowCount, ?string $fetchQuery): string
    {
        $schema = $config->getSchema();
        $table = $config->getTable();

        $header = "-- Дамп таблицы: {$schema}.{$table}\n";
        $header .= "-- Дата экспорта: " . date('Y-m-d H:i:s') . "\n";
        $header .= "-- Количество записей: {$rowCount}\n";

        if ($config->isPartialExport()) {
            $header .= "-- Режим: partial (limit {$config->getLimit()})\n";
        } else {
            $header .= "-- Режим: full\n";
        }

        if ($fetchQuery !== null) {
            $header .= "-- Запрос: {$fetchQuery}\n";
        }

        $header .= "\n";

        return $header;
    }
}
