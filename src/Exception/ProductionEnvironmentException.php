<?php

namespace Timbrs\DatabaseDumps\Exception;

use RuntimeException;

/**
 * Исключение при попытке опасной операции в production окружении
 */
class ProductionEnvironmentException extends RuntimeException
{
    /** @var string|null */
    private $currentEnv;

    public function __construct(string $message, string $currentEnv = null)
    {
        parent::__construct($message);
        $this->currentEnv = $currentEnv;
    }

    public function getCurrentEnv(): ?string
    {
        return $this->currentEnv;
    }

    public static function importBlocked(string $currentEnv): self
    {
        return new self(
            "ОШИБКА: Импорт дампов запрещён в production-окружении! Текущее окружение: '{$currentEnv}'. "
            . "Если намеренно — измените APP_ENV.",
            $currentEnv
        );
    }

    public static function exportBlocked(string $currentEnv): self
    {
        return new self(
            "ОШИБКА: Экспорт с production запрещён по умолчанию (риск утечки PII)! "
            . "Текущее окружение: '{$currentEnv}'. "
            . "Используйте опцию --allow-prod-export после ручной верификации faker-конфига.",
            $currentEnv
        );
    }
}
