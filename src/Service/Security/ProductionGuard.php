<?php

namespace Timbrs\DatabaseDumps\Service\Security;

use Timbrs\DatabaseDumps\Exception\ProductionEnvironmentException;

/**
 * Защита от опасных операций в production
 */
class ProductionGuard
{
    /** @var EnvironmentChecker */
    private $environmentChecker;

    public function __construct(EnvironmentChecker $environmentChecker)
    {
        $this->environmentChecker = $environmentChecker;
    }

    /**
     * Проверить безопасность импорта и выбросить исключение если production
     *
     * @throws ProductionEnvironmentException
     */
    public function ensureSafeForImport(): void
    {
        if ($this->environmentChecker->isProduction()) {
            throw ProductionEnvironmentException::importBlocked(
                $this->environmentChecker->getCurrentEnvironment()
            );
        }
    }
}
