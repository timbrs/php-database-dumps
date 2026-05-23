<?php

namespace Timbrs\DatabaseDumps\Service\Importer;

use Timbrs\DatabaseDumps\Contract\ConnectionRegistryInterface;
use Timbrs\DatabaseDumps\Platform\PlatformFactory;

/**
 * Валидация соответствия схемы дампа текущей структуре БД.
 *
 * Все запросы к information_schema/all_tab_columns используют параметризованный
 * SQL (защита от инъекций через имена схем/таблиц).
 */
class SchemaValidator
{
    /** @var ConnectionRegistryInterface */
    private $registry;

    public function __construct(ConnectionRegistryInterface $registry)
    {
        $this->registry = $registry;
    }

    /**
     * @param array<string> $dumpColumns Столбцы из INSERT-выражения дампа
     */
    public function validate(string $schema, string $table, array $dumpColumns, ?string $connectionName = null): ValidationResult
    {
        if (empty($dumpColumns)) {
            return new ValidationResult();
        }

        $dbColumns = $this->getTableColumns($schema, $table, $connectionName);

        if (empty($dbColumns)) {
            // Таблица не найдена в БД
            return new ValidationResult($dumpColumns, []);
        }

        $dbColumnSet = array_flip($dbColumns);
        $dumpColumnSet = array_flip($dumpColumns);

        $missingInDb = [];
        foreach ($dumpColumns as $col) {
            if (!isset($dbColumnSet[$col])) {
                $missingInDb[] = $col;
            }
        }

        $missingInDump = [];
        foreach ($dbColumns as $col) {
            if (!isset($dumpColumnSet[$col])) {
                $missingInDump[] = $col;
            }
        }

        return new ValidationResult($missingInDb, $missingInDump);
    }

    /**
     * @return array<string>
     */
    private function getTableColumns(string $schema, string $table, ?string $connectionName): array
    {
        $connection = $this->registry->getConnection($connectionName);
        $platform = PlatformFactory::canonicalize($connection->getPlatformName());

        if ($platform === PlatformFactory::ORACLE) {
            $sql = "SELECT LOWER(column_name) AS column_name FROM all_tab_columns
                    WHERE owner = :owner AND table_name = :table
                    ORDER BY column_id";
            $params = ['owner' => strtoupper($schema), 'table' => strtoupper($table)];
        } else {
            $sql = "SELECT column_name FROM information_schema.columns
                    WHERE table_schema = :schema AND table_name = :table
                    ORDER BY ordinal_position";
            $params = ['schema' => $schema, 'table' => $table];
        }

        $rows = $connection->fetchAllAssociative($sql, $params);

        $columns = [];
        foreach ($rows as $row) {
            // Поддержка разных регистров ключа (Doctrine/PDO/Laravel)
            $value = $row['column_name'] ?? ($row['COLUMN_NAME'] ?? null);
            if ($value !== null) {
                $columns[] = (string) $value;
            }
        }

        return $columns;
    }
}
