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
        $this->driverName = (string) $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
    }

    /**
     * @param array<string, mixed> $params
     */
    public function executeStatement(string $sql, array $params = []): void
    {
        if (empty($params)) {
            // PDO::exec возвращает int (rows affected) или false при ошибке.
            // Под ERRMODE_EXCEPTION ошибка кинет PDOException, но под SILENT/WARNING
            // вернётся false — поэтому явно проверяем.
            $result = $this->pdo->exec($sql);
            if ($result === false) {
                $err = $this->pdo->errorInfo();
                $msg = isset($err[2]) && $err[2] !== null ? $err[2] : 'unknown PDO error';
                throw new \RuntimeException('executeStatement failed: ' . $msg);
            }
            return;
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $stmt->closeCursor();
    }

    /**
     * @param array<string, mixed> $params
     * @return array<int, array<string, mixed>>
     */
    public function fetchAllAssociative(string $sql, array $params = []): array
    {
        $stmt = empty($params) ? $this->pdo->query($sql) : $this->prepareAndExecute($sql, $params);
        if ($stmt === false) {
            return [];
        }

        try {
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            if ($this->driverName === 'pgsql' && !empty($rows)) {
                $rows = BooleanNormalizer::normalize($stmt, $rows);
            }

            if ($this->driverName === 'oci') {
                $rows = $this->normalizeOracleRows($rows);
            }

            return $rows;
        } finally {
            $stmt->closeCursor();
        }
    }

    /**
     * @param array<string, mixed> $params
     * @return \Generator<int, array<string, mixed>>
     */
    public function iterateAssociative(string $sql, array $params = []): \Generator
    {
        $stmt = empty($params) ? $this->pdo->query($sql) : $this->prepareAndExecute($sql, $params);
        if ($stmt === false) {
            return;
        }

        // Для PG нужны метаданные для нормализации boolean. Снимем один раз.
        $boolColumns = [];
        if ($this->driverName === 'pgsql') {
            $columnCount = $stmt->columnCount();
            for ($i = 0; $i < $columnCount; $i++) {
                $meta = $stmt->getColumnMeta($i);
                if ($meta !== false && isset($meta['native_type']) && $meta['native_type'] === 'bool') {
                    $boolColumns[] = $meta['name'];
                }
            }
        }

        try {
            while (($row = $stmt->fetch(\PDO::FETCH_ASSOC)) !== false) {
                if (!empty($boolColumns)) {
                    foreach ($boolColumns as $col) {
                        if (array_key_exists($col, $row) && $row[$col] !== null) {
                            $row[$col] = ($row[$col] === 't' || $row[$col] === true
                                || $row[$col] === '1' || $row[$col] === 1);
                        }
                    }
                }
                if ($this->driverName === 'oci') {
                    $row = $this->normalizeOracleRow($row);
                }
                yield $row;
            }
        } finally {
            $stmt->closeCursor();
        }
    }

    /**
     * @param array<string, mixed> $params
     * @return array<int, mixed>
     */
    public function fetchFirstColumn(string $sql, array $params = []): array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        try {
            return $stmt->fetchAll(\PDO::FETCH_COLUMN);
        } finally {
            $stmt->closeCursor();
        }
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

    /**
     * @param array<string, mixed> $params
     */
    private function prepareAndExecute(string $sql, array $params): \PDOStatement
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function normalizeOracleRows(array $rows): array
    {
        return array_map([$this, 'normalizeOracleRow'], $rows);
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
