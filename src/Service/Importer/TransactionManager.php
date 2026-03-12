<?php

namespace Timbrs\DatabaseDumps\Service\Importer;

use Timbrs\DatabaseDumps\Contract\ConnectionRegistryInterface;
use Timbrs\DatabaseDumps\Contract\DatabaseConnectionInterface;

/**
 * Управление транзакциями БД
 */
class TransactionManager
{
    /** @var ConnectionRegistryInterface */
    private $registry;

    public function __construct(ConnectionRegistryInterface $registry)
    {
        $this->registry = $registry;
    }

    /**
     * Начать транзакцию на указанном подключении
     */
    public function begin(?string $connectionName = null): void
    {
        $connection = $this->registry->getConnection($connectionName);
        if (!$connection->isTransactionActive()) {
            $connection->beginTransaction();
        }
    }

    /**
     * Закоммитить транзакцию на указанном подключении
     */
    public function commit(?string $connectionName = null): void
    {
        $connection = $this->registry->getConnection($connectionName);
        if ($connection->isTransactionActive()) {
            $connection->commit();
        }
    }

    /**
     * Откатить транзакцию на указанном подключении
     */
    public function rollBack(?string $connectionName = null): void
    {
        $connection = $this->registry->getConnection($connectionName);
        if ($connection->isTransactionActive()) {
            $connection->rollBack();
        }
    }

    /**
     * Выполнить код в транзакции с автоматическим rollback при ошибке
     *
     * @template T
     * @param callable(): T $callback
     * @param string|null $connectionName
     * @return mixed
     * @throws \Throwable
     */
    public function transaction(callable $callback, ?string $connectionName = null)
    {
        $this->begin($connectionName);

        try {
            $result = $callback();
            $this->commit($connectionName);
            return $result;
        } catch (\Throwable $e) {
            $this->rollBack($connectionName);
            throw $e;
        }
    }
}
