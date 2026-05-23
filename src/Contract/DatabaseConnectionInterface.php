<?php

namespace Timbrs\DatabaseDumps\Contract;

/**
 * Интерфейс для работы с подключением к БД.
 *
 * Абстракция над конкретными реализациями (Doctrine DBAL, PDO, Laravel DB).
 *
 * Параметризованные методы используют именованные placeholders в формате `:name`.
 */
interface DatabaseConnectionInterface
{
    /**
     * Выполнить SQL statement (без выборки).
     *
     * @param array<string, mixed> $params Именованные параметры для prepared statement
     */
    public function executeStatement(string $sql, array $params = []): void;

    /**
     * Получить все строки как ассоциативный массив.
     *
     * @param array<string, mixed> $params Именованные параметры для prepared statement
     * @return array<int, array<string, mixed>>
     */
    public function fetchAllAssociative(string $sql, array $params = []): array;

    /**
     * Стримовая итерация по результатам (без загрузки всей выборки в память).
     *
     * @param array<string, mixed> $params Именованные параметры для prepared statement
     * @return \Generator<int, array<string, mixed>>
     */
    public function iterateAssociative(string $sql, array $params = []): \Generator;

    /**
     * Получить первую колонку всех строк.
     *
     * @param array<string, mixed> $params Именованные параметры для prepared statement
     * @return array<int, mixed>
     */
    public function fetchFirstColumn(string $sql, array $params = []): array;

    /**
     * Экранировать значение для безопасного использования в SQL-литералах.
     *
     * Соглашение: NULL → 'NULL'; bool/int/float — числа без кавычек; строки — экранированные с кавычками.
     *
     * @param mixed $value
     */
    public function quote($value): string;

    public function beginTransaction(): void;

    public function commit(): void;

    public function rollBack(): void;

    public function isTransactionActive(): bool;

    /**
     * Получить имя платформы БД ('postgresql' | 'mysql' | 'oracle' и т.д.)
     */
    public function getPlatformName(): string;
}
