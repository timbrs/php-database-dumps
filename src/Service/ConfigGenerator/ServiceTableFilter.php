<?php

namespace Timbrs\DatabaseDumps\Service\ConfigGenerator;

/**
 * Фильтр служебных таблиц фреймворков (Laravel, Symfony) и системных таблиц
 */
class ServiceTableFilter
{
    /**
     * Точные имена служебных таблиц
     */
    private const IGNORED_TABLES = [
        'migrations',
        'password_resets',
        'password_reset_tokens',
        'failed_jobs',
        'personal_access_tokens',
        'cache',
        'cache_locks',
        'sessions',
        'jobs',
        'job_batches',
        'telescope_entries',
        'telescope_entries_tags',
        'telescope_monitoring',
        'doctrine_migration_versions',
        'messenger_messages',
        'rememberme_token',
        'migration_versions',
    ];

    /**
     * Префиксы служебных таблиц
     */
    private const IGNORED_PREFIXES = [
        'horizon_',
        'pulse_',
        'sanctum_',
    ];

    /**
     * Ключевые слова для фильтрации (проверяются как сегменты имени)
     */
    private const IGNORED_KEYWORDS = [
        'backup',
        'backups',
        'test',
        'tests',
        'log',
        'logs',
    ];

    /**
     * Определяет, является ли таблица служебной и должна быть проигнорирована
     */
    public function shouldIgnore(string $tableName): bool
    {
        $lower = strtolower($tableName);

        if (in_array($lower, self::IGNORED_TABLES, true)) {
            return true;
        }

        foreach (self::IGNORED_PREFIXES as $prefix) {
            if (strpos($lower, $prefix) === 0) {
                return true;
            }
        }

        $segments = explode('_', $lower);
        foreach ($segments as $segment) {
            if (in_array($segment, self::IGNORED_KEYWORDS, true)) {
                return true;
            }
        }

        return false;
    }
}
