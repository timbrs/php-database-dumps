<?php

namespace Timbrs\DatabaseDumps\Service\Dumper;

use Timbrs\DatabaseDumps\Config\DumpConfig;
use Timbrs\DatabaseDumps\Config\TableConfig;
use Timbrs\DatabaseDumps\Contract\ConnectionRegistryInterface;
use Timbrs\DatabaseDumps\Contract\LoggerInterface;
use Timbrs\DatabaseDumps\Platform\PlatformFactory;

/**
 * Преобразует cascade_from-настройки таблицы в WHERE-фрагмент с подзапросами.
 *
 * Защиты:
 * - fk_column и parent_column квотируются через platform.quoteIdentifier (защита от инъекций).
 * - Защита от циклов FK (visited-set по пути рекурсии): A→B→A не приведёт к экспоненциальному взрыву.
 * - При превышении maxDepth поднимается warning в лог (вместо тихого возврата null,
 *   который мог раньше расширять выборку).
 */
class CascadeWhereResolver
{
    private const DEFAULT_MAX_DEPTH = 10;

    /** @var ConnectionRegistryInterface */
    private $registry;

    /** @var int */
    private $maxDepth;

    /** @var LoggerInterface|null */
    private $logger;

    /** @var SelectedPkRegistry|null */
    private $selectedPkRegistry;

    /** @var int Счётчик подзапросов для уникальных алиасов */
    private $subqueryCounter = 0;

    /**
     * @param int $maxDepth
     */
    public function __construct(
        ConnectionRegistryInterface $registry,
        $maxDepth = self::DEFAULT_MAX_DEPTH,
        LoggerInterface $logger = null,
        SelectedPkRegistry $selectedPkRegistry = null
    ) {
        $this->registry = $registry;
        $this->maxDepth = (int) $maxDepth;
        $this->logger = $logger;
        $this->selectedPkRegistry = $selectedPkRegistry;
    }

