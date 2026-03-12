<?php

namespace Timbrs\DatabaseDumps\Contract;

/**
 * Интерфейс для логирования
 *
 * Упрощенная версия PSR-3 LoggerInterface
 */
interface LoggerInterface
{
    /**
     * Вывести информационное сообщение
     */
    public function info(string $message): void;

    /**
     * Вывести сообщение об ошибке
     */
    public function error(string $message): void;

    /**
     * Вывести предупреждение
     */
    public function warning(string $message): void;

    /**
     * Вывести отладочное сообщение
     */
    public function debug(string $message): void;
}
