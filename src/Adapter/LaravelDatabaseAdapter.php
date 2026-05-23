<?php

namespace Timbrs\DatabaseDumps\Adapter;

use Illuminate\Database\Connection;
use Timbrs\DatabaseDumps\Contract\DatabaseConnectionInterface;
use Timbrs\DatabaseDumps\Platform\PlatformFactory;

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

    /**
     * @param array<string, mixed> $params
     */
    public function executeStatement(string $sql, array $params = []): void
    {
        if (empty($params)) {
            $this->connection->statement($sql);
            return;
        }
        $this->connection->statement($sql, $params);
    }

    /**
     * @param array<string, mixed> $params
     * @return array<int, array<string, mixed>>
     */
    public function fetchAllAssociative(string $sql, array $params = []): array
    {
        $platform = $this->getPlatformName();

        // PG: используем PDO напрямую для boolean-нормализации.
        if ($platform === PlatformFactory::POSTGRESQL && empty($params)) {
            return $this->fetchViaPdoWithBooleans($sql);
        }

        $results = $this->connection->select($sql, $params);
        $rows = array_map(function ($row) {
            return (array) $row;
        }, $results);

        if ($platform === PlatformFactory::ORACLE) {
            $rows = array_map([$this, 'normalizeOracleRow'], $rows);
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $params
     * @return \Generator<int, array<string, mixed>>
     */
    public function iterateAssociative(string $sql, array $params = []): \Generator
    {
        // Laravel cursor() даёт PDO-курсор без загрузки в память.
        // Generator из cursor() закрывает PDOStatement при GC (при выходе из
        // foreach или break). На MySQL c unbuffered query это важно, чтобы
        // не получить "commands out of sync" при следующем запросе.
        $cursor = $this->connection->cursor($sql, $params);
        try {
            foreach ($cursor as $row) {
                $arr = (array) $row;
                if ($this->getPlatformName() === PlatformFactory::ORACLE) {
                    $arr = $this->normalizeOracleRow($arr);
                }
                yield $arr;
            }
        } finally {
            // unset форсирует GC, очистка PDOStatement
            unset($cursor);
        }
    }

    /**
     * @param array<string, mixed> $params
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
        if ($value === null) {
            return 'NULL';
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
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

    private function fetchViaPdoWithBooleans(string $sql): array
    {
        $stmt = $this->connection->getPdo()->query($sql);
        if ($stmt === false) {
            return [];
        }
        try {
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            if (empty($rows)) {
                return $rows;
            }
            return BooleanNormalizer::normalize($stmt, $rows);
        } finally {
            $stmt->closeCursor();
        }
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function normalizeOracleRow(array $row): array
    {
        $normalized = array_change_key_case($row, CASE_LOWER);
        foreach ($normalized as $key => $value) {
            if (is_resource($value)) {
                $normalized[$key] = stream_get_contents($value);
            }
        }
        return $normalized;
    }
}
