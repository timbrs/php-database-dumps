<?php

namespace Timbrs\DatabaseDumps\Service\Db;

use Timbrs\DatabaseDumps\Contract\DatabaseConnectionInterface;
use Timbrs\DatabaseDumps\Exception\QueryBudgetExceededException;

/**
 * Декоратор соединения, считающий запросы и останавливающий разведку по бюджету.
 *
 * Оборачивает подключение в ConnectionRegistry::register(); наружу выглядит как обычное
 * соединение. Считаются только методы, которые действительно идут в БД; quote() и управление
 * транзакциями — нет. Решение «бросать или нет» принимает SafeQueryPolicy: в профилях export и
 * import бюджета нет.
 */
class CountingConnection implements DatabaseConnectionInterface
{
    /** @var DatabaseConnectionInterface */
    private $inner;

    /** @var SafeQueryPolicy */
    private $policy;

    /** @var string */
    private $connectionName;

    /** @var int */
    private $count = 0;

    public function __construct(DatabaseConnectionInterface $inner, SafeQueryPolicy $policy, string $connectionName = 'default')
    {
        $this->inner = $inner;
        $this->policy = $policy;
        $this->connectionName = $connectionName;
    }

    public function getInner(): DatabaseConnectionInterface
    {
        return $this->inner;
    }

    public function getQueryCount(): int
    {
        return $this->count;
    }

    public function resetQueryCount(): void
    {
        $this->count = 0;
    }

    /**
     * @param array<string, mixed> $params
     */
    public function executeStatement(string $sql, array $params = []): void
    {
        $this->tick();
        $this->inner->executeStatement($sql, $params);
    }

    /**
     * @param array<string, mixed> $params
     * @return array<int, array<string, mixed>>
     */
    public function fetchAllAssociative(string $sql, array $params = []): array
    {
        $this->tick();
        return $this->inner->fetchAllAssociative($sql, $params);
    }

    /**
     * Не генератор намеренно: счётчик срабатывает в момент вызова, а не первой итерации.
     *
     * @param array<string, mixed> $params
     * @return \Generator<int, array<string, mixed>>
     */
    public function iterateAssociative(string $sql, array $params = []): \Generator
    {
        $this->tick();
        return $this->inner->iterateAssociative($sql, $params);
    }

    /**
     * @param array<string, mixed> $params
     * @return array<int, mixed>
     */
    public function fetchFirstColumn(string $sql, array $params = []): array
    {
        $this->tick();
        return $this->inner->fetchFirstColumn($sql, $params);
    }

    /**
     * @param mixed $value
     */
    public function quote($value): string
    {
        return $this->inner->quote($value);
    }

    public function beginTransaction(): void
    {
        $this->inner->beginTransaction();
    }

    public function commit(): void
    {
        $this->inner->commit();
    }

    public function rollBack(): void
    {
        $this->inner->rollBack();
    }

    public function isTransactionActive(): bool
    {
        return $this->inner->isTransactionActive();
    }

    public function getPlatformName(): string
    {
        return $this->inner->getPlatformName();
    }

    private function tick(): void
    {
        $this->count++;
        if (!$this->policy->allowsQuery($this->count)) {
            throw QueryBudgetExceededException::forBudget($this->policy->getQueryBudget(), $this->connectionName);
        }
    }
}
