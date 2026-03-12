<?php

namespace Timbrs\DatabaseDumps\Service;

use Timbrs\DatabaseDumps\Contract\ConnectionRegistryInterface;
use Timbrs\DatabaseDumps\Contract\DatabaseConnectionInterface;
use Timbrs\DatabaseDumps\Contract\DatabasePlatformInterface;
use Timbrs\DatabaseDumps\Platform\PlatformFactory;

/**
 * Реестр подключений к БД
 */
class ConnectionRegistry implements ConnectionRegistryInterface
{
    /** @var string */
    private $defaultName;

    /** @var array<string, DatabaseConnectionInterface> */
    private $connections = [];

    /** @var array<string, DatabasePlatformInterface> */
    private $platforms = [];

    public function __construct(string $defaultName)
    {
        $this->defaultName = $defaultName;
    }

    /**
     * Зарегистрировать подключение (platform автоопределяется)
     */
    public function register(string $name, DatabaseConnectionInterface $connection): void
    {
        $this->connections[$name] = $connection;
        $this->platforms[$name] = PlatformFactory::create($connection->getPlatformName());
    }

    public function getConnection(?string $name = null): DatabaseConnectionInterface
    {
        $name = $name ?? $this->defaultName;

        if (!isset($this->connections[$name])) {
            throw new \InvalidArgumentException("Подключение '{$name}' не зарегистрировано");
        }

        return $this->connections[$name];
    }

    public function getPlatform(?string $name = null): DatabasePlatformInterface
    {
        $name = $name ?? $this->defaultName;

        if (!isset($this->platforms[$name])) {
            throw new \InvalidArgumentException("Платформа для подключения '{$name}' не зарегистрирована");
        }

        return $this->platforms[$name];
    }

    public function getDefaultName(): string
    {
        return $this->defaultName;
    }

    /**
     * @return string[]
     */
    public function getNames(): array
    {
        return array_keys($this->connections);
    }

    public function has(string $name): bool
    {
        return isset($this->connections[$name]);
    }
}
