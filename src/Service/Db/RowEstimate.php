<?php

namespace Timbrs\DatabaseDumps\Service\Db;

/**
 * Число строк таблицы вместе с тем, откуда оно взялось.
 *
 * Точный COUNT(*) на боевой базе позволителен только для небольших таблиц; для остальных
 * берётся оценка планировщика. Потребителю важно различать оба случая: порог «full или
 * partial» по оценке — нормально, а «таблица пуста, пропустить» по оценке — уже нет.
 */
class RowEstimate
{
    public const SOURCE_COUNT = 'count';
    public const SOURCE_PG_CLASS = 'pg_class.reltuples';
    public const SOURCE_PG_STAT = 'pg_stat_user_tables.n_live_tup';
    public const SOURCE_INFORMATION_SCHEMA = 'information_schema.tables.table_rows';
    public const SOURCE_ALL_TABLES = 'all_tables.num_rows';
    public const SOURCE_NONE = 'none';

    /** @var int|null */
    private $value;

    /** @var bool */
    private $estimated;

    /** @var string */
    private $source;

    public function __construct(?int $value, bool $estimated, string $source)
    {
        $this->value = $value;
        $this->estimated = $estimated;
        $this->source = $source;
    }

    public static function exact(int $value): self
    {
        return new self($value, false, self::SOURCE_COUNT);
    }

    /** Размер неизвестен: статистики нет, а считать точно нельзя. */
    public static function unknown(): self
    {
        return new self(null, true, self::SOURCE_NONE);
    }

    public function getValue(): ?int
    {
        return $this->value;
    }

    public function isKnown(): bool
    {
        return $this->value !== null;
    }

    public function isEstimated(): bool
    {
        return $this->estimated;
    }

    public function getSource(): string
    {
        return $this->source;
    }

    /**
     * @return array{value: int|null, estimated: bool, source: string}
     */
    public function toArray(): array
    {
        return ['value' => $this->value, 'estimated' => $this->estimated, 'source' => $this->source];
    }
}
