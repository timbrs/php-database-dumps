<?php

namespace Timbrs\DatabaseDumps\Service\Dumper;

use Timbrs\DatabaseDumps\Config\TableConfig;
use Timbrs\DatabaseDumps\Contract\ConnectionRegistryInterface;
use Timbrs\DatabaseDumps\Contract\DatabaseConnectionInterface;
use Timbrs\DatabaseDumps\Contract\DatabasePlatformInterface;
use Timbrs\DatabaseDumps\Contract\LoggerInterface;
use Timbrs\DatabaseDumps\Service\Analysis\CriteriaValidator;
use Timbrs\DatabaseDumps\Service\ConfigGenerator\PrimaryKeyInspector;

/**
 * Двухфазная выборка по именованным критериям («все фломастеры»).
 *
 * Фаза 1: для каждого критерия sample.criteria выполняется
 *   SELECT <pk> FROM <t> WHERE (<base>) AND (<crit.where>) [ORDER BY <order_by>] LIMIT <crit.limit>
 * и собираются значения первичного ключа отобранных строк. stratify_by
 * разворачивается в по-корзине-на-DISTINCT-значение колонки.
 *
 * Затем id всех корзин объединяются в PHP, дедуплицируются (без повторов строк),
 * опционально обрезаются общим limit-cap.
 *
 * Фаза 2: финальный SELECT * FROM <t> WHERE <pk> IN (...). Значения экранируются
 * через connection->quote(). Многоколоночный PK — через дизъюнкцию равенств
 * ((c1 = a AND c2 = b) OR ...) — портативно для PG/MySQL/Oracle.
 *
 * Выбранные значения PK регистрируются в SelectedPkRegistry для cascade-консистентности.
 */
class SampleQueryBuilder
{
    /** Потолок на число корзин при разворачивании stratify_by. */
    public const MAX_STRATIFY_BUCKETS = 50;

    /** Лимит плоского среза, когда все criteria непригодны, а limit таблицы не задан. */
    public const FALLBACK_LIMIT = 1000;

    /** @var ConnectionRegistryInterface */
    private $registry;

    /** @var PrimaryKeyInspector */
    private $pkInspector;

    /** @var SelectedPkRegistry */
    private $selectedPkRegistry;

    /** @var LoggerInterface|null */
    private $logger;

    public function __construct(
        ConnectionRegistryInterface $registry,
        PrimaryKeyInspector $pkInspector,
        SelectedPkRegistry $selectedPkRegistry,
        LoggerInterface $logger = null
    ) {
        $this->registry = $registry;
        $this->pkInspector = $pkInspector;
        $this->selectedPkRegistry = $selectedPkRegistry;
        $this->logger = $logger;
    }

