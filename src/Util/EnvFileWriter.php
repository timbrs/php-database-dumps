<?php

namespace Timbrs\DatabaseDumps\Util;

use Timbrs\DatabaseDumps\Contract\FileSystemInterface;

/**
 * Запись переменных окружения в .env-файл проекта.
 *
 * Цель setVar: .env.local (если существует) → .env (если существует) →
 * создать .env.local. Существующая строка `KEY=` заменяется (без дублей),
 * иначе значение дописывается в конец. Запись атомарная (writeAtomic).
 *
 * Приоритет чтения .env.local → .env обеспечивает dotenv фреймворка; здесь
 * важно писать в тот файл, который реально перекрывает значения.
 *
 * Значение оборачивается в двойные кавычки при наличии спецсимволов;
 * внутри экранируются \\ и ", чтобы dotenv прочитал значение как есть.
 */
class EnvFileWriter
{
    /** @var FileSystemInterface */
    private $fileSystem;

    public function __construct(FileSystemInterface $fileSystem)
    {
        $this->fileSystem = $fileSystem;
    }

    /**
     * Установить (или обновить) KEY=value в .env.local/.env.
     *
     * @return string путь к файлу, в который записано значение
     */
    public function setVar(string $projectDir, string $key, string $value): string
    {
        $base = rtrim($projectDir, '/\\');
        $local = $base . '/.env.local';
        $main = $base . '/.env';

        if ($this->fileSystem->exists($local)) {
            $target = $local;
        } elseif ($this->fileSystem->exists($main)) {
            $target = $main;
        } else {
            $target = $local;
        }

        $existing = $this->fileSystem->exists($target) ? $this->fileSystem->read($target) : '';
        $line = $key . '=' . $this->formatValue($value);

        $this->fileSystem->writeAtomic($target, $this->replaceOrAppend($existing, $key, $line));

        return $target;
    }

    private function replaceOrAppend(string $content, string $key, string $line): string
    {
        $pattern = '/^' . preg_quote($key, '/') . '=.*$/m';
        if (preg_match($pattern, $content)) {
            // Callback-замена, чтобы не интерпретировать $ и \\ в значении как обратные ссылки.
            $replaced = preg_replace_callback($pattern, function () use ($line) {
                return $line;
            }, $content, 1);
            return $replaced === null ? $content : $replaced;
        }

        if ($content === '') {
            return $line . "\n";
        }

        $sep = (substr($content, -1) === "\n") ? '' : "\n";
        return $content . $sep . $line . "\n";
    }

    private function formatValue(string $value): string
    {
        // Простое значение (URL, токен без спецсимволов) — без кавычек.
        if ($value !== '' && preg_match('#^[A-Za-z0-9_/.:@\\-]+$#', $value)) {
            return $value;
        }
        $escaped = str_replace(['\\', '"'], ['\\\\', '\\"'], $value);
        return '"' . $escaped . '"';
    }
}
