<?php

namespace Timbrs\DatabaseDumps\Service\ConfigGenerator;

use Timbrs\DatabaseDumps\Contract\ConnectionRegistryInterface;
use Timbrs\DatabaseDumps\Platform\PlatformFactory;
use Timbrs\DatabaseDumps\Service\Db\CodeValueGate;
use Timbrs\DatabaseDumps\Service\Db\PgStatsReader;
use Timbrs\DatabaseDumps\Service\Db\SafeQueryPolicy;

/**
 * Детерминированное профилирование колонок (без ИИ).
 *
 * Для каждой колонки: доля NULL, кардинальность (с потолком), top-значения, признак
 * категориальности (мало различных значений) и коды после шлюза. Категориальные колонки
 * питают авто-генерацию sample.criteria (CriteriaSuggester).
 *
 * Два источника. На PostgreSQL с PgStatsReader профиль берётся из pg_stats — статистика
 * планировщика уже содержит null_frac, n_distinct и самые частые значения, и таблицу ради них
 * читать не нужно; выборка строк делается только для колонок, которых в статистике нет
 * (добавлены после последнего ANALYZE) или если статистики нет вовсе. На остальных платформах
 * и без читателя — как раньше: одна выборка строк, статистика в PHP.
 *
 * Выборка строк никогда не сортирует таблицу целиком: PostgreSQL — TABLESAMPLE (BERNOULLI на
 * небольших, SYSTEM на больших), Oracle — SAMPLE, MySQL на больших — голова таблицы. Таблица
 * неизвестного размера — голова: ни процента выборки, ни стоимости сортировки для неё не знаем.
 */
class ColumnStatisticsInspector
{
    /** Потолок различных значений, при котором колонка считается категориальной. */
    public const MAX_CATEGORICAL_DISTINCT = 50;

    /** Сколько top-значений сохранять в профиле. */
    private const TOP_VALUES_LIMIT = 20;

    public const DEFAULT_SAMPLE_SIZE = 200;

    /**
     * Порог «большой таблицы» без политики: выше него — только блочная выборка.
     * С политикой порог задаёт SafeQueryPolicy::getMaxScanRows().
     */
    public const LARGE_TABLE_ROWS = 50000;

    /** Во сколько раз берём с запасом при нативной выборке (LIMIT потом обрежет до sampleSize). */
    private const SAMPLE_OVERSHOOT = 3;

    /** @var ConnectionRegistryInterface */
    private $registry;

    /** @var int */
    private $sampleSize;

    /** @var SafeQueryPolicy|null */
    private $policy;

    /** @var PgStatsReader|null */
    private $statsReader;

    /**
     * @param int $sampleSize
     */
    public function __construct(
        ConnectionRegistryInterface $registry,
        $sampleSize = self::DEFAULT_SAMPLE_SIZE,
        SafeQueryPolicy $policy = null,
        PgStatsReader $statsReader = null
    ) {
        $this->registry = $registry;
        $this->sampleSize = (int) $sampleSize;
        $this->policy = $policy;
        $this->statsReader = $statsReader;
    }

    /**
     * Профилировать все колонки таблицы.
     *
     * @param int|null $rowCount известное или оценённое число строк: выбирает способ выборки
     *                           и переводит доли из pg_stats в счётчики; null → голова таблицы
     * @return array<int, ColumnProfile>
     */
    public function profileTable(string $schema, string $table, ?string $connectionName = null, ?int $rowCount = null): array
    {
        $columnsMeta = $this->fetchColumns($schema, $table, $connectionName);
        if (empty($columnsMeta)) {
            return [];
        }

        $stats = $this->columnStatsFor($schema, $table, $connectionName);
        if ($rowCount === null && $stats !== []) {
            $rowCount = $this->rowCountFromStats($schema, $table, $connectionName);
        }

        $fromStats = [];
        $needSample = false;
        foreach ($columnsMeta as $name => $meta) {
            $stat = $this->findStat($stats, $name);
            if ($stat !== null) {
                $fromStats[$name] = $stat;
            } else {
                $needSample = true;
            }
        }

        $rows = [];
        $sampleCount = 0;
        if ($needSample) {
            $rows = $this->fetchSampleRows($schema, $table, $connectionName, $rowCount);
            $sampleCount = count($rows);
        }

        $profiles = [];
        foreach ($columnsMeta as $name => $meta) {
            $profiles[] = isset($fromStats[$name])
                ? $this->profileFromStats($name, $meta, $fromStats[$name], $rowCount)
                : $this->profileColumn($name, $meta, $rows, $sampleCount);
        }

        return $profiles;
    }

