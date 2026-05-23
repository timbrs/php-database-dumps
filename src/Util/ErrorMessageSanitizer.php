<?php

namespace Timbrs\DatabaseDumps\Util;

/**
 * Санитизация сообщений об ошибках для логов.
 *
 * Удаляет потенциально чувствительные данные (значения после VALUES),
 * ограничивает длину. Защита от утечки PII/данных строк в логи и трейсы.
 */
class ErrorMessageSanitizer
{
    private const MAX_LENGTH = 500;

    /**
     * Очистить сообщение исключения от потенциальных PII.
     */
    public static function sanitize(string $message): string
    {
        // Обрезаем после первого VALUES — там обычно данные строк
        $pos = stripos($message, 'VALUES');
        if ($pos !== false) {
            $message = substr($message, 0, $pos) . 'VALUES (...)';
        }

        if (strlen($message) > self::MAX_LENGTH) {
            $message = substr($message, 0, self::MAX_LENGTH) . '...';
        }

        return $message;
    }
}
