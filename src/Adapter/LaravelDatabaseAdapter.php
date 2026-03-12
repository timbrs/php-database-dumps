<?php

namespace Timbrs\DatabaseDumps\Adapter;

use Timbrs\DatabaseDumps\Contract\DatabaseConnectionInterface;
use Timbrs\DatabaseDumps\Platform\PlatformFactory;
use Illuminate\Database\Connection;

/**
 * Адаптер для Laravel Database Connection
 */
class LaravelDatabaseAdapter implements DatabaseConnectionInterface
{
    /** @var Connection */
    private $connection;

    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
    }

    public function executeStatement(string $sql): void
    {
        $this->connection->statement($sql);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function fetchAllAssociative(string $sql): array
    {
        if ($this->connection->getDriverName() === PlatformFactory::PGSQL) {
            return $this->fetchViaPdoWithBooleans($sql);
        }

        $results = $this->connection->select($sql);

        return array_map(function ($row) {
            return (array) $row;
        }, $results);
    }

    /**
     * @param string $sql
     * @return array<int, array<string, mixed>>
     */
    private function fetchViaPdoWithBooleans($sql)
    {
        $stmt = $this->connection->getPdo()->query($sql);
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
     * @param array<mixed> $params
     * @return array<int, mixed>
     */
    public function fetchFirstColumn(string $sql, array $params = []): array
    {
        $results = $this->connection->select($sql, $params);

        return array_map(function ($row) {
            $arr = (array) $row;
            return reset($arr);
        }, $results);
    }

    /**
     * @param mixed $value
     */
    public function quote($value): string
    {
        if (is_string($value)) {
            return $this->connection->getPdo()->quote($value);
        }

        return $this->connection->getPdo()->quote((string) $value);
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
        return $this->connection->transactionLevel() > 0;
    }

    public function getPlatformName(): string
    {
        $driver = $this->connection->getDriverName();

        switch ($driver) {
            case PlatformFactory::PGSQL:
                return PlatformFactory::POSTGRESQL;
            case PlatformFactory::MYSQL:
            case PlatformFactory::MARIADB:
                return PlatformFactory::MYSQL;
            case PlatformFactory::OCI:
            case PlatformFactory::ORACLE:
                return PlatformFactory::ORACLE;
            default:
                return $driver;
        }
    }
}
