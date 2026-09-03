<?php

namespace Timbrs\DatabaseDumps\Service\Db;

use Timbrs\DatabaseDumps\Contract\ConnectionRegistryInterface;
use Timbrs\DatabaseDumps\Platform\PlatformFactory;

/**
 * Статистика планировщика PostgreSQL вместо чтения самих данных.
 *
 * Два батч-запроса на всю базу (pg_class + pg_stat_user_tables) и на схему (pg_stats) заменяют
 * COUNT(*), DISTINCT и случайную выборку по каждой таблице: размер, доля NULL, кардинальность и
 * самые частые значения уже посчитаны ANALYZE'ом, читать таблицу ради них не нужно.
 *
 * Два предела, о которых обязан помнить потребитель:
 *  - reltuples = -1 (PG 14+) означает «ни разу не анализировалась»: оценки нет, и подменять её
 *    COUNT(*) на боевой базе нельзя — это находка P-1, а не повод посчитать самим;
 *  - most_common_vals хранит только частые значения; редкое значение в этом списке отсутствует,
 *    что не значит, что его нет в таблице.
 *
 * Значения most_common_vals — настоящие данные, в том числе ПД. Наружу они выходят только через
 * CodeValueGate; всё остальное остаётся внутри процесса.
 */
class PgStatsReader
{
    private const TABLE_STATS_SQL = "SELECT n.nspname AS table_schema,
               c.relname AS table_name,
               c.reltuples::float8 AS reltuples,
               c.relpages,
               s.n_live_tup,
               s.last_analyze::text AS last_analyze,
               s.last_autoanalyze::text AS last_autoanalyze,
               has_table_privilege(c.oid, 'SELECT') AS can_select
        FROM pg_class c
        JOIN pg_namespace n ON n.oid = c.relnamespace
        LEFT JOIN pg_stat_user_tables s ON s.relid = c.oid
        WHERE c.relkind IN ('r', 'p')
          AND n.nspname NOT IN ('pg_catalog', 'information_schema')
          AND n.nspname NOT LIKE 'pg_toast%'";

    private const COLUMN_STATS_SQL = "SELECT tablename, attname, null_frac, n_distinct, avg_width,
               most_common_vals::text AS mcv,
               most_common_freqs::text AS mcf,
               histogram_bounds::text AS hb
        FROM pg_stats
        WHERE schemaname = :schema";

    private const COLUMN_PRIVILEGES_SQL = "SELECT c.relname AS table_name,
               a.attname AS column_name,
               has_column_privilege(c.oid, a.attnum, 'SELECT') AS can_select
        FROM pg_attribute a
        JOIN pg_class c ON c.oid = a.attrelid
        JOIN pg_namespace n ON n.oid = c.relnamespace
        WHERE n.nspname = :schema
          AND a.attnum > 0
          AND NOT a.attisdropped
          AND c.relkind IN ('r', 'p')";

    /** @var ConnectionRegistryInterface */
    private $registry;

    /** @var array<string, array<string, array{schema: string, table: string, reltuples: float, relpages: int, n_live_tup: int|null, last_analyze: string|null, last_autoanalyze: string|null, can_select: bool}>> connKey => "schema.table" => stats */
    private $tableStats = [];

    /** @var array<string, array<string, array<string, array{null_frac: float, n_distinct: float, avg_width: int, most_common_vals: array<int, string|null>|null, most_common_freqs: array<int, float>|null, histogram_bounds: array<int, string|null>|null}>>> connKey/schema => table => column => stats */
    private $columnStats = [];

    /** @var array<string, array<string, array<string, bool>>> connKey/schema => table => column => can_select */
    private $columnPrivileges = [];

    public function __construct(ConnectionRegistryInterface $registry)
    {
        $this->registry = $registry;
    }

    /** Есть ли у подключения статистика такого рода (только PostgreSQL). */
    public function supports(?string $connectionName = null): bool
    {
        $connection = $this->registry->getConnection($connectionName);

        return PlatformFactory::canonicalize($connection->getPlatformName()) === PlatformFactory::POSTGRESQL;
    }

    /**
     * Статистика по всем таблицам подключения одним запросом; кэшируется на прогон.
     *
     * @return array<string, array{schema: string, table: string, reltuples: float, relpages: int, n_live_tup: int|null, last_analyze: string|null, last_autoanalyze: string|null, can_select: bool}>
     */
    public function readTableStats(?string $connectionName = null): array
    {
        $key = $this->connKey($connectionName);
        if (isset($this->tableStats[$key])) {
            return $this->tableStats[$key];
        }

        $rows = $this->registry->getConnection($connectionName)->fetchAllAssociative(self::TABLE_STATS_SQL);

        /** @var array<string, array{schema: string, table: string, reltuples: float, relpages: int, n_live_tup: int|null, last_analyze: string|null, last_autoanalyze: string|null, can_select: bool}> $result */
        $result = [];
        foreach ($rows as $row) {
            $row = array_change_key_case($row, CASE_LOWER);
            $schema = (string) ($row['table_schema'] ?? '');
            $table = (string) ($row['table_name'] ?? '');
            if ($schema === '' || $table === '') {
                continue;
            }
            $result[$schema . '.' . $table] = [
                'schema' => $schema,
                'table' => $table,
                'reltuples' => isset($row['reltuples']) && is_numeric($row['reltuples']) ? (float) $row['reltuples'] : -1.0,
                'relpages' => isset($row['relpages']) && is_numeric($row['relpages']) ? (int) $row['relpages'] : 0,
                'n_live_tup' => isset($row['n_live_tup']) && is_numeric($row['n_live_tup']) ? (int) $row['n_live_tup'] : null,
                'last_analyze' => isset($row['last_analyze']) && is_scalar($row['last_analyze']) ? (string) $row['last_analyze'] : null,
                'last_autoanalyze' => isset($row['last_autoanalyze']) && is_scalar($row['last_autoanalyze']) ? (string) $row['last_autoanalyze'] : null,
                'can_select' => self::toBool($row['can_select'] ?? null),
            ];
        }

        return $this->tableStats[$key] = $result;
    }

