<?php

namespace Timbrs\DatabaseDumps\Service\Dumper;

use Timbrs\DatabaseDumps\Config\TableConfig;
use Timbrs\DatabaseDumps\Contract\ConnectionRegistryInterface;
use Timbrs\DatabaseDumps\Contract\DatabaseConnectionInterface;
use Timbrs\DatabaseDumps\Contract\DatabasePlatformInterface;
use Timbrs\DatabaseDumps\Contract\LoggerInterface;
use Timbrs\DatabaseDumps\Service\Analysis\CriteriaValidator;
use Timbrs\DatabaseDumps\Service\ConfigGenerator\PrimaryKeyInspector;
use Timbrs\DatabaseDumps\Service\ConfigGenerator\TableInspector;
use Timbrs\DatabaseDumps\Service\Db\PgStatsReader;
use Timbrs\DatabaseDumps\Service\Db\SafeQueryPolicy;
use Timbrs\DatabaseDumps\Service\Faker\PatternDetector;

/**
 * Двухфазная выборка по именованным критериям («все фломастеры»).
 *
 * Фаза 1: для каждой корзины выполняется
 *   SELECT <pk[, колонки, на которые ссылаются дети]> FROM <t>
 *   WHERE (<where>) AND (<cascade>) AND (<crit.where>) [ORDER BY <order_by>] LIMIT <crit.limit>
 * и собираются значения отобранных строк. Каскад (cascade_from) входит в базовое условие
 * каждой корзины: «все виды» набираются среди строк, связанных с выгруженным родителем,
 * а не по всей таблице. stratify_by (колонка или список колонок) разворачивается в
 * по-корзине-на-DISTINCT-значение; значения берутся из pg_stats, когда статистики хватает,
 * иначе DISTINCT под потолком и таймаутом сессии.
 *
 * Затем корзины объединяются в PHP без повторов строк. Если общий limit меньше суммы, корзины
 * сливаются round-robin (по строке из каждой по кругу): каждая получает не меньше
 * ⌊limit/корзин⌋ строк, и ни один вид данных не теряется целиком. Квота stratify_by заранее
 * ужимается под limit, а не отрезается с конца.
 *
 * Фаза 2: финальный SELECT * FROM <t> WHERE <pk> IN (...). Значения экранируются
 * через connection->quote(). Многоколоночный PK — через дизъюнкцию равенств
 * ((c1 = a AND c2 = b) OR ...) — портативно для PG/MySQL/Oracle.
 *
 * Выбранные значения PK и ссылочных колонок регистрируются в SelectedPkRegistry: дети
 * с cascade_from по parent_column: core_id найдут именно выбранные core_id, а не подзапрос.
 * Ход выборки (корзины, строки, усечения) пишется в SampleReportCollector.
 */
class SampleQueryBuilder
{
    /** Потолок на число корзин при разворачивании stratify_by. */
    public const MAX_STRATIFY_BUCKETS = 50;

    /** Лимит плоского среза, когда все criteria непригодны, а limit таблицы не задан. */
    public const FALLBACK_LIMIT = 1000;

    /** Ниже этой квоты корзину stratify_by под limit не ужимаем — лучше усечь потолком. */
    public const MIN_PER_VALUE = 5;

    /** Больше стольких значений в одном IN — список режется на несколько IN через OR. */
    public const IN_CHUNK = 1000;

    /** Источники значений stratify_by в отчёте. */
    public const STRATIFY_SOURCE_PG_STATS = 'pg_stats';
    public const STRATIFY_SOURCE_DISTINCT = 'distinct';
    public const STRATIFY_SOURCE_SKIPPED = 'skipped';

    /** Значение корзины показывается в отчёте только если похоже на код и колонка не ПД. */
    private const CODE_VALUE_REGEX = '/^[A-Za-z0-9_.\-]{1,32}$/';

    /** @var ConnectionRegistryInterface */
    private $registry;

    /** @var PrimaryKeyInspector */
    private $pkInspector;

    /** @var SelectedPkRegistry */
    private $selectedPkRegistry;

    /** @var LoggerInterface|null */
    private $logger;

