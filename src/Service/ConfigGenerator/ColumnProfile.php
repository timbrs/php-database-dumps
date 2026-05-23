<?php

namespace Timbrs\DatabaseDumps\Service\ConfigGenerator;

/**
 * Профиль одной колонки: тип, доля NULL, кардинальность (по семплу),
 * top-значения и признак категориальности.
 *
 * Питает: авто-генерацию sample.criteria (CriteriaSuggester), промпты LLM и отчёт.
 */
class ColumnProfile
{
    /** @var string */
    private $column;
    /** @var string */
    private $dataType;
    /** @var bool */
    private $nullable;
    /** @var float Доля NULL/пустых в семпле (0..1) */
    private $nullFraction;
    /** @var int Число различных значений в семпле (с потолком) */
    private $distinctCount;
    /** @var bool Достигнут ли потолок distinct (реальная кардинальность выше) */
    private $distinctCapped;
    /** @var array<int, array{value: string, count: int}> Топ-значения по частоте */
    private $topValues;
    /** @var bool Категориальная ли колонка (мало различных значений) */
    private $categorical;

    /**
     * @param array<int, array{value: string, count: int}> $topValues
     */
    public function __construct(
        string $column,
        string $dataType,
        bool $nullable,
        float $nullFraction,
        int $distinctCount,
        bool $distinctCapped,
        array $topValues,
        bool $categorical
    ) {
        $this->column = $column;
        $this->dataType = $dataType;
        $this->nullable = $nullable;
        $this->nullFraction = $nullFraction;
        $this->distinctCount = $distinctCount;
        $this->distinctCapped = $distinctCapped;
        $this->topValues = $topValues;
        $this->categorical = $categorical;
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
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'column' => $this->column,
            'data_type' => $this->dataType,
            'nullable' => $this->nullable,
            'null_fraction' => round($this->nullFraction, 3),
            'distinct_count' => $this->distinctCount,
            'distinct_capped' => $this->distinctCapped,
            'categorical' => $this->categorical,
            'top_values' => $this->topValues,
        ];
    }
}
