<?php

namespace Timbrs\DatabaseDumps\Config;

/**
 * DTO для конфигурации экспорта таблицы
 */
class TableConfig
{
    public const KEY_LIMIT = 'limit';
    public const KEY_WHERE = 'where';

    public const KEY_ORDER_BY = 'order_by';
    public const KEY_CASCADE_FROM = 'cascade_from';

    /** @var string */
    private $schema;
    /** @var string */
    private $table;
    /** @var int|null */
    private $limit;
    /** @var string|null */
    private $where;
    /** @var string|null */
    private $orderBy;
    /** @var string|null */
    private $connectionName;
    /** @var array<int, array{parent: string, fk_column: string, parent_column: string}>|null */
    private $cascadeFrom;

    /**
     * @param array<int, array{parent: string, fk_column: string, parent_column: string}>|null $cascadeFrom
     */
    public function __construct(
        string $schema,
        string $table,
        ?int $limit = null,
        ?string $where = null,
        ?string $orderBy = null,
        ?string $connectionName = null,
        ?array $cascadeFrom = null
    ) {
        $this->schema = $schema;
        $this->table = $table;
        $this->limit = $limit;
        $this->where = $where;
        $this->orderBy = $orderBy;
        $this->connectionName = $connectionName;
        $this->cascadeFrom = $cascadeFrom;
    }

    public function getSchema(): string
    {
        return $this->schema;
    }

    public function getTable(): string
    {
        return $this->table;
    }

    public function getFullTableName(): string
    {
        return "{$this->schema}.{$this->table}";
    }

    public function getLimit(): ?int
    {
        return $this->limit;
    }

    public function getWhere(): ?string
    {
        return $this->where;
    }

    public function getOrderBy(): ?string
    {
        return $this->orderBy;
    }

    public function isFullExport(): bool
    {
        return $this->limit === null;
    }

    public function isPartialExport(): bool
    {
        return $this->limit !== null;
    }

    public function getConnectionName(): ?string
    {
        return $this->connectionName;
    }

    /**
     * Получить конфигурацию каскадных зависимостей
     *
     * @return array<int, array{parent: string, fk_column: string, parent_column: string}>|null
     */
    public function getCascadeFrom(): ?array
    {
        return $this->cascadeFrom;
    }

    /**
     * Создать из массива конфигурации
     *
     * @param string $schema
     * @param string $table
     * @param array<string, mixed> $config
     * @param string|null $connectionName
     * @return self
     */
    public static function fromArray(string $schema, string $table, array $config = [], ?string $connectionName = null): self
    {
        $cascadeFrom = isset($config[self::KEY_CASCADE_FROM]) ? $config[self::KEY_CASCADE_FROM] : null;

        return new self(
            $schema,
            $table,
            $config[self::KEY_LIMIT] ?? null,
            $config[self::KEY_WHERE] ?? null,
            $config[self::KEY_ORDER_BY] ?? null,
            $connectionName,
            $cascadeFrom
        );
    }
}