    /** @var PgStatsReader|null */
    private $statsReader;

    /** @var SafeQueryPolicy|null */
    private $policy;

    /** @var TableInspector|null */
    private $inspector;

    /** @var SampleReportCollector|null */
    private $report;

    public function __construct(
        ConnectionRegistryInterface $registry,
        PrimaryKeyInspector $pkInspector,
        SelectedPkRegistry $selectedPkRegistry,
        LoggerInterface $logger = null,
        PgStatsReader $statsReader = null,
        SafeQueryPolicy $policy = null,
        TableInspector $inspector = null,
        SampleReportCollector $report = null
    ) {
        $this->registry = $registry;
        $this->pkInspector = $pkInspector;
        $this->selectedPkRegistry = $selectedPkRegistry;
        $this->logger = $logger;
        $this->statsReader = $statsReader;
        $this->policy = $policy;
        $this->inspector = $inspector;
        $this->report = $report;
    }

    /**
     * Построить финальный SELECT фазы 2 и зарегистрировать выбранные значения.
     *
     * @param string|null        $cascadeWhere    условие cascade_from — входит в базовое условие каждой корзины
     * @param array<int, string> $extraColumns    колонки, на которые ссылаются дети (parent_column) — выбираются
     *                                            в фазе 1 и регистрируются вместе с PK
     * @param int|null           $defaultPerValue квота stratify_by из settings.sample.per_value
     *
     * @throws \InvalidArgumentException если у таблицы нет первичного ключа
     */
    public function build(
        TableConfig $config,
        ?string $cascadeWhere = null,
        array $extraColumns = [],
        ?int $defaultPerValue = null
    ): string {
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
        $selectColumns = $this->mergeColumns($pkColumns, $extraColumns);

        $sample = $config->getSample() ?? [];
        $baseWhere = self::combineWhere($config->getWhere(), $cascadeWhere);
        $orderBy = $config->getOrderBy();
        $cap = $config->getLimit();

        $buckets = $this->expandBuckets($config, $sample, $platform, $connection, $fullTable, $baseWhere, $cascadeWhere !== null, $cap, $defaultPerValue);

        // Фаза 1: строки по каждой корзине — отдельно, чтобы при усечении слить их по кругу.
        /** @var array<int, array<int, array<string, mixed>>> $rowsByBucket */
        $rowsByBucket = [];
        $executed = 0;

        foreach ($buckets as $n => $bucket) {
            // Устойчивость: битый criterion (напр. сгенерированный из ORM/DQL с алиасами t1./
            // bind-параметрами, если он всё же просочился в конфиг) НЕ должен ронять экспорт всей
            // таблицы. Ловим ошибку, пропускаем этот criterion, продолжаем остальными.
            $started = microtime(true);
            try {
                $rows = $this->fetchCriterionRows(
                    $connection,
                    $platform,
                    $fullTable,
                    $selectColumns,
                    $baseWhere,
                    $bucket['where'],
                    $orderBy,
                    (int) $bucket['limit']
                );
            } catch (\Throwable $e) {
                $this->warn(sprintf(
                    'sample: %s — criterion пропущен (WHERE «%s»): %s',
                    $fullTable,
                    $bucket['where'],
                    $this->shortError($e->getMessage())
                ));
                $this->reportBucket($schema, $table, $bucket, null, $started, $this->shortError($e->getMessage()));
                continue;
            }
            $executed++;
            $rowsByBucket[$n] = $rows;
            $this->reportBucket($schema, $table, $bucket, count($rows), $started, null);
            if ($rows === []) {
                $this->warn(sprintf('sample: %s — корзина «%s» пуста: критерий не ловит ни одной строки', $fullTable, $bucket['name']));
            }
        }

        // Все критерии упали (или отсеяны), но выборка по критериям задавалась → не выгружаем
        // пустую таблицу: берём плоский срез по base + limit (обычная лимитированная выборка).
        $hadCriteria = isset($sample[TableConfig::SAMPLE_KEY_CRITERIA])
            && is_array($sample[TableConfig::SAMPLE_KEY_CRITERIA])
            && !empty($sample[TableConfig::SAMPLE_KEY_CRITERIA]);
        $fallback = false;
        if ($executed === 0 && $hadCriteria) {
            $fallback = true;
            $rowsByBucket = [$this->fetchFallbackRows($connection, $platform, $fullTable, $selectColumns, $baseWhere, $orderBy, $cap)];
            $this->warn(sprintf(
                'sample: %s — все criteria непригодны, выгрузка плоским срезом по limit',
                $fullTable
            ));
        }

        // Общий потолок на объединённую выборку.
        // limit валидируется в TableConfig как >= 0, поэтому достаточно проверки на null.
        // limit = 0 трактуется как «обрезать до нуля» → фаза 2 вернёт WHERE 1 = 0.
        $beforeCap = $this->countDistinct($rowsByBucket, $pkColumns);
        $selectedRows = $this->merge($rowsByBucket, $pkColumns, $cap, $beforeCap);

        if ($this->report !== null) {
            $this->report->total($schema, $table, $cap, count($selectedRows), $beforeCap, $fallback);
        }

        $this->recordSelected($schema, $table, $selectColumns, $selectedRows);

        return $this->buildPhase2Sql($connection, $platform, $fullTable, $pkColumns, $selectedRows, $orderBy);
    }

