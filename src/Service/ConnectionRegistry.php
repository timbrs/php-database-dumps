<?php

namespace Timbrs\DatabaseDumps\Service;

use Timbrs\DatabaseDumps\Contract\ConnectionRegistryInterface;
use Timbrs\DatabaseDumps\Contract\DatabaseConnectionInterface;
use Timbrs\DatabaseDumps\Contract\DatabasePlatformInterface;
use Timbrs\DatabaseDumps\Contract\LoggerInterface;
use Timbrs\DatabaseDumps\Platform\PlatformFactory;
use Timbrs\DatabaseDumps\Service\Db\CountingConnection;
use Timbrs\DatabaseDumps\Service\Db\SafeQueryPolicy;

/**
 * Реестр подключений к БД.
 *
 * С политикой (SafeQueryPolicy) каждое подключение оборачивается в CountingConnection, а при
 * первом getConnection() — и при каждой смене профиля политики — в сессию уходят SET-инструкции
 * профиля (таймауты). Делать это в register() нельзя: команды регистрируются лениво, и `list`
 * не должен открывать соединение с БД. Без политики реестр ведёт себя как раньше: отдаёт
 * то, что зарегистрировали.
 */
class ConnectionRegistry implements ConnectionRegistryInterface
{
    /** @var string */
    private $defaultName;

    /** @var array<string, DatabaseConnectionInterface> */
    private $connections = [];

    /** @var array<string, DatabasePlatformInterface> */
    private $platforms = [];

    /** @var SafeQueryPolicy|null */
    private $policy;

    /** @var LoggerInterface|null */
    private $logger;

    /** @var array<string, string> имя подключения → профиль, чьи SET уже применены */
    private $appliedProfile = [];

    public function __construct(string $defaultName, SafeQueryPolicy $policy = null, LoggerInterface $logger = null)
    {
        $this->defaultName = $defaultName;
        $this->policy = $policy;
        $this->logger = $logger;
    }

    /**
     * Зарегистрировать подключение (platform автоопределяется)
     */
    public function register(string $name, DatabaseConnectionInterface $connection): void
    {
        $this->connections[$name] = $this->policy !== null
            ? new CountingConnection($connection, $this->policy, $name)
            : $connection;
        $this->platforms[$name] = PlatformFactory::create($connection->getPlatformName(), $this->logger);
        unset($this->appliedProfile[$name]);
    }

    public function getConnection(?string $name = null): DatabaseConnectionInterface
    {
        $name = $name ?? $this->defaultName;

        if (!isset($this->connections[$name])) {
            throw new \InvalidArgumentException("Подключение '{$name}' не зарегистрировано");
        }

        $this->applySessionProfile($name);

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

    public function getPolicy(): ?SafeQueryPolicy
    {
        return $this->policy;
    }

    /**
     * Сколько запросов ушло в каждое подключение за прогон (только с политикой).
     *
     * @return array<string, int>
     */
    public function getQueryCounts(): array
    {
        $counts = [];
        foreach ($this->connections as $name => $connection) {
            if ($connection instanceof CountingConnection) {
                $counts[$name] = $connection->getQueryCount();
            }
        }

        return $counts;
    }

    /**
     * SET-инструкции текущего профиля политики — один раз на подключение и профиль.
     * Идут в сырое соединение, минуя счётчик бюджета.
     */
    private function applySessionProfile(string $name): void
    {
        if ($this->policy === null) {
            return;
        }
        $profile = $this->policy->getProfile();
        if (isset($this->appliedProfile[$name]) && $this->appliedProfile[$name] === $profile) {
            return;
        }
        // Отмечаем до исполнения: упавший SET не должен повторяться на каждом getConnection().
        $this->appliedProfile[$name] = $profile;

        $connection = $this->connections[$name];
        $raw = $connection instanceof CountingConnection ? $connection->getInner() : $connection;
        $platformName = $raw->getPlatformName();

        $groups = $this->policy->sessionStatements($platformName, $profile);
        if ($groups === []) {
            if ($this->logger !== null) {
                $this->logger->info(sprintf(
                    'Подключение "%s": сессионные таймауты для платформы %s не поддерживаются, профиль %s без них',
                    $name,
                    $platformName,
                    $profile
                ));
            }
            return;
        }

        foreach ($groups as $alternatives) {
            $lastError = null;
            foreach ($alternatives as $sql) {
                try {
                    $raw->executeStatement($sql);
                    $lastError = null;
                    break;
                } catch (\Throwable $e) {
                    $lastError = $e;
                }
            }
            if ($lastError !== null && $this->logger !== null) {
                $this->logger->warning(sprintf(
                    'Подключение "%s": не удалось применить "%s" (%s)',
                    $name,
                    $alternatives[0],
                    $lastError->getMessage()
                ));
            }
        }
    }
}
