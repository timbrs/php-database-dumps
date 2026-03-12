<?php

namespace Timbrs\DatabaseDumps\Adapter;

use Timbrs\DatabaseDumps\Contract\DatabaseConnectionInterface;
use Timbrs\DatabaseDumps\Platform\PlatformFactory;

/**
 * Универсальный адаптер для PDO
 */
class PdoAdapter implements DatabaseConnectionInterface
{
    /** @var \PDO */
    private $pdo;

    /** @var string */
    private $driverName;

    public function __construct(\PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->driverName = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
    }

    public function executeStatement(string $sql): void
    {
        $this->pdo->exec($sql);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function fetchAllAssociative(string $sql): array
    {
        $stmt = $this->pdo->query($sql);
        if ($stmt === false) {
            return [];
        }
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        if ($this->driverName === 'pgsql' && !empty($rows)) {
            $rows = BooleanNormalizer::normalize($stmt, $rows);
        }

        if ($this->driverName === 'oci') {
            return array_map(function (array $row) {
                $normalized = array_change_key_case($row, CASE_LOWER);
                foreach ($normalized as $key => $value) {
                    if (is_resource($value)) {
                        $normalized[$key] = stream_get_contents($value);
                    }
                }
                return $normalized;
            }, $rows);
        }

        return $rows;
    }

    /**
     * @param array<mixed> $params
     * @return array<int, mixed>
     */
    public function fetchFirstColumn(string $sql, array $params = []): array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(\PDO::FETCH_COLUMN);
    }

    public function quote($value): string
    {
        return $this->pdo->quote((string) $value);
    }

    public function beginTransaction(): void
    {
        $this->pdo->beginTransaction();
    }

    public function commit(): void
    {
        $this->pdo->commit();
    }

    public function rollBack(): void
    {
        $this->pdo->rollBack();
    }

    public function isTransactionActive(): bool
    {
        return $this->pdo->inTransaction();
    }

    public function getPlatformName(): string
    {
        switch ($this->driverName) {
            case 'oci':
                return PlatformFactory::ORACLE;
            case 'pgsql':
                return PlatformFactory::POSTGRESQL;
            case 'mysql':
                return PlatformFactory::MYSQL;
            default:
                return $this->driverName;
        }
    }
}
