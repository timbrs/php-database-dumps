<?php

namespace Timbrs\DatabaseDumps\Service\Analysis\CodeHints;

/**
 * Общие мультибайт-безопасные строковые хелперы для сканера кода и детекторов.
 *
 * Вынесены в трейт, чтобы не дублировать mb_*-обёртки и логику сниппетов/имён в каждом классе.
 * Все методы чистые (не используют состояние объекта). PHP 7.2-совместимо.
 */
trait TextHelperTrait
{
    /** Регистр в нижний (UTF-8, с ASCII-fallback). */
    protected function lower(string $s): string
    {
        return function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s);
    }

    /** Длина строки в символах (UTF-8, с ASCII-fallback). */
    protected function length(string $s): int
    {
        return function_exists('mb_strlen') ? mb_strlen($s, 'UTF-8') : strlen($s);
    }

    /** Обрезать до $len символов (UTF-8, с ASCII-fallback). */
    protected function cut(string $s, int $len): string
    {
        return function_exists('mb_substr') ? mb_substr($s, 0, $len, 'UTF-8') : substr($s, 0, $len);
    }

    /** «Голое» имя: часть после последней точки (schema.table → table). */
    protected function bareName(string $key): string
    {
        $pos = strrpos($key, '.');
        return $pos === false ? $key : substr($key, $pos + 1);
    }

    /** Короткое имя класса: часть после последнего разделителя пространства имён. */
    protected function shortClass(string $fqcn): string
    {
        $fqcn = str_replace('\\', '/', $fqcn);
        $pos = strrpos($fqcn, '/');
        return $pos === false ? $fqcn : substr($fqcn, $pos + 1);
    }

    /** CamelCase / camelCase → snake_case (lower). */
    protected function camelToSnake(string $s): string
    {
        $snake = preg_replace('/([a-z0-9])([A-Z])/', '$1_$2', $s);
        return $this->lower(is_string($snake) ? $snake : $s);
    }

    /**
     * Сниппет вокруг строки $idx: [idx-$before .. idx+$after], каждая тримится
     * и (при $maxLineLen > 0) обрезается по длине. Строки склеиваются через \n.
     *
     * @param array<int, string> $lines
     */
    protected function snippet(array $lines, int $idx, int $before, int $after, int $maxLineLen = 0): string
    {
        $from = max(0, $idx - $before);
        $to = min(count($lines) - 1, $idx + $after);
        $chunk = [];
        for ($i = $from; $i <= $to; $i++) {
            $text = trim($lines[$i]);
            if ($maxLineLen > 0 && $this->length($text) > $maxLineLen) {
                $text = $this->cut($text, $maxLineLen);
            }
            $chunk[] = $text;
        }
        return implode("\n", $chunk);
    }
}
