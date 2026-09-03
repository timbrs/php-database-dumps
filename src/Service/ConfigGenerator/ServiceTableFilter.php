<?php

namespace Timbrs\DatabaseDumps\Service\ConfigGenerator;

/**
 * Фильтр служебных таблиц фреймворков (Laravel, Symfony) и системных таблиц.
 *
 * Список настраивается через `settings.service_tables` (exact / prefix / segments): жёсткий
 * набор ошибается на доменных именах — так из инвентаря пропала бизнес-таблица `tasks.jobs`,
 * совпавшая с очередью Laravel. Поэтому `jobs` из умолчаний убрана: очередь Laravel всё равно
 * ловится по `failed_jobs`/`job_batches`, а доменную таблицу с таким именем не жалко проверить
 * глазами, чем молча потерять.
 */
class ServiceTableFilter
{
    /**
     * Точные имена служебных таблиц.
     *
     * @var array<int, string>
     */
    public const DEFAULT_EXACT = [
        'migrations',
        'password_resets',
        'password_reset_tokens',
        'failed_jobs',
        'personal_access_tokens',
        'cache',
        'cache_locks',
        'sessions',
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
     * Префиксы служебных таблиц.
     *
     * @var array<int, string>
     */
    public const DEFAULT_PREFIXES = [
        'horizon_',
        'pulse_',
        'sanctum_',
    ];

    /**
     * Ключевые слова (проверяются как сегменты имени).
     *
     * @var array<int, string>
     */
    public const DEFAULT_SEGMENTS = [
        'backup',
        'backups',
        'test',
        'tests',
        'log',
        'logs',
    ];

    /** @var array<int, string> */
    private $exact;

    /** @var array<int, string> */
    private $prefixes;

    /** @var array<int, string> */
    private $segments;

    /**
     * @param array<string, mixed> $settings секция settings.service_tables (exact/prefix/segments)
     */
    public function __construct(array $settings = [])
    {
        $this->exact = $this->normalize(isset($settings['exact']) ? $settings['exact'] : null, self::DEFAULT_EXACT);
        $this->prefixes = $this->normalize(isset($settings['prefix']) ? $settings['prefix'] : null, self::DEFAULT_PREFIXES);
        $this->segments = $this->normalize(isset($settings['segments']) ? $settings['segments'] : null, self::DEFAULT_SEGMENTS);
    }

    /**
     * Определяет, является ли таблица служебной и должна быть проигнорирована.
     */
    public function shouldIgnore(string $tableName): bool
    {
        $lower = strtolower($tableName);

        if (in_array($lower, $this->exact, true)) {
            return true;
        }

        foreach ($this->prefixes as $prefix) {
            if ($prefix !== '' && strpos($lower, $prefix) === 0) {
                return true;
            }
        }

        foreach (explode('_', $lower) as $segment) {
            if (in_array($segment, $this->segments, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param mixed              $value
     * @param array<int, string> $default
     * @return array<int, string>
     */
    private function normalize($value, array $default): array
    {
        if (!is_array($value)) {
            return $default;
        }
        $out = [];
        foreach ($value as $item) {
            if (is_string($item) && $item !== '') {
                $out[] = strtolower($item);
            }
        }

        return $out;
    }
}
