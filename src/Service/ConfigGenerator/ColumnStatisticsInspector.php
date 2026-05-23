<?php

namespace Timbrs\DatabaseDumps\Service\ConfigGenerator;

use Timbrs\DatabaseDumps\Contract\ConnectionRegistryInterface;
use Timbrs\DatabaseDumps\Platform\PlatformFactory;

/**
 * Детерминированное профилирование колонок (без ИИ).
 *
 * По случайной выборке строк вычисляет для каждой колонки: долю NULL,
 * кардинальность (с потолком), top-значения и признак категориальности
 * (низкое число различных значений). Категориальные колонки питают
 * авто-генерацию sample.criteria (CriteriaSuggester).
 *
 * Реализация: одна выборка строк (SELECT * ... LIMIT N), статистика считается
 * в PHP — это и дешевле (1 запрос на таблицу), и легко тестируется на моке.
 */
class ColumnStatisticsInspector
{
    /** Потолок различных значений, при котором колонка считается категориальной. */
    public const MAX_CATEGORICAL_DISTINCT = 50;

    /** Сколько top-значений сохранять в профиле. */
    private const TOP_VALUES_LIMIT = 20;

    public const DEFAULT_SAMPLE_SIZE = 200;

    /** @var ConnectionRegistryInterface */
    private $registry;

    /** @var int */
    private $sampleSize;

    /**
     * @param int $sampleSize
     */
    public function __construct(ConnectionRegistryInterface $registry, $sampleSize = self::DEFAULT_SAMPLE_SIZE)
    {
        $this->registry = $registry;
        $this->sampleSize = (int) $sampleSize;
    }

    /**
     * Профилировать все колонки таблицы.
     *
     * @return array<int, ColumnProfile>
     */
    public function profileTable(string $schema, string $table, ?string $connectionName = null): array
    {
        $columnsMeta = $this->fetchColumns($schema, $table, $connectionName);
        if (empty($columnsMeta)) {
            return [];
        }

        $rows = $this->fetchSampleRows($schema, $table, $connectionName);
        $sampleCount = count($rows);

        $profiles = [];
        foreach ($columnsMeta as $name => $meta) {
            $profiles[] = $this->profileColumn($name, $meta, $rows, $sampleCount);
        }

        return $profiles;
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

        return new ColumnProfile(
            $name,
            $meta['type'],
            $meta['nullable'],
            $nullFraction,
            $distinct,
            $distinctCapped,
            $topValues,
            $categorical
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
     * @return array<int, array<string, mixed>>
     */
    private function fetchSampleRows(string $schema, string $table, ?string $connectionName): array
    {
        $connection = $this->registry->getConnection($connectionName);
        $platform = $this->registry->getPlatform($connectionName);

        $fullTable = $platform->getFullTableName($schema, $table);
        $randomFunc = $platform->getRandomFunctionSql();
        $limitSql = $platform->getLimitSql($this->sampleSize);

        $sql = "SELECT * FROM {$fullTable} ORDER BY {$randomFunc} {$limitSql}";

        return $connection->fetchAllAssociative($sql);
    }
}
