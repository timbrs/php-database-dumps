<?php

namespace Timbrs\DatabaseDumps\Exception;

use RuntimeException;

/**
 * Исключение при попытке опасной операции в production окружении
 */
class ProductionEnvironmentException extends RuntimeException
{
    public static function importBlocked(string $currentEnv): self
    {
        return new self(
            "ОШИБКА: Импорт дампов запрещен в production/predprod окружении! Текущее окружение: {$currentEnv}"
        );
    }
}
