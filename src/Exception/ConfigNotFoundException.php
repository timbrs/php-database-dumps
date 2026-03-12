<?php

namespace Timbrs\DatabaseDumps\Exception;

use RuntimeException;

/**
 * Исключение при отсутствии файла конфигурации
 */
class ConfigNotFoundException extends RuntimeException
{
    public static function fileNotFound(string $path): self
    {
        return new self("Файл конфигурации не найден: {$path}");
    }
}
