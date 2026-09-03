<?php

namespace Timbrs\DatabaseDumps\Service\Verification\Sink;

use Timbrs\DatabaseDumps\Service\Verification\ColumnSinkInterface;

/**
 * Первые N непустых значений колонки — для проверок «на что похожи данные»
 * (регулярные выражения детектора ПД). Остальное считается, но не хранится.
 */
class SampleSink implements ColumnSinkInterface
{
    /** @var int */
    private $limit;

    /** @var array<int, string> */
    private $values = [];

    /** @var int */
    private $nonNull = 0;

    /** @var int */
    private $total = 0;

    public function __construct(int $limit)
    {
        $this->limit = $limit;
    }

    public function accept(?string $value): void
    {
        $this->total++;
        if ($value === null || $value === '') {
            return;
        }
        $this->nonNull++;
        if (count($this->values) < $this->limit) {
            $this->values[] = $value;
        }
    }

    /**
     * @return array<int, string>
     */
    public function values(): array
    {
        return $this->values;
    }

    public function nonNull(): int
    {
        return $this->nonNull;
    }

    public function total(): int
    {
        return $this->total;
    }

    public function isSampled(): bool
    {
        return $this->nonNull > count($this->values);
    }
}
