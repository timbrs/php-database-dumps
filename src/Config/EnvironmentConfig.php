<?php

namespace Timbrs\DatabaseDumps\Config;

/**
 * DTO для конфигурации окружения
 */
class EnvironmentConfig
{
    /**
     * @var string Текущее окружение (dev, test, prod, predprod)
     */
    private $currentEnv;

    /**
     * @var array<string> Список production окружений
     */
    private $productionEnvs;

    /**
     * @param string $currentEnv Текущее окружение (dev, test, prod, predprod)
     * @param array<string> $productionEnvs Список production окружений
     */
    public function __construct(
        string $currentEnv,
        array $productionEnvs = ['prod', 'production', 'predprod']
    ) {
        $this->currentEnv = $currentEnv;
        $this->productionEnvs = $productionEnvs;
    }

    public function getCurrentEnv(): string
    {
        return $this->currentEnv;
    }

    public function isProduction(): bool
    {
        return in_array($this->currentEnv, $this->productionEnvs, true);
    }

    public function isDevelopment(): bool
    {
        return $this->currentEnv === 'dev';
    }

    public function isTest(): bool
    {
        return $this->currentEnv === 'test';
    }

    /**
     * Создать из переменной окружения
     */
    public static function fromEnv(): self
    {
        $env = $_ENV['APP_ENV'] ?? getenv('APP_ENV') ?: 'dev';

        return new self($env);
    }
}
