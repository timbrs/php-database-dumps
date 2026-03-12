<?php

namespace Timbrs\DatabaseDumps\Contract;

/**
 * Реестр подключений к БД
 *
 * Позволяет работать с несколькими подключениями (connection + platform)
 */
interface ConnectionRegistryInterface
{
    public const CONNECTION_ALL = 'all';

    /**
     * Получить подключение по имени (null = дефолтное)
     */
    public function getConnection(?string $name = null): DatabaseConnectionInterface;

    /**
     * Получить платформу по имени подключения (null = дефолтная)
     */
    public function getPlatform(?string $name = null): DatabasePlatformInterface;

    /**
     * Получить имя дефолтного подключения
     */
    public function getDefaultName(): string;

    /**
     * Получить список всех зарегистрированных имён подключений
     *
     * @return string[]
     */
    public function getNames(): array;

    /**
     * Проверить наличие подключения
     */
    public function has(string $name): bool;
}