    /**
     * Базовое условие корзины: where таблицы и каскад, оба в скобках.
     */
    public static function combineWhere(?string $where, ?string $cascadeWhere): ?string
    {
        if ($where !== null && $where !== '' && $cascadeWhere !== null && $cascadeWhere !== '') {
            return "({$where}) AND ({$cascadeWhere})";
        }
        if ($where !== null && $where !== '') {
            return $where;
        }
        if ($cascadeWhere !== null && $cascadeWhere !== '') {
            return $cascadeWhere;
        }

        return null;
    }

    /**
     * Развернуть criteria + stratify_by в плоский список корзин.
     *
     * @param array<string, mixed> $sample
     *
     * @return array<int, array{name: string, where: string, limit: int, kind: string, column?: string, value?: string}>
     */
    private function expandBuckets(
        TableConfig $config,
        array $sample,
        DatabasePlatformInterface $platform,
        DatabaseConnectionInterface $connection,
        string $fullTable,
        ?string $baseWhere,
        bool $withCascade,
        ?int $cap,
        ?int $defaultPerValue
    ): array {
        $result = [];

        $criteria = $sample[TableConfig::SAMPLE_KEY_CRITERIA] ?? [];
        if (is_array($criteria)) {
            $validator = new CriteriaValidator();
            foreach ($criteria as $i => $entry) {
                $where = (string) $entry[TableConfig::CRITERION_KEY_WHERE];
                $name = isset($entry[TableConfig::CRITERION_KEY_NAME]) ? (string) $entry[TableConfig::CRITERION_KEY_NAME] : 'criterion#' . $i;
                // Заведомо непригодный (алиас t1./bind-параметр :name) — не тратим на него запрос к БД.
                $problems = $validator->syntaxProblems($where);
                if (!empty($problems)) {
                    $this->warn(sprintf('sample: %s — criterion пропущен (%s): «%s»', $fullTable, implode('; ', $problems), $where));
                    if ($this->report !== null) {
                        $this->report->bucket($config->getSchema(), $config->getTable(), [
                            'name' => $name,
                            'kind' => 'criterion',
                            'limit' => (int) $entry[TableConfig::CRITERION_KEY_LIMIT],
                            'rows' => null,
                            'ms' => 0,
                            'error' => implode('; ', $problems),
                        ]);
                    }
                    continue;
                }
                $result[] = [
                    'name' => $name,
                    'where' => $where,
                    'limit' => (int) $entry[TableConfig::CRITERION_KEY_LIMIT],
                    'kind' => 'criterion',
                ];
            }
        }

        $perValue = isset($sample[TableConfig::SAMPLE_KEY_PER_VALUE])
            ? (int) $sample[TableConfig::SAMPLE_KEY_PER_VALUE]
            : ($defaultPerValue ?? TableConfig::DEFAULT_PER_VALUE);

        foreach (TableConfig::stratifyColumns($sample) as $column) {
            $found = $this->stratifyValues($config, $column, $platform, $connection, $fullTable, $baseWhere, $withCascade);
            $values = [];
            foreach ($found['values'] ?? [] as $value) {
                if ($value !== null) {
                    $values[] = $value;
                }
            }

            // Квота на корзину ужимается под limit заранее: покрытие важнее объёма.
            $quota = $perValue;
            if ($cap !== null && $cap > 0 && $values !== [] && $perValue * count($values) > $cap) {
                $quota = max(self::MIN_PER_VALUE, intdiv($cap, count($values)));
                $this->warn(sprintf(
                    'sample: %s — stratify_by %s: %d корзин × %d > limit %d, квота корзины ужата до %d',
                    $fullTable,
                    $column,
                    count($values),
                    $perValue,
                    $cap,
                    $quota
                ));
            }

            if ($this->report !== null) {
                $this->report->stratify($config->getSchema(), $config->getTable(), [
                    'column' => $column,
                    'source' => $found['source'],
                    'values' => count($values),
                    'truncated' => $found['truncated'],
                    'per_value' => $quota,
                    'reason' => $found['reason'] ?? null,
                ]);
            }

            $quotedCol = $platform->quoteIdentifier($column);
            $showValue = !PatternDetector::hintsPii($column);
            foreach ($values as $i => $value) {
                $bucket = [
                    'name' => $column . '#' . $i,
                    'where' => $quotedCol . ' = ' . $connection->quote($value),
                    'limit' => $quota,
                    'kind' => 'stratify',
                    'column' => $column,
                ];
                if ($showValue && is_scalar($value) && preg_match(self::CODE_VALUE_REGEX, (string) $value) === 1) {
                    $bucket['value'] = (string) $value;
                }
                $result[] = $bucket;
            }
        }

        return $result;
    }

