<?php

namespace Timbrs\DatabaseDumps\Exception;

use RuntimeException;
use Throwable;

/**
 * Исключение при ошибке экспорта
 */
class ExportFailedException extends RuntimeException
{
    public static function fromException(string $table, Throwable $previous): self
    {
        return new self(
            "Ошибка экспорта таблицы {$table}: {$previous->getMessage()}",
            (int) $previous->getCode(),
            $previous
        );
    }

    public static function invalidTableName(string $tableName): self
    {
        return new self(
            "Неверный формат таблицы: {$tableName}. Используйте формат: schema.table"
        );
    }
}
