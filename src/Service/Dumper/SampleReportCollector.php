<?php

namespace Timbrs\DatabaseDumps\Service\Dumper;

/**
 * Что реально дала выборка по критериям: по таблице — корзины (квота, строк, миллисекунды,
 * ошибка), потолок и усечение, источник и полнота значений stratify_by. Заполняет
 * SampleQueryBuilder, сбрасывает в {data_dir}/analysis/sample-report.json DatabaseDumper.
 *
 * Значения корзин stratify_by в отчёт не попадают: корзина называется по колонке и номеру
 * («status#2»), и только коды, прошедшие CodeValueGate, показываются как есть. Пустая
 * корзина — сигнал агенту: критерий не ловит ничего, вид данных в дампе отсутствует.
 */
class SampleReportCollector
{
    /** @var array<string, array<string, mixed>> «schema.table» => отчёт таблицы */
    private $tables = [];

    /**
     * @param array<string, mixed> $bucket {name, limit, rows, ms, error?, value?}
     */
    public function bucket(string $schema, string $table, array $bucket): void
    {
        $key = $schema . '.' . $table;
        $this->ensure($key);
        $this->tables[$key]['buckets'][] = $bucket;
    }

    /**
     * @param array<string, mixed> $stratify {column, source, values, truncated, per_value}
     */
    public function stratify(string $schema, string $table, array $stratify): void
    {
        $key = $schema . '.' . $table;
        $this->ensure($key);
        $this->tables[$key]['stratify'][] = $stratify;
    }

    public function total(string $schema, string $table, ?int $cap, int $selected, int $beforeCap, bool $fallback): void
    {
        $key = $schema . '.' . $table;
        $this->ensure($key);
        $this->tables[$key]['cap'] = $cap;
        $this->tables[$key]['selected'] = $selected;
        $this->tables[$key]['before_cap'] = $beforeCap;
        $this->tables[$key]['truncated_by_cap'] = $cap !== null && $beforeCap > $cap;
        $this->tables[$key]['fallback'] = $fallback;
    }

    public function isEmpty(): bool
    {
        return $this->tables === [];
    }

    public function clear(): void
    {
        $this->tables = [];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $tables = [];
        $emptyBuckets = 0;
        $truncated = 0;
        foreach ($this->tables as $key => $report) {
            foreach ($report['buckets'] as $bucket) {
                if (isset($bucket['rows']) && $bucket['rows'] === 0 && !isset($bucket['error'])) {
                    $emptyBuckets++;
                }
            }
            if (!empty($report['truncated_by_cap'])) {
                $truncated++;
            }
            $tables[$key] = $report;
        }
        ksort($tables);

        return [
            'generated_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'summary' => [
                'tables' => count($tables),
                'empty_buckets' => $emptyBuckets,
                'truncated_by_cap' => $truncated,
            ],
            'tables' => $tables,
        ];
    }

    private function ensure(string $key): void
    {
        if (!isset($this->tables[$key])) {
            $this->tables[$key] = [
                'buckets' => [],
                'stratify' => [],
                'cap' => null,
                'selected' => 0,
                'before_cap' => 0,
                'truncated_by_cap' => false,
                'fallback' => false,
            ];
        }
    }
}
