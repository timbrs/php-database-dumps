<?php

namespace Timbrs\DatabaseDumps\Service\Verification\Sink;

use Timbrs\DatabaseDumps\Service\Verification\ColumnSinkInterface;

/**
 * Множество различных значений со счётчиками и потолком.
 *
 * Потолок — предохранитель памяти: колонка-ключ на миллионы строк в множество не
 * помещается, и после потолка новые значения не запоминаются, а сток помечается capped.
 * Для проверок этого достаточно: покрытию нужно «≤ 50 или больше», замкнутости каскада —
 * множество родителя, которое по построению не больше числа его строк.
 *
 * Ключи массива в PHP превращают '1' в int — значения наружу отдаются строками.
 */
class CountingSetSink implements ColumnSinkInterface
{
    /** @var int 0 — без потолка */
    private $cap;

    /** @var int 0 — без обрезки */
    private $maxLength;

    /** @var array<int|string, int> */
    private $counts = [];

    /** @var bool */
    private $capped = false;

    /** @var int */
    private $nulls = 0;

    /** @var int */
    private $total = 0;

    public function __construct(int $cap = 0, int $maxLength = 0)
    {
        $this->cap = $cap;
        $this->maxLength = $maxLength;
    }

    public function accept(?string $value): void
    {
        $this->total++;
        if ($value === null) {
            $this->nulls++;
            return;
        }
        if ($this->maxLength > 0 && strlen($value) > $this->maxLength) {
            $value = substr($value, 0, $this->maxLength);
        }
        if (isset($this->counts[$value])) {
            $this->counts[$value]++;
            return;
        }
        if ($this->cap > 0 && count($this->counts) >= $this->cap) {
            $this->capped = true;
            return;
        }
        $this->counts[$value] = 1;
    }

    public function distinct(): int
    {
        return count($this->counts);
    }

    public function isCapped(): bool
    {
        return $this->capped;
    }

    public function has(string $value): bool
    {
        return isset($this->counts[$value]);
    }

    public function count(string $value): int
    {
        return $this->counts[$value] ?? 0;
    }

    /**
     * @return array<int, string>
     */
    public function values(): array
    {
        return array_map('strval', array_keys($this->counts));
    }

    /**
     * @return array<string, int> значение => сколько раз встретилось
     */
    public function counts(): array
    {
        $out = [];
        foreach ($this->counts as $value => $count) {
            $out[(string) $value] = $count;
        }

        return $out;
    }

    public function nulls(): int
    {
        return $this->nulls;
    }

    public function total(): int
    {
        return $this->total;
    }

    public function nonNull(): int
    {
        return $this->total - $this->nulls;
    }
}
