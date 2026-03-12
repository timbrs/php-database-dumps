<?php

namespace Timbrs\DatabaseDumps\Bridge\Laravel;

use Timbrs\DatabaseDumps\Contract\LoggerInterface;

/**
 * Логгер для Laravel с callback для консольного вывода
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
        $this->output($message);
    }

    public function error(string $message): void
    {
        $this->output('[ERROR] ' . $message);
    }

    public function warning(string $message): void
    {
        $this->output('[WARNING] ' . $message);
    }

    public function debug(string $message): void
    {
        $this->output('[DEBUG] ' . $message);
    }

    private function output(string $message): void
    {
        if ($this->outputCallback !== null) {
            call_user_func($this->outputCallback, $message);
        }
    }
}
