<?php

namespace Timbrs\DatabaseDumps\Config;

/**
 * DTO для конфигурации окружения.
 *
 * Защита от обхода: имя текущего окружения и список production-окружений
 * нормализуются (trim + lowercase) перед сравнением — 'PROD', 'Production',
 * 'prod ' интерпретируются как production.
 *
 * Fail-closed: если APP_ENV не задан (или пустой), окружение считается
 * production (т.е. деструктивные операции БЛОКИРУЮТСЯ). Это безопаснее,
 * чем считать env=dev по умолчанию.
 */
class EnvironmentConfig
{
    /**
     * Стандартный набор production-окружений (Symfony 'prod', Laravel 'production', плюс отечественные).
     */
    public const DEFAULT_PRODUCTION_ENVS = [
        'prod',
        'production',
        'predprod',
        'preprod',
        'pre-prod',
        'live',
        'staging',
    ];

    /** @var string */
    private $currentEnv;

    /** @var array<int, string> Нормализованный список production-окружений */
    private $productionEnvs;

    /**
     * @param array<int, string>|null $productionEnvs Если null — используются DEFAULT_PRODUCTION_ENVS
     */
    public function __construct(string $currentEnv, ?array $productionEnvs = null)
    {
        $this->currentEnv = $this->normalize($currentEnv);

        $list = $productionEnvs !== null ? $productionEnvs : self::DEFAULT_PRODUCTION_ENVS;
        $this->productionEnvs = array_values(array_map([$this, 'normalize'], $list));
    }

    public function getCurrentEnv(): string
    {
        return $this->currentEnv;
    }

    public function isProduction(): bool
    {
        return in_array($this->currentEnv, $this->productionEnvs, true);
    }

    /**
     * Создать из переменной окружения.
     *
     * Источник: getenv() → $_SERVER → $_ENV (порядок приоритета: реальный env процесса).
     * Если значение пустое/отсутствует — считаем production (fail-closed).
     */
    public static function fromEnv(): self
    {
        $env = self::readFromOs();

        if ($env === '' || $env === null) {
            // Fail-closed: при отсутствии APP_ENV считаем prod, чтобы блокировать импорт.
            return new self('prod');
        }

        return new self($env);
    }

    /**
     * @return string|null
     */
    private static function readFromOs()
    {
        // Реальный env процесса — самый авторитетный.
        $env = getenv('APP_ENV');
        if ($env !== false && $env !== '') {
            return $env;
        }

        if (isset($_SERVER['APP_ENV']) && is_string($_SERVER['APP_ENV']) && $_SERVER['APP_ENV'] !== '') {
            return $_SERVER['APP_ENV'];
        }

        if (isset($_ENV['APP_ENV']) && is_string($_ENV['APP_ENV']) && $_ENV['APP_ENV'] !== '') {
            return $_ENV['APP_ENV'];
        }

        return null;
    }

    private function normalize(string $value): string
    {
        return strtolower(trim($value));
    }
}
