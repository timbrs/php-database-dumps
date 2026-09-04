<?php

namespace Timbrs\DatabaseDumps\Util;

/**
 * Кодирование отчётов инструмента в JSON — одним набором флагов на всех.
 *
 * Набор везде один и тот же, но один флаг из него, `JSON_INVALID_UTF8_SUBSTITUTE`, появился только
 * в PHP 7.2, а пакет обязан работать с 7.1. На старом PHP такое выражение не «пропускает»
 * неизвестную константу: сначала notice, потом фатал на побитовом ИЛИ со строкой. Поэтому флаг
 * подставляется через `defined()`.
 *
 * Что теряется на 7.1: битое значение (двоичный мусор в профиле колонки) не заменится на U+FFFD и
 * `json_encode` вернёт false — но `JSON_PARTIAL_OUTPUT_ON_ERROR` есть с 5.5, поэтому отчёт всё
 * равно запишется, просто без испорченного куска.
 */
class JsonReport
{
    /**
     * Флаги отчёта: читаемо, по-русски, без экранирования слэшей и без падения на мусоре.
     */
    public static function flags(): int
    {
        $flags = JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR;
        if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
            $flags |= (int) constant('JSON_INVALID_UTF8_SUBSTITUTE');
        }

        return $flags;
    }

    /**
     * Отчёт в строку. Пустой объект вместо `false` — файл отчёта должен остаться читаемым JSON'ом
     * даже когда закодировать не удалось.
     *
     * @param mixed $data
     */
    public static function encode($data): string
    {
        $json = json_encode($data, self::flags());

        return $json === false ? '{}' : $json;
    }
}
