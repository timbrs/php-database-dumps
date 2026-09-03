<?php

namespace Timbrs\DatabaseDumps\Service\ConfigGenerator;

/**
 * Профиль одной колонки: тип, доля NULL, кардинальность, top-значения, признак
 * категориальности и — после шлюза CodeValueGate — коды.
 *
 * Откуда взята кардинальность, помечено в distinctSource: по выборке строк (sample) —
 * оценка снизу, по статистике планировщика (pg_stats) — оценка планировщика. Коды есть только
 * у колонок, прошедших шлюз; codesComplete говорит, покрывает ли список все различные значения
 * или только частые (most_common_vals хранит лишь их).
 *
 * Питает: авто-генерацию sample.criteria (CriteriaSuggester), инвентарь для агента и отчёт.
 */
class ColumnProfile
{
    public const SOURCE_SAMPLE = 'sample';
    public const SOURCE_PG_STATS = 'pg_stats';

    /** @var string */
    private $column;
    /** @var string */
    private $dataType;
    /** @var bool */
    private $nullable;
    /** @var float Доля NULL/пустых (0..1) */
    private $nullFraction;
    /** @var int Число различных значений (с потолком) */
    private $distinctCount;
    /** @var bool Достигнут ли потолок distinct (реальная кардинальность выше) */
    private $distinctCapped;
    /** @var array<int, array{value: string, count: int}> Топ-значения по частоте */
    private $topValues;
    /** @var bool Категориальная ли колонка (мало различных значений) */
    private $categorical;
    /** @var array<int, string>|null Коды, прошедшие шлюз; null — колонка кодов не содержит */
    private $codes;
    /** @var string Источник кардинальности: sample | pg_stats */
    private $distinctSource;
    /** @var bool|null Покрывают ли коды все различные значения */
    private $codesComplete;

    /**
     * @param array<int, array{value: string, count: int}> $topValues
     * @param array<int, string>|null $codes
     */
    public function __construct(
        string $column,
        string $dataType,
        bool $nullable,
        float $nullFraction,
        int $distinctCount,
        bool $distinctCapped,
        array $topValues,
        bool $categorical,
        ?array $codes = null,
        string $distinctSource = self::SOURCE_SAMPLE,
        ?bool $codesComplete = null
    ) {
        $this->column = $column;
        $this->dataType = $dataType;
        $this->nullable = $nullable;
        $this->nullFraction = $nullFraction;
        $this->distinctCount = $distinctCount;
        $this->distinctCapped = $distinctCapped;
        $this->topValues = $topValues;
        $this->categorical = $categorical;
        $this->codes = $codes;
        $this->distinctSource = $distinctSource;
        $this->codesComplete = $codes === null ? null : ($codesComplete !== null ? $codesComplete : false);
    }

    public function getColumn(): string
    {
        return $this->column;
    }

    public function getDataType(): string
    {
        return $this->dataType;
    }

    public function isNullable(): bool
    {
        return $this->nullable;
    }

    public function getNullFraction(): float
    {
        return $this->nullFraction;
    }

    public function getDistinctCount(): int
    {
        return $this->distinctCount;
    }

    public function isDistinctCapped(): bool
    {
        return $this->distinctCapped;
    }

    /**
     * @return array<int, array{value: string, count: int}>
     */
    public function getTopValues(): array
    {
        return $this->topValues;
    }

    public function isCategorical(): bool
    {
        return $this->categorical;
    }

    /**
     * @return array<int, string>|null
     */
    public function getCodes(): ?array
    {
        return $this->codes;
    }

    public function isCodesComplete(): ?bool
    {
        return $this->codesComplete;
    }

    public function getDistinctSource(): string
    {
        return $this->distinctSource;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $arr = [
            'column' => $this->column,
            'data_type' => $this->dataType,
            'nullable' => $this->nullable,
            'null_fraction' => round($this->nullFraction, 3),
            'distinct_count' => $this->distinctCount,
            'distinct_capped' => $this->distinctCapped,
            'n_distinct_source' => $this->distinctSource,
            'categorical' => $this->categorical,
            'top_values' => $this->topValues,
        ];
        if ($this->codes !== null) {
            $arr['codes'] = $this->codes;
            $arr['codes_complete'] = $this->codesComplete;
        }

        return $arr;
    }
}