    /**
     * Профиль из статистики планировщика — таблица не читается.
     *
     * n_distinct > 0 — абсолютное число; < 0 — доля от числа строк; 0 — неизвестно.
     * most_common_vals хранит только частые значения, поэтому codes_complete = false, если их
     * меньше, чем различных значений.
     *
     * @param array{type: string, nullable: bool} $meta
     * @param array<string, mixed> $stat строка pg_stats из PgStatsReader::readColumnStats()
     */
    private function profileFromStats(string $name, array $meta, array $stat, ?int $rows): ColumnProfile
    {
        $nullFraction = max(0.0, min(1.0, isset($stat['null_frac']) && is_numeric($stat['null_frac']) ? (float) $stat['null_frac'] : 0.0));
        $nDistinct = isset($stat['n_distinct']) && is_numeric($stat['n_distinct']) ? (float) $stat['n_distinct'] : 0.0;

        $distinctCapped = false;
        if ($nDistinct > 0) {
            $distinct = (int) round($nDistinct);
        } elseif ($nDistinct < 0) {
            if ($rows !== null) {
                $distinct = (int) round(-$nDistinct * $rows);
            } else {
                // Доля без числа строк — известно лишь, что значений много.
                $distinct = self::MAX_CATEGORICAL_DISTINCT;
                $distinctCapped = true;
            }
        } else {
            $distinct = 0;
        }
        $distinctCapped = $distinctCapped || $distinct >= self::MAX_CATEGORICAL_DISTINCT;

        $nonNull = $rows !== null ? (int) round($rows * (1.0 - $nullFraction)) : null;

        $topValues = [];
        $mcv = isset($stat['most_common_vals']) && is_array($stat['most_common_vals']) ? $stat['most_common_vals'] : [];
        $mcf = isset($stat['most_common_freqs']) && is_array($stat['most_common_freqs']) ? $stat['most_common_freqs'] : [];
        foreach ($mcv as $i => $value) {
            if ($value === null || count($topValues) >= self::TOP_VALUES_LIMIT) {
                continue;
            }
            $freq = isset($mcf[$i]) ? (float) $mcf[$i] : 0.0;
            $topValues[] = [
                'value' => (string) $value,
                'count' => $rows !== null ? (int) round($freq * $rows) : 0,
            ];
        }

        $categorical = $distinct >= 2
            && !$distinctCapped
            && ($nonNull === null || $distinct < $nonNull);

        $codes = null;
        $codesComplete = null;
        if ($mcv !== []) {
            $codes = CodeValueGate::filter($name, $meta['type'], $distinct, $nonNull, $mcv);
            if ($codes !== null) {
                $codesComplete = count($codes) >= $distinct;
            }
        }

        return new ColumnProfile(
            $name,
            $meta['type'],
            $meta['nullable'],
            $nullFraction,
            $distinct,
            $distinctCapped,
            $topValues,
            $categorical,
            $codes,
            ColumnProfile::SOURCE_PG_STATS,
            $codesComplete
        );
    }

