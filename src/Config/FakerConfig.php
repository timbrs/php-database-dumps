<?php

namespace Timbrs\DatabaseDumps\Config;

/**
 * DTO для конфигурации фейкера (замены ПД)
 */
class FakerConfig
{
    /**
     * Structure: { schema: { table: { column: pattern_type } } }
     * @var array<string, array<string, array<string, string>>>
     */
    private $config;

    /** @param array<string, array<string, array<string, string>>> $config */
    public function __construct(array $config = [])
    {
        $this->config = $config;
    }

    /**
     * Получить конфигурацию фейкера для таблицы
     *
     * @return array<string, string>|null column => pattern_type
     */
    public function getTableFaker(string $schema, string $table): ?array
    {
        return $this->config[$schema][$table] ?? null;
    }

    /** @return array<string, array<string, array<string, string>>> */
    public function toArray(): array
    {
        return $this->config;
    }

    public function isEmpty(): bool
    {
        return empty($this->config);
    }
}
