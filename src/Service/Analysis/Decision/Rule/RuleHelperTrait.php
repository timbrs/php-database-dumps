<?php

namespace Timbrs\DatabaseDumps\Service\Analysis\Decision\Rule;

/**
 * Общее для правил: чтение полей досье без россыпи isset и пороги из настроек.
 */
trait RuleHelperTrait
{
    /**
     * @param array<string, mixed> $table
     * @param mixed                $default
     * @return mixed
     */
    protected function config(array $table, string $key, $default = null)
    {
        return isset($table['config'][$key]) ? $table['config'][$key] : $default;
    }

    /**
     * @param array<string, mixed> $table
     */
    protected function mode(array $table): string
    {
        return (string) $this->config($table, 'mode', 'not_exported');
    }

    /**
     * @param array<string, mixed> $table
     * @return int|null
     */
    protected function rows(array $table)
    {
        return isset($table['row_count']['value']) ? (int) $table['row_count']['value'] : null;
    }

    /**
     * @param array<string, mixed> $table
     */
    protected function rowsEstimated(array $table): bool
    {
        return !empty($table['row_count']['estimated']);
    }

    /**
     * @param array<string, mixed> $table
     * @return array<string, array<string, mixed>>
     */
    protected function columns(array $table): array
    {
        return isset($table['columns']) && is_array($table['columns']) ? $table['columns'] : [];
    }

    /**
     * @param array<string, mixed> $table
     * @return array<string, mixed>
     */
    protected function traits(array $table): array
    {
        return isset($table['traits']) && is_array($table['traits']) ? $table['traits'] : [];
    }

    /**
     * @param array<string, mixed> $context
     * @param mixed                $default
     * @return mixed
     */
    protected function setting(array $context, string $key, $default)
    {
        return isset($context['settings'][$key]) ? $context['settings'][$key] : $default;
    }

    /**
     * @param array<string, mixed> $table
     */
    protected function hasColumn(array $table, string $column): bool
    {
        return isset($table['columns'][$column]);
    }
}