    /**
     * @param array{type: string, nullable: bool} $meta
     * @param array<int, array<string, mixed>> $rows
     */
    private function profileColumn(string $name, array $meta, array $rows, int $sampleCount): ColumnProfile
    {
        $nonNull = 0;
        $counts = [];
        foreach ($rows as $row) {
            // Ключи строк могут отличаться регистром — учитываем оба варианта.
            $value = $this->extractValue($row, $name);
            if ($value === null || $value === '') {
                continue;
            }
            $nonNull++;
            // Нормализуем bool к читаемому виду: (string) false === '' схлопнулось бы
            // в пустой (невидимый) ключ. true/false → '1'/'0' как в quoteBoolean.
            if (is_bool($value)) {
                $key = $value ? '1' : '0';
            } else {
                $key = (string) $value;
            }
            $counts[$key] = isset($counts[$key]) ? $counts[$key] + 1 : 1;
        }

        $distinct = count($counts);
        $distinctCapped = $distinct >= self::MAX_CATEGORICAL_DISTINCT;

        // Доля NULL/пустых: пустая строка трактуется как «нет значения» (см. фильтр выше).
        // На пустой выборке (sampleCount === 0) делим на 0 → возвращаем 0.0.
        $nullFraction = $sampleCount > 0 ? ($sampleCount - $nonNull) / $sampleCount : 0.0;

        arsort($counts);
        $topValues = [];
        foreach (array_slice($counts, 0, self::TOP_VALUES_LIMIT, true) as $val => $cnt) {
            $topValues[] = ['value' => (string) $val, 'count' => (int) $cnt];
        }

        // Категориальная = малый набор различных значений, в котором мы уверены.
        // Условия:
        //  - >= 2 (одно значение бесполезно для корзин),
        //  - не достигнут потолок (на distinct == MAX уже не уверены, что нет 51-го),
        //  - distinct < nonNull (значения повторяются — иначе это уникальный ключ).
        $categorical = $distinct >= 2
            && !$distinctCapped
            && $distinct < $nonNull;

        // Коды по выборке: выборка видит все значения своих строк, но не всей таблицы —
        // полными их считать нельзя.
        $codes = null;
        $codesComplete = null;
        if ($counts !== []) {
            $codes = CodeValueGate::filter($name, $meta['type'], $distinct, $nonNull, array_map('strval', array_keys($counts)));
            if ($codes !== null) {
                $codesComplete = false;
            }
        }

        return new ColumnProfile(
            $name,
            $meta['type'],
            $meta['nullable'],
            $nullFraction,
            $distinct,
            $distinctCapped,
            $topValues,
            $categorical,
            $codes,
            ColumnProfile::SOURCE_SAMPLE,
            $codesComplete
        );
    }

    /**
     * @param array<string, mixed> $row
     * @return mixed
     */
    private function extractValue(array $row, string $column)
    {
        if (array_key_exists($column, $row)) {
            return $row[$column];
        }
        $lower = strtolower($column);
        foreach ($row as $key => $value) {
            if (strtolower((string) $key) === $lower) {
                return $value;
            }
        }
        return null;
    }

    /**
     * Статистика колонок таблицы из pg_stats; [] — не PostgreSQL, нет читателя или ANALYZE
     * не проводился.
     *
     * @return array<string, array<string, mixed>> column => stats
     */
    private function columnStatsFor(string $schema, string $table, ?string $connectionName): array
    {
        if ($this->statsReader === null || !$this->statsReader->supports($connectionName)) {
            return [];
        }
        $all = $this->statsReader->readColumnStats($schema, $connectionName);

        return $all[$table] ?? [];
    }

    private function rowCountFromStats(string $schema, string $table, ?string $connectionName): ?int
    {
        if ($this->statsReader === null) {
            return null;
        }
        $stats = $this->statsReader->readTableStats($connectionName);

        return PgStatsReader::estimateRows($stats[$schema . '.' . $table] ?? null)->getValue();
    }

    /**
     * @param array<string, array<string, mixed>> $stats
     * @return array<string, mixed>|null
     */
    private function findStat(array $stats, string $column): ?array
    {
        if (isset($stats[$column])) {
            return $stats[$column];
        }
        $lower = strtolower($column);
        foreach ($stats as $name => $stat) {
            if (strtolower((string) $name) === $lower) {
                return $stat;
            }
        }
        return null;
    }

    /**
     * Список колонок таблицы с типами и nullability.
     *
     * @return array<string, array{type: string, nullable: bool}>
     */
    private function fetchColumns(string $schema, string $table, ?string $connectionName): array
    {
        $connection = $this->registry->getConnection($connectionName);
        $platformName = PlatformFactory::canonicalize($connection->getPlatformName());

        if ($platformName === PlatformFactory::ORACLE) {
            $sql = "SELECT LOWER(column_name) AS column_name, LOWER(data_type) AS data_type, nullable
                    FROM all_tab_columns
                    WHERE owner = :owner AND table_name = :tbl
                    ORDER BY column_id";
            $params = ['owner' => strtoupper($schema), 'tbl' => strtoupper($table)];
        } else {
            $sql = "SELECT column_name, data_type, is_nullable
                    FROM information_schema.columns
                    WHERE table_schema = :schema AND table_name = :tbl
                    ORDER BY ordinal_position";
            $params = ['schema' => $schema, 'tbl' => $table];
        }

        $rows = $connection->fetchAllAssociative($sql, $params);

        $result = [];
        foreach ($rows as $row) {
            $normalized = array_change_key_case($row, CASE_LOWER);
            $name = (string) ($normalized['column_name'] ?? '');
            if ($name === '') {
                continue;
            }
            $type = (string) ($normalized['data_type'] ?? 'unknown');

            if (array_key_exists('nullable', $normalized)) {
                // Oracle: 'Y'/'N'
                $nullable = (strtoupper((string) $normalized['nullable']) === 'Y');
            } else {
                // PG/MySQL: is_nullable 'YES'/'NO'
                $isNullable = (string) ($normalized['is_nullable'] ?? 'NO');
                $nullable = (strtoupper($isNullable) === 'YES');
            }

            $result[$name] = ['type' => $type, 'nullable' => $nullable];
        }

        return $result;
    }

