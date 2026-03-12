<?php

namespace Timbrs\DatabaseDumps\Service\Dumper;

use Timbrs\DatabaseDumps\Config\DumpConfig;
use Timbrs\DatabaseDumps\Config\TableConfig;
use Timbrs\DatabaseDumps\Contract\ConnectionRegistryInterface;

class CascadeWhereResolver
{
    private const MAX_DEPTH = 10;

    /** @var ConnectionRegistryInterface */
    private $registry;

    /** @var int Счётчик подзапросов для уникальных алиасов */
    private $subqueryCounter = 0;

    public function __construct(ConnectionRegistryInterface $registry)
    {
        $this->registry = $registry;
    }

    /**
     * Resolve cascade_from into WHERE clause fragment.
     *
     * @return string|null WHERE clause fragment, or null if no cascade needed
     */
    public function resolve(TableConfig $childConfig, DumpConfig $dumpConfig): ?string
    {
        $cascadeFrom = $childConfig->getCascadeFrom();
        if ($cascadeFrom === null || empty($cascadeFrom)) {
            return null;
        }

        $this->subqueryCounter = 0;

        $conditions = [];
        foreach ($cascadeFrom as $entry) {
            $condition = $this->resolveEntry($entry, $dumpConfig, $childConfig->getConnectionName(), 0);
            if ($condition !== null) {
                $conditions[] = $condition;
            }
        }

        if (empty($conditions)) {
            return null;
        }

        return implode(' AND ', $conditions);
    }

    /**
     * @param array{parent: string, fk_column: string, parent_column: string} $entry
     */
    private function resolveEntry(array $entry, DumpConfig $dumpConfig, ?string $connectionName, int $depth): ?string
    {
        if ($depth >= self::MAX_DEPTH) {
            return null;
        }

        $parentKey = $entry['parent']; // "schema.table"
        $fkColumn = $entry['fk_column'];
        $parentColumn = $entry['parent_column'];

        // Parse parent schema.table
        $parts = explode('.', $parentKey, 2);
        if (count($parts) !== 2) {
            return null;
        }
        $parentSchema = $parts[0];
        $parentTable = $parts[1];

        // Check if parent is in full_export — no subquery needed
        $fullTables = $dumpConfig->getFullExportTables($parentSchema);
        if (in_array($parentTable, $fullTables, true)) {
            return null;
        }

        // Check if parent is in partial_export
        $parentTableConfig = $dumpConfig->getTableConfig($parentSchema, $parentTable);
        if ($parentTableConfig === null) {
            // Parent not in config at all — skip
            return null;
        }

        // Build subquery
        $platform = $this->registry->getPlatform($connectionName);
        $fullTableSql = $platform->getFullTableName($parentSchema, $parentTable);

        $subquery = "SELECT {$platform->quoteIdentifier($parentColumn)} FROM {$fullTableSql}";

        // Add parent's WHERE condition
        $parentWhere = isset($parentTableConfig[TableConfig::KEY_WHERE]) ? $parentTableConfig[TableConfig::KEY_WHERE] : null;

        // Check if parent also has cascade_from — recursive resolution
        $parentCascadeWhere = null;
        if (isset($parentTableConfig[TableConfig::KEY_CASCADE_FROM]) && !empty($parentTableConfig[TableConfig::KEY_CASCADE_FROM])) {
            $parentCascadeConditions = [];
            foreach ($parentTableConfig[TableConfig::KEY_CASCADE_FROM] as $parentEntry) {
                $parentCondition = $this->resolveEntry($parentEntry, $dumpConfig, $connectionName, $depth + 1);
                if ($parentCondition !== null) {
                    $parentCascadeConditions[] = $parentCondition;
                }
            }
            if (!empty($parentCascadeConditions)) {
                $parentCascadeWhere = implode(' AND ', $parentCascadeConditions);
            }
        }

        // Combine parent's WHERE and cascade WHERE
        $whereClause = '';
        if ($parentWhere !== null && $parentCascadeWhere !== null) {
            $whereClause = " WHERE ({$parentWhere}) AND ({$parentCascadeWhere})";
        } elseif ($parentWhere !== null) {
            $whereClause = " WHERE {$parentWhere}";
        } elseif ($parentCascadeWhere !== null) {
            $whereClause = " WHERE {$parentCascadeWhere}";
        }

        // Add ORDER BY and LIMIT
        $orderBy = isset($parentTableConfig[TableConfig::KEY_ORDER_BY]) ? $parentTableConfig[TableConfig::KEY_ORDER_BY] : null;
        if ($orderBy !== null) {
            $whereClause .= " ORDER BY {$orderBy}";
        }

        $limit = isset($parentTableConfig[TableConfig::KEY_LIMIT]) ? $parentTableConfig[TableConfig::KEY_LIMIT] : null;
        if ($limit !== null) {
            $whereClause .= ' ' . $platform->getLimitSql((int) $limit);
        }

        $innerSql = $subquery . $whereClause;

        // MySQL/MariaDB не поддерживает LIMIT в подзапросе внутри IN —
        // оборачиваем в дополнительный SELECT
        if ($limit !== null) {
            $platformName = $this->registry->getConnection($connectionName)->getPlatformName();
            if ($platformName === 'mysql' || $platformName === 'mariadb') {
                $alias = '_cascade_' . $this->subqueryCounter;
                $this->subqueryCounter++;
                $innerSql = "SELECT * FROM ({$innerSql}) AS {$alias}";
            }
        }

        return "({$fkColumn} IN ({$innerSql}) OR {$fkColumn} IS NULL)";
    }
}