    /**
     * Построить финальный SELECT фазы 2 и зарегистрировать выбранные PK.
     *
     * @throws \InvalidArgumentException если у таблицы нет первичного ключа
     */
    public function build(TableConfig $config): string
    {
        $connection = $this->registry->getConnection($config->getConnectionName());
        $platform = $this->registry->getPlatform($config->getConnectionName());

        $schema = $config->getSchema();
        $table = $config->getTable();
        $fullTable = $platform->getFullTableName($schema, $table);

        $pkColumns = $this->pkInspector->getPrimaryKeyColumns($schema, $table, $config->getConnectionName());
        if (empty($pkColumns)) {
            throw new \InvalidArgumentException(sprintf(
                'sample requires a primary key, but none was found for %s.%s',
                $schema,
                $table
            ));
        }

        $sample = $config->getSample() ?? [];
        $baseWhere = $config->getWhere();
        $orderBy = $config->getOrderBy();

        $criteria = $this->expandCriteria($config, $sample, $platform, $connection, $fullTable, $baseWhere);

        // Фаза 1: собрать строки PK по каждому критерию.
        /** @var array<int, array<string, mixed>> $selectedRows row = [pkCol => value] */
        $selectedRows = [];
        /** @var array<string, true> $seen ключ дедупа */
        $seen = [];
        $executed = 0;

        foreach ($criteria as $criterion) {
            // Устойчивость: битый criterion (напр. сгенерированный из ORM/DQL с алиасами t1./
            // bind-параметрами, если он всё же просочился в конфиг) НЕ должен ронять экспорт всей
            // таблицы. Ловим ошибку, пропускаем этот criterion, продолжаем остальными.
            try {
                $rows = $this->fetchCriterionRows(
                    $connection,
                    $platform,
                    $fullTable,
                    $pkColumns,
                    $baseWhere,
                    $criterion['where'],
                    $orderBy,
                    (int) $criterion['limit']
                );
            } catch (\Throwable $e) {
                $this->warn(sprintf(
                    'sample: %s — criterion пропущен (WHERE «%s»): %s',
                    $fullTable,
                    $criterion['where'],
                    $this->shortError($e->getMessage())
                ));
                continue;
            }
            $executed++;
            foreach ($rows as $pkRow) {
                $key = $this->rowKey($pkRow, $pkColumns);
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $selectedRows[] = $pkRow;
            }
        }

        // Все критерии упали (или отсеяны), но выборка по критериям задавалась → не выгружаем
        // пустую таблицу: берём плоский срез по base + limit (обычная лимитированная выборка).
        $hadCriteria = isset($sample[TableConfig::SAMPLE_KEY_CRITERIA])
            && is_array($sample[TableConfig::SAMPLE_KEY_CRITERIA])
            && !empty($sample[TableConfig::SAMPLE_KEY_CRITERIA]);
        if ($executed === 0 && empty($selectedRows) && $hadCriteria) {
            $selectedRows = $this->fetchFallbackRows($connection, $platform, $fullTable, $pkColumns, $baseWhere, $orderBy, $config->getLimit());
            $this->warn(sprintf(
                'sample: %s — все criteria непригодны, выгрузка плоским срезом по limit',
                $fullTable
            ));
        }

        // Общий потолок на объединённую выборку.
        // limit валидируется в TableConfig как >= 0, поэтому достаточно проверки на null.
        // limit = 0 трактуется как «обрезать до нуля» → фаза 2 вернёт WHERE 1 = 0.
        $cap = $config->getLimit();
        if ($cap !== null && count($selectedRows) > $cap) {
            $selectedRows = array_slice($selectedRows, 0, $cap);
        }

        $this->recordSelected($schema, $table, $pkColumns, $selectedRows);

        return $this->buildPhase2Sql($connection, $platform, $fullTable, $pkColumns, $selectedRows, $orderBy);
    }

    /**
     * Развернуть criteria + stratify_by в плоский список критериев.
     *
     * @param array<string, mixed> $sample
     * @return array<int, array{where: string, limit: int}>
     */
    private function expandCriteria(
        TableConfig $config,
        array $sample,
        DatabasePlatformInterface $platform,
        DatabaseConnectionInterface $connection,
        string $fullTable,
        ?string $baseWhere
    ): array {
        $result = [];

        $criteria = $sample[TableConfig::SAMPLE_KEY_CRITERIA] ?? [];
        if (is_array($criteria)) {
            $validator = new CriteriaValidator();
            foreach ($criteria as $entry) {
                $where = (string) $entry[TableConfig::CRITERION_KEY_WHERE];
                // Заведомо непригодный (алиас t1./bind-параметр :name) — не тратим на него запрос к БД.
                $problems = $validator->syntaxProblems($where);
                if (!empty($problems)) {
                    $this->warn(sprintf('sample: %s — criterion пропущен (%s): «%s»', $fullTable, implode('; ', $problems), $where));
                    continue;
                }
                $result[] = [
                    'where' => $where,
                    'limit' => (int) $entry[TableConfig::CRITERION_KEY_LIMIT],
                ];
            }
        }

        $stratifyBy = $sample[TableConfig::SAMPLE_KEY_STRATIFY_BY] ?? null;
        if (is_string($stratifyBy) && $stratifyBy !== '') {
            $perValue = isset($sample[TableConfig::SAMPLE_KEY_PER_VALUE])
                ? (int) $sample[TableConfig::SAMPLE_KEY_PER_VALUE]
                : TableConfig::DEFAULT_PER_VALUE;

            $quotedCol = $platform->quoteIdentifier($stratifyBy);
            $distinctSql = "SELECT DISTINCT {$quotedCol} FROM {$fullTable}";
            if ($baseWhere !== null) {
                $distinctSql .= " WHERE ({$baseWhere})";
            }
            $distinctSql .= ' ' . $platform->getLimitSql(self::MAX_STRATIFY_BUCKETS);

            $values = $connection->fetchFirstColumn($distinctSql);
            foreach ($values as $value) {
                if ($value === null) {
                    continue;
                }
                $result[] = [
                    'where' => $quotedCol . ' = ' . $connection->quote($value),
                    'limit' => $perValue,
                ];
            }
        }

        return $result;
    }