    /**
     * Значения колонки для корзин stratify_by.
     *
     * Без каскада статистика планировщика даёт список бесплатно (когда она полная);
     * иначе DISTINCT с потолком MAX_STRATIFY_BUCKETS + 1 — лишнее значение отличает «ровно
     * потолок» от усечения. На большой таблице без каскада и без статистики DISTINCT —
     * это полный скан, и политика бережного доступа его не разрешает: корзин не будет.
     *
     * @return array{values: array<int, mixed>|null, source: string, truncated: bool, reason?: string}
     */
    private function stratifyValues(
        TableConfig $config,
        string $column,
        DatabasePlatformInterface $platform,
        DatabaseConnectionInterface $connection,
        string $fullTable,
        ?string $baseWhere,
        bool $withCascade
    ): array {
        $schema = $config->getSchema();
        $table = $config->getTable();
        $connectionName = $config->getConnectionName();

        if (!$withCascade && $this->statsReader !== null && $this->statsReader->supports($connectionName)) {
            $stats = $this->statsReader->readColumnStats($schema, $connectionName)[$table][$column] ?? null;
            if ($stats !== null) {
                $distinct = $stats['n_distinct'];
                $mcv = $stats['most_common_vals'];
                // Список полный, только когда частых значений не меньше, чем различных вообще.
                if ($mcv !== null && $distinct > 0 && $distinct <= self::MAX_STRATIFY_BUCKETS && count($mcv) >= (int) ceil($distinct)) {
                    return ['values' => $mcv, 'source' => self::STRATIFY_SOURCE_PG_STATS, 'truncated' => false];
                }
            }
        }

        if (!$withCascade && $this->policy !== null && $this->inspector !== null) {
            $estimate = $this->inspector->estimateRows($schema, $table, $connectionName);
            if ($estimate->isKnown() && !$this->policy->allowsScan($estimate->getValue())) {
                $this->warn(sprintf(
                    'sample: %s — stratify_by %s пропущен: ~%d строк без каскада и без полной статистики, DISTINCT был бы полным сканом',
                    $fullTable,
                    $column,
                    (int) $estimate->getValue()
                ));

                return [
                    'values' => null,
                    'source' => self::STRATIFY_SOURCE_SKIPPED,
                    'truncated' => false,
                    'reason' => 'table_too_large_without_stats',
                ];
            }
        }

        $quotedCol = $platform->quoteIdentifier($column);
        $distinctSql = "SELECT DISTINCT {$quotedCol} FROM {$fullTable}";
        if ($baseWhere !== null) {
            $distinctSql .= " WHERE ({$baseWhere})";
        }
        $distinctSql .= " ORDER BY {$quotedCol} " . $platform->getLimitSql(self::MAX_STRATIFY_BUCKETS + 1);

        $values = $connection->fetchFirstColumn($distinctSql);
        $truncated = count($values) > self::MAX_STRATIFY_BUCKETS;
        if ($truncated) {
            $values = array_slice($values, 0, self::MAX_STRATIFY_BUCKETS);
            $this->warn(sprintf(
                'sample: %s — у stratify_by %s больше %d значений, корзины только по первым %d',
                $fullTable,
                $column,
                self::MAX_STRATIFY_BUCKETS,
                self::MAX_STRATIFY_BUCKETS
            ));
        }

        return ['values' => $values, 'source' => self::STRATIFY_SOURCE_DISTINCT, 'truncated' => $truncated];
    }

