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

    /** @var string|null cached platform name */
    private $platformName;

    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
    }

    /**
     * @param array<string, mixed> $params
     */
    public function executeStatement(string $sql, array $params = []): void
    {
        $this->connection->executeStatement($sql, $params);
    }

    /**
     * @param array<string, mixed> $params
     * @return array<int, array<string, mixed>>
     */
    public function fetchAllAssociative(string $sql, array $params = []): array
    {
        // Для PG нам важна boolean-нормализация — пытаемся через нативный PDO.
        if ($this->getPlatformName() === PlatformFactory::POSTGRESQL && empty($params)) {
            $pdo = $this->getNativePdo();
            if ($pdo !== null) {
                return $this->fetchViaPdoWithBooleans($pdo, $sql);
            }
        }

        $rows = $this->connection->fetchAllAssociative($sql, $params);

        if ($this->getPlatformName() === PlatformFactory::ORACLE) {
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
        if (method_exists($this->connection, 'iterateAssociative')) {
            foreach ($this->connection->iterateAssociative($sql, $params) as $row) {
                if ($this->getPlatformName() === PlatformFactory::ORACLE) {
                    $row = $this->normalizeOracleRow($row);
                }
                yield $row;
            }
            return;
        }

        // Fallback DBAL 2.x — нет streaming, используем массив.
        foreach ($this->fetchAllAssociative($sql, $params) as $row) {
            yield $row;
        }
    }

    /**
     * @param array<string, mixed> $params
     * @return array<int, mixed>
     */
    public function fetchFirstColumn(string $sql, array $params = []): array
    {
        return $this->connection->fetchFirstColumn($sql, $params);
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

        return $this->connection->quote((string) $value);
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

    public function getPlatformName(): string
    {
        if ($this->platformName !== null) {
            return $this->platformName;
        }

        $platform = $this->connection->getDatabasePlatform();
        $className = get_class($platform);

        if (strpos($className, 'PostgreSQL') !== false || strpos($className, 'Postgre') !== false) {
            return $this->platformName = PlatformFactory::POSTGRESQL;
        }

        if (strpos($className, 'MySQL') !== false || strpos($className, 'MariaDb') !== false) {
            return $this->platformName = PlatformFactory::MYSQL;
        }

        if (strpos($className, 'Oracle') !== false || strpos($className, 'OCI') !== false) {
            return $this->platformName = PlatformFactory::ORACLE;
        }

        return $this->platformName = PlatformFactory::POSTGRESQL;
    }

    private function fetchViaPdoWithBooleans(\PDO $pdo, string $sql): array
    {
        $stmt = $pdo->query($sql);
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

    private function getNativePdo(): ?\PDO
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
