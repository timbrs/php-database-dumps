<?php

namespace Timbrs\DatabaseDumps\Service\Db;

use Timbrs\DatabaseDumps\Service\ConfigGenerator\TableInspector;

/**
 * Решает, откуда брать число строк: точный COUNT(*) или оценка планировщика.
 *
 * Оценка — всегда (дёшево, из каталога). Точный подсчёт — только когда оценка говорит, что
 * таблица укладывается в max_scan_rows, либо когда вызывающий настоял (--exact-counts).
 * Таблица с неизвестным размером (без статистики) точно не считается: именно она может
 * оказаться самой большой.
 *
 * Результаты кэшируются на прогон — генератор конфига и сборщик инвентаря спрашивают
 * об одной таблице по нескольку раз.
 */
class RowCounter
{
    /** @var TableInspector */
    private $inspector;

    /** @var SafeQueryPolicy */
    private $policy;

    /** @var array<string, RowEstimate> */
    private $cache = [];

    public function __construct(TableInspector $inspector, SafeQueryPolicy $policy = null)
    {
        $this->inspector = $inspector;
        $this->policy = $policy !== null ? $policy : SafeQueryPolicy::defaults();
    }

    public function count(string $schema, string $table, ?string $connectionName = null, bool $exact = false): RowEstimate
    {
        $key = ($connectionName ?? 'default') . ':' . $schema . '.' . $table;
        if (isset($this->cache[$key]) && (!$exact || !$this->cache[$key]->isEstimated())) {
            return $this->cache[$key];
        }

        if ($exact) {
            return $this->cache[$key] = RowEstimate::exact($this->inspector->countRows($schema, $table, $connectionName));
        }

        $estimate = $this->inspector->estimateRows($schema, $table, $connectionName);
        if ($estimate->isKnown() && $this->policy->allowsScan($estimate->getValue())) {
            $estimate = RowEstimate::exact($this->inspector->countRows($schema, $table, $connectionName));
        }

        return $this->cache[$key] = $estimate;
    }

    public function reset(): void
    {
        $this->cache = [];
    }
}
