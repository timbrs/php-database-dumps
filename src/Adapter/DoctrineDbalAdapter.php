<?php

namespace Timbrs\DatabaseDumps\Adapter;

use Doctrine\DBAL\Connection;
use Timbrs\DatabaseDumps\Contract\DatabaseConnectionInterface;
use Timbrs\DatabaseDumps\Platform\PlatformFactory;

/**
 * Адаптер для Doctrine DBAL Connection
 */
class DoctrineDbalAdapter implements DatabaseConnectionInterface
{
    /** @var Connection */
    private $connection;

    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
    }

    public function executeStatement(string $sql): void
    {
        $this->connection->executeStatement($sql);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function fetchAllAssociative(string $sql): array
    {
        if ($this->isPostgres()) {
            $pdo = $this->getNativePdo();
            if ($pdo !== null) {
                return $this->fetchViaPdoWithBooleans($pdo, $sql);
            }
        }

        return $this->connection->fetchAllAssociative($sql);
    }

    /**
     * @param array<mixed> $params
     * @return array<int, mixed>
     */
    public function fetchFirstColumn(string $sql, array $params = []): array
    {
        return $this->connection->fetchFirstColumn($sql, $params);
    }

    public function quote($value): string
    {
        return $this->connection->quote($value);
    }

    public function beginTransaction(): void
    {
        $this->connection->beginTransaction();
    }

    public function commit(): void
    {
        $this->connection->commit();
    }

    public function rollBack(): void
    {
        $this->connection->rollBack();
    }

    public function isTransactionActive(): bool
    {
        return $this->connection->isTransactionActive();
    }

    /**
     * @return bool
     */
    private function isPostgres()
    {
        $platform = $this->connection->getDatabasePlatform();
        $className = get_class($platform);

        return strpos($className, 'PostgreSQL') !== false || strpos($className, 'Postgre') !== false;
    }

    /**
     * Выполнить запрос через нативный PDO с нормализацией boolean-колонок
     *
     * @param \PDO $pdo
     * @param string $sql
     * @return array<int, array<string, mixed>>
     */
    private function fetchViaPdoWithBooleans(\PDO $pdo, $sql)
    {
        $stmt = $pdo->query($sql);
        if ($stmt === false) {
            return array();
        }

        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        if (empty($rows)) {
            return $rows;
        }

        return BooleanNormalizer::normalize($stmt, $rows);
    }

    /**
     * @return \PDO|null
     */
    private function getNativePdo()
    {
        if (method_exists($this->connection, 'getNativeConnection')) {
            $native = $this->connection->getNativeConnection();
            return $native instanceof \PDO ? $native : null;
        }

        if (method_exists($this->connection, 'getWrappedConnection')) {
            $wrapped = $this->connection->getWrappedConnection();
            return $wrapped instanceof \PDO ? $wrapped : null;
        }

        return null;
    }

    public function getPlatformName(): string
    {
        $platform = $this->connection->getDatabasePlatform();
        $className = get_class($platform);

        if (strpos($className, 'PostgreSQL') !== false || strpos($className, 'Postgre') !== false) {
            return PlatformFactory::POSTGRESQL;
        }

        if (strpos($className, 'MySQL') !== false || strpos($className, 'MariaDb') !== false) {
            return PlatformFactory::MYSQL;
        }

        if (strpos($className, 'Oracle') !== false || strpos($className, 'OCI') !== false) {
            return PlatformFactory::ORACLE;
        }

        return PlatformFactory::POSTGRESQL;
    }
}