    /**
     * Разрешить cascade_from в WHERE-фрагмент.
     *
     * @return string|null WHERE-фрагмент или null если cascade не нужен.
     */
    public function resolve(TableConfig $childConfig, DumpConfig $dumpConfig): ?string
    {
        $cascadeFrom = $childConfig->getCascadeFrom();
        if (empty($cascadeFrom)) {
            return null;
        }

        $this->subqueryCounter = 0;
        $visited = [$childConfig->getFullTableName() => true];

        $conditions = [];
        foreach ($cascadeFrom as $entry) {
            $condition = $this->resolveEntry($entry, $dumpConfig, $childConfig->getConnectionName(), 0, $visited);
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
     * @param array<string, bool> $visited Путь рекурсии (для защиты от циклов)
     */
    private function resolveEntry(
        array $entry,
        DumpConfig $dumpConfig,
        ?string $connectionName,
        int $depth,
        array $visited
    ): ?string {
        if ($depth >= $this->maxDepth) {
            $this->warn("CascadeWhereResolver: достигнут максимальный depth={$this->maxDepth}, "
                . "ветка {$entry['parent']} отброшена. Возможна неполная выборка.");
            return null;
        }

        $parentKey = $entry['parent'];
        $fkColumn = $entry['fk_column'];
        $parentColumn = $entry['parent_column'];

        if (isset($visited[$parentKey])) {
            $this->warn("CascadeWhereResolver: обнаружен цикл FK в пути cascade_from "
                . "(повторное посещение {$parentKey}), ветка отброшена.");
            return null;
        }
        $visited[$parentKey] = true;

        $parts = explode('.', $parentKey, 2);
        if (count($parts) !== 2) {
            return null;
        }
        [$parentSchema, $parentTable] = $parts;

        // Parent в full_export — нет смысла строить подзапрос.
        $fullTables = $dumpConfig->getFullExportTables($parentSchema);
        if (in_array($parentTable, $fullTables, true)) {
            return null;
        }

        $parentTableConfig = $dumpConfig->getTableConfig($parentSchema, $parentTable);
        if ($parentTableConfig === null) {
            return null;
        }

        $platform = $this->registry->getPlatform($connectionName);
        $fullTableSql = $platform->getFullTableName($parentSchema, $parentTable);

        // Квотируем идентификаторы — защита от инъекций через имена колонок из YAML.
        $quotedParentColumn = $platform->quoteIdentifier($parentColumn);
        $quotedFkColumn = $platform->quoteIdentifier($fkColumn);

        // Cascade-консистентность: если у родителя задан sample и значения parent_column
        // уже зарегистрированы (родитель дампится раньше детей) — ссылаемся на ИМЕННО
        // выбранные id, а не повторяем критерии подзапросом.
        if (isset($parentTableConfig[TableConfig::KEY_SAMPLE])) {
            $idSetCondition = $this->buildIdSetCondition(
                $parentSchema,
                $parentTable,
                $parentColumn,
                $quotedFkColumn,
                $connectionName
            );
            if ($idSetCondition !== null) {
                return $idSetCondition;
            }
            $this->warn(sprintf(
                'CascadeWhereResolver: у родителя %s.%s задан sample, но выбранные значения "%s" '
                . 'недоступны — откат к подзапросу (выборка может быть несогласованной).',
                $parentSchema,
                $parentTable,
                $parentColumn
            ));
        }

        $subquery = "SELECT {$quotedParentColumn} FROM {$fullTableSql}";

        $parentWhere = $parentTableConfig[TableConfig::KEY_WHERE] ?? null;

        $parentCascadeWhere = null;
        if (!empty($parentTableConfig[TableConfig::KEY_CASCADE_FROM])) {
            $parentCascadeConditions = [];
            foreach ($parentTableConfig[TableConfig::KEY_CASCADE_FROM] as $parentEntry) {
                $sub = $this->resolveEntry($parentEntry, $dumpConfig, $connectionName, $depth + 1, $visited);
                if ($sub !== null) {
                    $parentCascadeConditions[] = $sub;
                }
            }
            if (!empty($parentCascadeConditions)) {
                $parentCascadeWhere = implode(' AND ', $parentCascadeConditions);
            }
        }

        $whereClause = $this->combineWhere($parentWhere, $parentCascadeWhere);

        $orderBy = $parentTableConfig[TableConfig::KEY_ORDER_BY] ?? null;
        if ($orderBy !== null) {
            $whereClause .= " ORDER BY {$orderBy}";
        }

        $limit = $parentTableConfig[TableConfig::KEY_LIMIT] ?? null;
        if ($limit !== null) {
            $whereClause .= ' ' . $platform->getLimitSql((int) $limit);
        }

        $innerSql = $subquery . $whereClause;

        // MySQL/MariaDB не поддерживает LIMIT в подзапросе внутри IN — оборачиваем.
        if ($limit !== null) {
            $platformName = PlatformFactory::canonicalize(
                $this->registry->getConnection($connectionName)->getPlatformName()
            );
            if ($platformName === PlatformFactory::MYSQL) {
                $alias = '_cascade_' . $this->subqueryCounter;
                $this->subqueryCounter++;
                $innerSql = "SELECT * FROM ({$innerSql}) AS {$alias}";
            }
        }

        return "({$quotedFkColumn} IN ({$innerSql}) OR {$quotedFkColumn} IS NULL)";
    }

    /**
     * Построить условие `fk IN (<выбранные id родителя>)` из реестра выбранных PK.
     *
     * @return string|null null, если реестр недоступен или значения родителя не зарегистрированы.
     */
    private function buildIdSetCondition(
        string $parentSchema,
        string $parentTable,
        string $parentColumn,
        string $quotedFkColumn,
        ?string $connectionName
    ): ?string {
        if ($this->selectedPkRegistry === null) {
            return null;
        }

        $values = $this->selectedPkRegistry->getColumnValues($parentSchema, $parentTable, $parentColumn);
        if ($values === null) {
            return null;
        }

        if (empty($values)) {
            // Родитель не отобрал ни одной строки — у детей нет связанных строк (orphans по IS NULL).
            return "({$quotedFkColumn} IN (NULL) OR {$quotedFkColumn} IS NULL)";
        }

        $connection = $this->registry->getConnection($connectionName);
        $quoted = [];
        foreach ($values as $value) {
            $quoted[] = $connection->quote($value);
        }

        return "({$quotedFkColumn} IN (" . implode(', ', $quoted) . ") OR {$quotedFkColumn} IS NULL)";
    }

    private function combineWhere(?string $parentWhere, ?string $parentCascadeWhere): string
    {
        if ($parentWhere !== null && $parentCascadeWhere !== null) {
            return " WHERE ({$parentWhere}) AND ({$parentCascadeWhere})";
        }
        if ($parentWhere !== null) {
            return " WHERE {$parentWhere}";
        }
        if ($parentCascadeWhere !== null) {
            return " WHERE {$parentCascadeWhere}";
        }
        return '';
    }

    private function warn(string $message): void
    {
        if ($this->logger !== null) {
            $this->logger->warning($message);
        }
    }
}
