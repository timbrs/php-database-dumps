<?php

namespace Timbrs\DatabaseDumps\Config;

use Timbrs\DatabaseDumps\Service\Faker\PatternDetector;

/**
 * DTO для конфигурации фейкера (замены ПД).
 *
 * Валидация: все pattern_type сверяются с PatternDetector::ALLOWED_PATTERNS.
 * Неизвестный паттерн = InvalidArgumentException (защита от silent data leak,
 * когда опечатка типа 'fioo' молча отключает маскирование).
 */
class FakerConfig
{
    /**
     * Structure: { schema: { table: { column: pattern_type } } }
     * @var array<string, array<string, array<string, string>>>
     */
    private $config;

    /**
     * @param array<string, array<string, array<string, string>>> $config
     * @throws \InvalidArgumentException
     */
    public function __construct(array $config = [])
    {
        $this->validate($config);
        $this->config = $config;
    }

    /**
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

    /**
     * @param array<string, array<string, array<string, string>>> $config
     * @throws \InvalidArgumentException
     */
    private function validate(array $config): void
    {
        $allowed = PatternDetector::ALLOWED_PATTERNS;
        foreach ($config as $schema => $tables) {
            $this->validateIdentifier('schema', (string) $schema);
            if (!is_array($tables)) {
                throw new \InvalidArgumentException(
                    "FakerConfig: schema '{$schema}' must be array, got " . gettype($tables)
                );
            }
            foreach ($tables as $table => $columns) {
                $this->validateIdentifier("schema '{$schema}', table", (string) $table);
                if (!is_array($columns)) {
                    throw new \InvalidArgumentException(
                        "FakerConfig: {$schema}.{$table} must be array, got " . gettype($columns)
                    );
                }
                foreach ($columns as $column => $patternType) {
                    $this->validateIdentifier("{$schema}.{$table}, column", (string) $column);
                    if (!is_string($patternType)) {
                        throw new \InvalidArgumentException(
                            "FakerConfig: pattern for {$schema}.{$table}.{$column} must be string"
                        );
                    }
                    if (!in_array($patternType, $allowed, true)) {
                        throw new \InvalidArgumentException(
                            sprintf(
                                "FakerConfig: unknown pattern '%s' for %s.%s.%s. Allowed: %s",
                                $patternType,
                                $schema,
                                $table,
                                $column,
                                implode(', ', $allowed)
                            )
                        );
                    }
                }
            }
        }
    }

    private function validateIdentifier(string $context, string $value): void
    {
        if ($value === '' || !preg_match('/^[A-Za-z_][A-Za-z0-9_$]*$/', $value)) {
            throw new \InvalidArgumentException(
                sprintf("FakerConfig: invalid %s identifier '%s'", $context, $value)
            );
        }
    }
}
