<?php

namespace Timbrs\DatabaseDumps\Service\Security;

use Timbrs\DatabaseDumps\Config\EnvironmentConfig;

/**
 * Проверка текущего окружения
 */
class EnvironmentChecker
{
    /** @var EnvironmentConfig */
    private $config;

    public function __construct(EnvironmentConfig $config)
    {
        $this->config = $config;
    }

    /**
     * Проверить, является ли окружение production
     */
    public function isProduction(): bool
    {
        return $this->config->isProduction();
    }

    /**
     * Проверить, безопасно ли выполнять импорт
     */
    public function isSafeForImport(): bool
    {
        return !$this->isProduction();
    }

    /**
     * Получить текущее окружение
     */
    public function getCurrentEnvironment(): string
    {
        return $this->config->getCurrentEnv();
    }
}
