<?php

namespace Timbrs\DatabaseDumps\Contract;

/**
 * Интерфейс абстракции платформы БД
 *
 * Обеспечивает генерацию SQL совместимого с конкретной СУБД
 */
interface DatabasePlatformInterface
{
    /**
     * Экранировать идентификатор (имя колонки, таблицы)
     */
    public function quoteIdentifier(string $identifier): string;

    /**
     * Получить полное имя таблицы со схемой
     */
    public function getFullTableName(string $schema, string $table): string;

    /**
     * Получить TRUNCATE statement
     */
    public function getTruncateStatement(string $schema, string $table): string;

    /**
     * Получить SQL для сброса sequence/auto-increment
     */
    public function getSequenceResetSql(string $schema, string $table, DatabaseConnectionInterface $connection): string;

    /**
     * Получить SQL-функцию случайного числа для платформы
     */
    public function getRandomFunctionSql(): string;

    /**
     * Получить SQL-выражение для ограничения количества строк
     */
    public function getLimitSql(int $limit): string;
}