    /**
     * Выполнить SELECT по одному критерию.
     *
     * @param array<int, string> $selectColumns PK плюс ссылочные колонки
     * @return array<int, array<string, mixed>> Список строк (row = [column => value])
     */
    private function fetchCriterionRows(
        DatabaseConnectionInterface $connection,
        DatabasePlatformInterface $platform,
        string $fullTable,
        array $selectColumns,
        ?string $baseWhere,
        string $criterionWhere,
        ?string $orderBy,
        int $limit
    ): array {
        $sql = $this->buildPhase1Sql($platform, $fullTable, $selectColumns, $baseWhere, $criterionWhere, $orderBy, $limit);

        if (count($selectColumns) === 1) {
            $column = $selectColumns[0];
            $values = $connection->fetchFirstColumn($sql);
            $rows = [];
            foreach ($values as $value) {
                $rows[] = [$column => $value];
            }
            return $rows;
        }

        $assoc = $connection->fetchAllAssociative($sql);
        $rows = [];
        foreach ($assoc as $row) {
            $normalized = array_change_key_case($row, CASE_LOWER);
            $picked = [];
            foreach ($selectColumns as $col) {
                $picked[$col] = $normalized[strtolower($col)] ?? ($row[$col] ?? null);
            }
            $rows[] = $picked;
        }
        return $rows;
    }

