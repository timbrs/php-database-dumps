<?php

namespace Timbrs\DatabaseDumps\Service\Security;

use Timbrs\DatabaseDumps\Config\EnvironmentConfig;
use Timbrs\DatabaseDumps\Exception\ProductionEnvironmentException;

/**
 * Защита от опасных операций в production.
 *
 * Принимает EnvironmentConfig напрямую — слой EnvironmentChecker удалён
 * как избыточный (был тонкой обёрткой).
 */
class ProductionGuard
{
    /** @var EnvironmentConfig */
    private $environmentConfig;

    public function __construct(EnvironmentConfig $environmentConfig)
    {
        $this->environmentConfig = $environmentConfig;
    }

    public function isProduction(): bool
    {
        return $this->environmentConfig->isProduction();
    }

    public function getCurrentEnvironment(): string
    {
        return $this->environmentConfig->getCurrentEnv();
    }

    /**
     * Проверка безопасности импорта (разрушает целевую БД).
     *
     * @throws ProductionEnvironmentException
     */
    public function ensureSafeForImport(): void
    {
        if ($this->environmentConfig->isProduction()) {
            throw ProductionEnvironmentException::importBlocked(
                $this->environmentConfig->getCurrentEnv()
            );
        }
    }

    /**
     * Проверка безопасности экспорта (читает реальные данные с потенциальным PII).
     *
     * Может быть обойдена через $allowProdExport (например, опция CLI --allow-prod-export),
     * чтобы намеренно снимать дамп с prod после ручной верификации faker-конфига.
     *
     * @throws ProductionEnvironmentException
     */
    public function ensureSafeForExport(bool $allowProdExport = false): void
    {
        if ($allowProdExport) {
            return;
        }
        if ($this->environmentConfig->isProduction()) {
            throw ProductionEnvironmentException::exportBlocked(
                $this->environmentConfig->getCurrentEnv()
            );
        }
    }
}
