<?php

namespace Timbrs\DatabaseDumps\Service\Importer;

use Timbrs\DatabaseDumps\Contract\ConnectionRegistryInterface;
use Timbrs\DatabaseDumps\Contract\LoggerInterface;

/**
 * Управление транзакциями БД.
 *
 * При rollback() оборачиваем оригинальную ошибку — если rollBack сам бросает
 * (потерянное соединение), не теряем исходное исключение.
 */
class TransactionManager
{
    /** @var ConnectionRegistryInterface */
    private $registry;

    /** @var LoggerInterface|null */
    private $logger;

    public function __construct(ConnectionRegistryInterface $registry, LoggerInterface $logger = null)
    {
        $this->registry = $registry;
        $this->logger = $logger;
    }

    public function begin(?string $connectionName = null): void
    {
        $connection = $this->registry->getConnection($connectionName);
        if (!$connection->isTransactionActive()) {
            $connection->beginTransaction();
        }
    }

    public function commit(?string $connectionName = null): void
    {
        $connection = $this->registry->getConnection($connectionName);
        if ($connection->isTransactionActive()) {
            $connection->commit();
        }
    }

    public function rollBack(?string $connectionName = null): void
    {
        $connection = $this->registry->getConnection($connectionName);
        if ($connection->isTransactionActive()) {
            $connection->rollBack();
        }
    }

    /**
     * Выполнить код в транзакции с автоматическим rollback при ошибке.
     *
     * @template T
     * @param callable(): T $callback
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
            try {
                $this->rollBack($connectionName);
            } catch (\Throwable $rollbackError) {
                if ($this->logger !== null) {
                    $this->logger->error('Rollback failed: ' . $rollbackError->getMessage());
                }
                // Не подменяем оригинальное исключение
            }
            throw $e;
        }
    }
}