    /**
     * Статистика колонок схемы одним запросом; кэшируется на прогон.
     *
     * @return array<string, array<string, array{null_frac: float, n_distinct: float, avg_width: int, most_common_vals: array<int, string|null>|null, most_common_freqs: array<int, float>|null, histogram_bounds: array<int, string|null>|null}>> table => column => stats
     */
    public function readColumnStats(string $schema, ?string $connectionName = null): array
    {
        $key = $this->connKey($connectionName) . '/' . $schema;
        if (isset($this->columnStats[$key])) {
            return $this->columnStats[$key];
        }

        $rows = $this->registry->getConnection($connectionName)
            ->fetchAllAssociative(self::COLUMN_STATS_SQL, ['schema' => $schema]);

        /** @var array<string, array<string, array{null_frac: float, n_distinct: float, avg_width: int, most_common_vals: array<int, string|null>|null, most_common_freqs: array<int, float>|null, histogram_bounds: array<int, string|null>|null}>> $result */
        $result = [];
        foreach ($rows as $row) {
            $row = array_change_key_case($row, CASE_LOWER);
            $table = (string) ($row['tablename'] ?? '');
            $column = (string) ($row['attname'] ?? '');
            if ($table === '' || $column === '') {
                continue;
            }
            $result[$table][$column] = [
                'null_frac' => isset($row['null_frac']) && is_numeric($row['null_frac']) ? (float) $row['null_frac'] : 0.0,
                'n_distinct' => isset($row['n_distinct']) && is_numeric($row['n_distinct']) ? (float) $row['n_distinct'] : 0.0,
                'avg_width' => isset($row['avg_width']) && is_numeric($row['avg_width']) ? (int) $row['avg_width'] : 0,
                'most_common_vals' => PgArrayLiteralParser::parse(self::nullableString($row['mcv'] ?? null)),
                'most_common_freqs' => PgArrayLiteralParser::parseFloats(self::nullableString($row['mcf'] ?? null)),
                'histogram_bounds' => PgArrayLiteralParser::parse(self::nullableString($row['hb'] ?? null)),
            ];
        }

        return $this->columnStats[$key] = $result;
    }

    /**
     * Право SELECT по колонкам схемы — из pg_attribute, который виден всем. information_schema
     * колонку без привилегий просто не показывает, и тогда «нет статистики» и «нет прав»
     * выглядят одинаково.
     *
     * @return array<string, array<string, bool>> table => column => can_select
     */
    public function readColumnPrivileges(string $schema, ?string $connectionName = null): array
    {
        $key = $this->connKey($connectionName) . '/' . $schema;
        if (isset($this->columnPrivileges[$key])) {
            return $this->columnPrivileges[$key];
        }

        $rows = $this->registry->getConnection($connectionName)
            ->fetchAllAssociative(self::COLUMN_PRIVILEGES_SQL, ['schema' => $schema]);

        $result = [];
        foreach ($rows as $row) {
            $row = array_change_key_case($row, CASE_LOWER);
            $table = (string) ($row['table_name'] ?? '');
            $column = (string) ($row['column_name'] ?? '');
            if ($table === '' || $column === '') {
                continue;
            }
            $result[$table][$column] = self::toBool($row['can_select'] ?? null);
        }

        return $this->columnPrivileges[$key] = $result;
    }

    /**
     * Оценка числа строк по статистике таблицы.
     *
     * reltuples > 0 — оценка планировщика; иначе n_live_tup из сборщика статистики (считает
     * вставки даже без ANALYZE); reltuples < 0 при пустом n_live_tup — «не анализировалась»,
     * размер неизвестен; reltuples = 0 — таблица пуста.
     *
     * @param array{reltuples: float, n_live_tup: int|null}|null $stats
     */
    public static function estimateRows(?array $stats): RowEstimate
    {
        if ($stats === null) {
            return RowEstimate::unknown();
        }
        $reltuples = $stats['reltuples'];
        $liveTuples = $stats['n_live_tup'];

        if ($reltuples > 0) {
            return new RowEstimate((int) round($reltuples), true, RowEstimate::SOURCE_PG_CLASS);
        }
        if ($liveTuples !== null && $liveTuples > 0) {
            return new RowEstimate($liveTuples, true, RowEstimate::SOURCE_PG_STAT);
        }
        if ($reltuples < 0) {
            return RowEstimate::unknown();
        }

        return new RowEstimate(0, true, RowEstimate::SOURCE_PG_CLASS);
    }

    public function reset(): void
    {
        $this->tableStats = [];
        $this->columnStats = [];
        $this->columnPrivileges = [];
    }

    private function connKey(?string $connectionName): string
    {
        return $connectionName ?? 'default';
    }

    /**
     * @param mixed $value
     */
    private static function toBool($value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if ($value === null) {
            return false;
        }
        $s = strtolower(trim((string) $value));

        return $s === 't' || $s === 'true' || $s === '1' || $s === 'yes';
    }

    /**
     * @param mixed $value
     */
    private static function nullableString($value): ?string
    {
        if ($value === null || !is_scalar($value)) {
            return null;
        }

        return (string) $value;
    }
}
