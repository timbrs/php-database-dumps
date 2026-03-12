<?php

namespace Timbrs\DatabaseDumps\Exception;

use RuntimeException;
use Throwable;

/**
 * Исключение при ошибке импорта
 */
class ImportFailedException extends RuntimeException
{
    public static function fromException(Throwable $previous): self
    {
        return new self(
            "Ошибка импорта: {$previous->getMessage()}",
            (int) $previous->getCode(),
            $previous
        );
    }

    public static function dumpsNotFound(string $path): self
    {
        return new self("Директория дампов не найдена: {$path}");
    }

    public static function noDumpsFound(string $path): self
    {
        return new self("SQL дампы не найдены в: {$path}");
    }
}
