<?php

namespace Timbrs\DatabaseDumps\Service\Generator;

use Timbrs\DatabaseDumps\Contract\ConnectionRegistryInterface;

/**
 * Генерация UPDATE statements для отложенных (deferred) столбцов.
 *
 * При разрыве циклических FK-зависимостей INSERT сначала вставляет NULL
 * в deferred-столбцы, а затем этот генератор создаёт UPDATE для восстановления
 * реальных значений.
 */
class DeferredUpdateGenerator
{
    /** @var ConnectionRegistryInterface */
    private $registry;

    public function __construct(ConnectionRegistryInterface $registry)
    {
        $this->registry = $registry;
    }

    /**
     * Сгенерировать UPDATE statements для восстановления deferred-столбцов
     *
     * @param string $schema
     * @param string $table
     * @param array<int, array{column: string, reference_table: string, reference_column: string}> $deferredColumns
     * @param array<int, array{pk_column: string, pk_value: mixed, column: string, value: mixed}> $deferredValues
     * @param string|null $connectionName
     * @return string
     */
    public function generate(
        string $schema,
        string $table,
        array $deferredColumns,
        array $deferredValues,
        ?string $connectionName = null
    ): string {
        if (empty($deferredValues)) {
            return '';
        }

        $platform = $this->registry->getPlatform($connectionName);
        $connection = $this->registry->getConnection($connectionName);
        $fullTable = $platform->getFullTableName($schema, $table);

        $sql = "\n-- Восстановление отложенных FK-столбцов (разрыв цикла)\n";

        foreach ($deferredValues as $entry) {
            $columnQuoted = $platform->quoteIdentifier($entry['column']);
            $pkColumnQuoted = $platform->quoteIdentifier($entry['pk_column']);

            if ($entry['value'] === null) {
                continue; // Значение и так NULL — пропускаем
            }

            $quotedValue = $connection->quote($entry['value']);
            $quotedPk = $connection->quote($entry['pk_value']);

            $sql .= "UPDATE {$fullTable} SET {$columnQuoted} = {$quotedValue} WHERE {$pkColumnQuoted} = {$quotedPk};\n";
        }

        return $sql;
    }

    /**
     * Потоковая генерация UPDATE statements
     *
     * @param string $schema
     * @param string $table
     * @param array<int, array{column: string, reference_table: string, reference_column: string}> $deferredColumns
     * @param array<int, array{pk_column: string, pk_value: mixed, column: string, value: mixed}> $deferredValues
     * @param string|null $connectionName
     * @return \Generator<string>
     */
    public function generateChunks(
        $schema,
        $table,
        array $deferredColumns,
        array $deferredValues,
        $connectionName = null
    ) {
        $result = $this->generate($schema, $table, $deferredColumns, $deferredValues, $connectionName);
        if ($result !== '') {
            yield $result;
        }
    }
}