    /**
     * Сформировать SQL фазы 1 (выбор PK и ссылочных колонок по критерию).
     *
     * @param array<int, string> $selectColumns
     */
    private function buildPhase1Sql(
        DatabasePlatformInterface $platform,
        string $fullTable,
        array $selectColumns,
        ?string $baseWhere,
        string $criterionWhere,
        ?string $orderBy,
        int $limit
    ): string {
        $quotedCols = [];
        foreach ($selectColumns as $col) {
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
            $inLists = [];
            foreach (array_chunk($quotedValues, self::IN_CHUNK) as $chunk) {
                $inLists[] = "{$pk} IN (" . implode(', ', $chunk) . ')';
            }
            $sql .= count($inLists) === 1
                ? ' WHERE ' . $inLists[0]
                : ' WHERE (' . implode(' OR ', $inLists) . ')';
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
     * Объединить корзины без повторов. Если всё помещается в потолок — подряд, как
     * перечислены; если нет — по кругу (по строке из каждой корзины), чтобы усечение
     * не съело последние корзины целиком.
     *
     * @param array<int, array<int, array<string, mixed>>> $rowsByBucket
     * @param array<int, string> $pkColumns
     * @return array<int, array<string, mixed>>
     */
    private function merge(array $rowsByBucket, array $pkColumns, ?int $cap, int $distinctTotal): array
    {
        $selected = [];
        $seen = [];

        if ($cap === null || $distinctTotal <= $cap) {
            foreach ($rowsByBucket as $rows) {
                foreach ($rows as $row) {
                    $key = $this->rowKey($row, $pkColumns);
                    if (isset($seen[$key])) {
                        continue;
                    }
                    $seen[$key] = true;
                    $selected[] = $row;
                }
            }

            return $selected;
        }

        if ($cap <= 0) {
            return [];
        }

        $buckets = array_values($rowsByBucket);
        $longest = 0;
        foreach ($buckets as $rows) {
            $longest = max($longest, count($rows));
        }
        for ($i = 0; $i < $longest; $i++) {
            foreach ($buckets as $rows) {
                if (!isset($rows[$i])) {
                    continue;
                }
                $key = $this->rowKey($rows[$i], $pkColumns);
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $selected[] = $rows[$i];
                if (count($selected) >= $cap) {
                    return $selected;
                }
            }
        }

        return $selected;
    }

    /**
     * @param array<int, array<int, array<string, mixed>>> $rowsByBucket
     * @param array<int, string> $pkColumns
     */
    private function countDistinct(array $rowsByBucket, array $pkColumns): int
    {
        $seen = [];
        foreach ($rowsByBucket as $rows) {
            foreach ($rows as $row) {
                $seen[$this->rowKey($row, $pkColumns)] = true;
            }
        }

        return count($seen);
    }

    /**
     * Зарегистрировать выбранные значения PK и ссылочных колонок для cascade-консистентности.
     * Ссылочная колонка у версионных таблиц повторяется (core_id у каждой версии) —
     * в реестр идут уникальные значения.
     *
     * @param array<int, string> $columns
     * @param array<int, array<string, mixed>> $selectedRows
     */
    private function recordSelected(string $schema, string $table, array $columns, array $selectedRows): void
    {
        $columnValues = [];
        foreach ($columns as $col) {
            $columnValues[$col] = [];
        }
        foreach ($selectedRows as $row) {
            foreach ($columns as $col) {
                $columnValues[$col][] = array_key_exists($col, $row) ? $row[$col] : null;
            }
        }
        foreach ($columnValues as $col => $values) {
            $columnValues[$col] = array_values(array_unique($values, SORT_REGULAR));
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
     * Плоский срез по base + limit — фолбэк, когда все criteria непригодны (чтобы не
     * выгрузить пустую таблицу). Эквивалент обычной лимитированной выборки.
     *
     * @param array<int, string> $selectColumns
     * @return array<int, array<string, mixed>>
     */
    private function fetchFallbackRows(
        DatabaseConnectionInterface $connection,
        DatabasePlatformInterface $platform,
        string $fullTable,
        array $selectColumns,
        ?string $baseWhere,
        ?string $orderBy,
        ?int $limit
    ): array {
        $cap = ($limit !== null && $limit > 0) ? $limit : self::FALLBACK_LIMIT;
        return $this->fetchCriterionRows($connection, $platform, $fullTable, $selectColumns, $baseWhere, '1 = 1', $orderBy, $cap);
    }

    /**
     * PK плюс ссылочные колонки, без дублей (регистр имён не важен).
     *
     * @param array<int, string> $pkColumns
     * @param array<int, string> $extraColumns
     * @return array<int, string>
     */
    private function mergeColumns(array $pkColumns, array $extraColumns): array
    {
        $result = [];
        $seen = [];
        foreach (array_merge($pkColumns, $extraColumns) as $column) {
            $column = (string) $column;
            $lower = strtolower($column);
            if ($column === '' || isset($seen[$lower])) {
                continue;
            }
            $seen[$lower] = true;
            $result[] = $column;
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $bucket
     */
    private function reportBucket(string $schema, string $table, array $bucket, ?int $rows, float $started, ?string $error): void
    {
        if ($this->report === null) {
            return;
        }
        $entry = [
            'name' => $bucket['name'],
            'kind' => $bucket['kind'],
            'limit' => $bucket['limit'],
            'rows' => $rows,
            'ms' => (int) round((microtime(true) - $started) * 1000),
        ];
        if (isset($bucket['column'])) {
            $entry['column'] = $bucket['column'];
        }
        if (isset($bucket['value'])) {
            $entry['value'] = $bucket['value'];
        }
        if ($error !== null) {
            $entry['error'] = $error;
        }
        $this->report->bucket($schema, $table, $entry);
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