    /**
     * Выполнить SELECT PK по одному критерию.
     *
     * @param array<int, string> $pkColumns
     * @return array<int, array<string, mixed>> Список строк PK (row = [pkCol => value])
     */
    private function fetchCriterionRows(
        DatabaseConnectionInterface $connection,
        DatabasePlatformInterface $platform,
        string $fullTable,
        array $pkColumns,
        ?string $baseWhere,
        string $criterionWhere,
        ?string $orderBy,
        int $limit
    ): array {
        $sql = $this->buildPhase1Sql($platform, $fullTable, $pkColumns, $baseWhere, $criterionWhere, $orderBy, $limit);

        if (count($pkColumns) === 1) {
            $pk = $pkColumns[0];
            $values = $connection->fetchFirstColumn($sql);
            $rows = [];
            foreach ($values as $value) {
                $rows[] = [$pk => $value];
            }
            return $rows;
        }

        $assoc = $connection->fetchAllAssociative($sql);
        $rows = [];
        foreach ($assoc as $row) {
            $normalized = array_change_key_case($row, CASE_LOWER);
            $pkRow = [];
            foreach ($pkColumns as $col) {
                $pkRow[$col] = $normalized[strtolower($col)] ?? ($row[$col] ?? null);
            }
            $rows[] = $pkRow;
        }
        return $rows;
    }

    /**
     * Сформировать SQL фазы 1 (выбор PK по критерию).
     *
     * @param array<int, string> $pkColumns
     */
    private function buildPhase1Sql(
        DatabasePlatformInterface $platform,
        string $fullTable,
        array $pkColumns,
        ?string $baseWhere,
        string $criterionWhere,
        ?string $orderBy,
        int $limit
    ): string {
        $quotedCols = [];
        foreach ($pkColumns as $col) {
            $quotedCols[] = $platform->quoteIdentifier($col);
        }
        $sql = 'SELECT ' . implode(', ', $quotedCols) . " FROM {$fullTable}";

        $conditions = [];
        if ($baseWhere !== null) {
            $conditions[] = "({$baseWhere})";
        }
        $conditions[] = "({$criterionWhere})";
        $sql .= ' WHERE ' . implode(' AND ', $conditions);

        if ($orderBy !== null && $orderBy !== '') {
            $sql .= " ORDER BY {$orderBy}";
        }

        $sql .= ' ' . $platform->getLimitSql($limit);

        return $sql;
    }