    /**
     * Выбрать ~sampleSize строк для профилирования, не сортируя таблицу целиком.
     *
     * PostgreSQL: TABLESAMPLE BERNOULLI(p) на таблицах в пределах max_scan_rows (построчная,
     * равномерная), SYSTEM(p) на больших (блочная, быстрая); пустой сэмпл (блочная выборка
     * может не попасть ни в один блок) → голова таблицы. Oracle на больших — SAMPLE(p); MySQL
     * нативного сэмпла по проценту не имеет → голова. На небольших таблицах MySQL/Oracle —
     * прежняя случайная выборка, там она дёшева. Таблица неизвестного размера — голова.
     *
     * @return array<int, array<string, mixed>>
     */
    private function fetchSampleRows(string $schema, string $table, ?string $connectionName, ?int $rowCount): array
    {
        $connection = $this->registry->getConnection($connectionName);
        $platform = $this->registry->getPlatform($connectionName);
        $platformName = PlatformFactory::canonicalize($connection->getPlatformName());

        $fullTable = $platform->getFullTableName($schema, $table);
        $limitSql = $platform->getLimitSql($this->sampleSize);
        $head = "SELECT * FROM {$fullTable} {$limitSql}";

        if ($rowCount === null) {
            return $connection->fetchAllAssociative($head);
        }

        $maxScan = $this->policy !== null ? $this->policy->getMaxScanRows() : self::LARGE_TABLE_ROWS;
        $large = $rowCount > $maxScan;

        if ($platformName === PlatformFactory::POSTGRESQL) {
            $method = $large ? 'SYSTEM' : 'BERNOULLI';
            $p = $this->formatPercent($this->samplePercent($rowCount, $large ? 99.9 : 100.0));
            $rows = $connection->fetchAllAssociative("SELECT * FROM {$fullTable} TABLESAMPLE {$method} ({$p}) {$limitSql}");

            return !empty($rows) ? $rows : $connection->fetchAllAssociative($head);
        }

        if ($large) {
            if ($platformName === PlatformFactory::ORACLE) {
                $p = $this->formatPercent($this->samplePercent($rowCount, 99.9));
                $rows = $connection->fetchAllAssociative("SELECT * FROM {$fullTable} SAMPLE ({$p}) {$limitSql}");
                if (!empty($rows)) {
                    return $rows;
                }
            }

            return $connection->fetchAllAssociative($head);
        }

        $randomFunc = $platform->getRandomFunctionSql();

        return $connection->fetchAllAssociative("SELECT * FROM {$fullTable} ORDER BY {$randomFunc} {$limitSql}");
    }

    /**
     * Процент выборки: ~SAMPLE_OVERSHOOT×sampleSize строк из rowCount, зажатый в (0.01 .. $max) —
     * 100 недопустим для Oracle SAMPLE и PG SYSTEM, BERNOULLI принимает и 100.
     */
    private function samplePercent(int $rowCount, float $max): float
    {
        $target = self::SAMPLE_OVERSHOOT * $this->sampleSize;
        $percent = 100.0 * $target / max(1, $rowCount);
        if ($percent < 0.01) {
            $percent = 0.01;
        }
        if ($percent > $max) {
            $percent = $max;
        }
        return $percent;
    }

    /**
     * Печать процента без научной нотации и хвостовых нулей (безопасно для SQL-литерала).
     */
    private function formatPercent(float $percent): string
    {
        $s = rtrim(rtrim(sprintf('%.6f', $percent), '0'), '.');
        return $s === '' ? '0' : $s;
    }
}
