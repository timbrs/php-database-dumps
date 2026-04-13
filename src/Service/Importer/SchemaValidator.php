<?php

namespace Timbrs\DatabaseDumps\Service\Importer;

use Timbrs\DatabaseDumps\Contract\ConnectionRegistryInterface;
use Timbrs\DatabaseDumps\Platform\PlatformFactory;

/**
 * Валидация соответствия схемы дампа текущей структуре БД
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
     * Сравнить столбцы из дампа со столбцами в БД
     *
     * @param string $schema
     * @param string $table
     * @param array<string> $dumpColumns Столбцы из INSERT-выражения дампа
     * @param string|null $connectionName
     * @return ValidationResult
     */
    public function validate(string $schema, string $table, array $dumpColumns, ?string $connectionName = null): ValidationResult
    {
        if (empty($dumpColumns)) {
            return new ValidationResult();
        }

        $dbColumns = $this->getTableColumns($schema, $table, $connectionName);

        if (empty($dbColumns)) {
            // Таблица не найдена в БД — все столбцы дампа отсутствуют
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
     * Получить список столбцов таблицы из БД
     *
     * @return array<string>
     */
    private function getTableColumns(string $schema, string $table, ?string $connectionName): array
    {
        $connection = $this->registry->getConnection($connectionName);
        $platformName = $connection->getPlatformName();

        if ($platformName === PlatformFactory::ORACLE || $platformName === PlatformFactory::OCI) {
            $sql = "SELECT LOWER(column_name) AS column_name FROM all_tab_columns "
                . "WHERE owner = UPPER('" . $schema . "') AND table_name = UPPER('" . $table . "') "
                . "ORDER BY column_id";
        } else {
            $sql = "SELECT column_name FROM information_schema.columns "
                . "WHERE table_schema = '" . $schema . "' AND table_name = '" . $table . "' "
                . "ORDER BY ordinal_position";
        }

        $rows = $connection->fetchAllAssociative($sql);

        $columns = [];
        foreach ($rows as $row) {
            $columns[] = $row['column_name'];
        }

        return $columns;
    }
}