    /**
     * Сформировать финальный SELECT фазы 2.
     *
     * @param array<int, string> $pkColumns
     * @param array<int, array<string, mixed>> $selectedRows
     */
    private function buildPhase2Sql(
        DatabaseConnectionInterface $connection,
        DatabasePlatformInterface $platform,
        string $fullTable,
        array $pkColumns,
        array $selectedRows,
        ?string $orderBy
    ): string {
        $sql = "SELECT * FROM {$fullTable}";

        if (empty($selectedRows)) {
            // Ни одна строка не отобрана — гарантированно пустой результат.
            return $sql . ' WHERE 1 = 0';
        }

        if (count($pkColumns) === 1) {
            $pk = $platform->quoteIdentifier($pkColumns[0]);
            $col = $pkColumns[0];
            $quotedValues = [];
            foreach ($selectedRows as $row) {
                $quotedValues[] = $connection->quote($row[$col]);
            }
            $sql .= " WHERE {$pk} IN (" . implode(', ', $quotedValues) . ')';
        } else {
            $tuples = [];
            foreach ($selectedRows as $row) {
                $eq = [];
                foreach ($pkColumns as $col) {
                    $eq[] = $platform->quoteIdentifier($col) . ' = ' . $connection->quote($row[$col]);
                }
                $tuples[] = '(' . implode(' AND ', $eq) . ')';
            }
            $sql .= ' WHERE (' . implode(' OR ', $tuples) . ')';
        }

        if ($orderBy !== null && $orderBy !== '') {
            $sql .= " ORDER BY {$orderBy}";
        }

        return $sql;
    }

    /**
     * Зарегистрировать выбранные значения PK для cascade-консистентности.
     *
     * @param array<int, string> $pkColumns
     * @param array<int, array<string, mixed>> $selectedRows
     */
    private function recordSelected(string $schema, string $table, array $pkColumns, array $selectedRows): void
    {
        $columnValues = [];
        foreach ($pkColumns as $col) {
            $columnValues[$col] = [];
        }
        foreach ($selectedRows as $row) {
            foreach ($pkColumns as $col) {
                $columnValues[$col][] = $row[$col];
            }
        }
        $this->selectedPkRegistry->record($schema, $table, $columnValues);

        if ($this->logger !== null) {
            $this->logger->info(sprintf(
                'sample: %s.%s — отобрано %d строк по критериям',
                $schema,
                $table,
                count($selectedRows)
            ));
        }
    }

    /**
     * Плоский срез PK по base + limit — фолбэк, когда все criteria непригодны (чтобы не
     * выгрузить пустую таблицу). Эквивалент обычной лимитированной выборки.
     *
     * @param array<int, string> $pkColumns
     * @return array<int, array<string, mixed>>
     */
    private function fetchFallbackRows(
        DatabaseConnectionInterface $connection,
        DatabasePlatformInterface $platform,
        string $fullTable,
        array $pkColumns,
        ?string $baseWhere,
        ?string $orderBy,
        ?int $limit
    ): array {
        $cap = ($limit !== null && $limit > 0) ? $limit : self::FALLBACK_LIMIT;
        return $this->fetchCriterionRows($connection, $platform, $fullTable, $pkColumns, $baseWhere, '1 = 1', $orderBy, $cap);
    }

    private function warn(string $message): void
    {
        if ($this->logger !== null) {
            $this->logger->warning($message);
        }
    }

    /**
     * Первая строка ошибки БД, схлопнутые пробелы, обрезка — для читаемого лога/промпта.
     */
    private function shortError(string $message): string
    {
        $line = strtok($message, "\n");
        $line = $line === false ? $message : $line;
        $collapsed = preg_replace('/\s+/', ' ', trim($line));
        $line = $collapsed === null ? $line : $collapsed;
        if (function_exists('mb_strlen') && mb_strlen($line) > 200) {
            return mb_substr($line, 0, 200) . '…';
        }
        return strlen($line) > 200 ? substr($line, 0, 200) . '…' : $line;
    }

    /**
     * Ключ дедупликации строки по значениям PK.
     *
     * @param array<string, mixed> $pkRow
     * @param array<int, string> $pkColumns
     */
    private function rowKey(array $pkRow, array $pkColumns): string
    {
        $parts = [];
        foreach ($pkColumns as $col) {
            $value = array_key_exists($col, $pkRow) ? $pkRow[$col] : null;
            // NULL отличаем от пустой строки сентинелом, чтобы не схлопнуть разные строки
            // составного PK (например ['a', null] и ['a', '']).
            if ($value === null) {
                $parts[] = "\x00NULL";
            } else {
                $parts[] = (string) $value;
            }
        }
        return implode("\x1f", $parts);
    }
}
