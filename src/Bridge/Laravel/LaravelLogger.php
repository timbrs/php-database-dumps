<?php

namespace Timbrs\DatabaseDumps\Bridge\Laravel;

use Illuminate\Support\Facades\Log;
use Timbrs\DatabaseDumps\Contract\LoggerInterface;

/**
 * Логгер для Laravel.
 *
 * Дуальный режим: пишет в Laravel Log::* (всегда), плюс зеркалит сообщения
 * в console через callback (если установлен в команде).
 *
 * Это решает HIGH-проблему из аудита: ранее без outputCallback логи молча терялись.
 */
class LaravelLogger implements LoggerInterface
{
    /** @var callable|null */
    private $outputCallback;

    /**
     * @param callable|null $outputCallback
     */
    public function __construct($outputCallback = null)
    {
        $this->outputCallback = $outputCallback;
    }

    /**
     * @param callable $callback
     */
    public function setOutputCallback($callback): void
    {
        $this->outputCallback = $callback;
    }

    public function info(string $message): void
    {
        $this->writeToLog('info', $message);
        $this->output($message);
    }

    public function error(string $message): void
    {
        $this->writeToLog('error', $message);
        $this->output('[ERROR] ' . $message);
    }

    public function warning(string $message): void
    {
        $this->writeToLog('warning', $message);
        $this->output('[WARNING] ' . $message);
    }

    public function debug(string $message): void
    {
        $this->writeToLog('debug', $message);
        // debug в консоль выводим только если callback установлен (под -v)
        $this->output('[DEBUG] ' . $message);
    }

    private function writeToLog(string $level, string $message): void
    {
        // Защита: при ранней загрузке (например, в pure unit тестах) Log facade
        // может быть недоступен.
        if (!class_exists(Log::class)) {
            return;
        }
        try {
            Log::{$level}($message);
        } catch (\Throwable $e) {
            // Не пробрасываем — логирование не должно ломать основной поток
        }
    }

    private function output(string $message): void
    {
        if ($this->outputCallback !== null) {
            call_user_func($this->outputCallback, $message);
        }
    }
}
