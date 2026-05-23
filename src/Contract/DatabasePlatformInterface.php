<?php

namespace Timbrs\DatabaseDumps\Contract;

/**
 * Интерфейс абстракции платформы БД.
 *
 * Обеспечивает генерацию SQL, совместимого с конкретной СУБД.
 */
interface DatabasePlatformInterface
{
    /**
     * Экранировать идентификатор (имя колонки, таблицы).
     * Реализация должна удваивать внутренние кавычки (защита от SQL-инъекций).
     */
    public function quoteIdentifier(string $identifier): string;

    /**
     * Получить полное имя таблицы со схемой.
     */
    public function getFullTableName(string $schema, string $table): string;

    /**
     * Получить SQL-statement для очистки таблицы.
     * Для платформ, где TRUNCATE = неявный COMMIT (MySQL/Oracle), возвращает DELETE FROM.
     */
    public function getTruncateStatement(string $schema, string $table): string;

    /**
     * Получить SQL для сброса sequence / auto-increment / identity до next-value.
     */
    public function getSequenceResetSql(string $schema, string $table, DatabaseConnectionInterface $connection): string;

    /**
     * Получить SQL-функцию случайного числа для платформы (для ORDER BY).
     */
    public function getRandomFunctionSql(): string;

    /**
     * Получить SQL-выражение для ограничения количества строк.
     */
    public function getLimitSql(int $limit): string;

    /**
     * Поддерживает ли платформа multi-row INSERT (INSERT INTO ... VALUES (...), (...))
     */
    public function supportsMultiRowInsert(): bool;

    /**
     * Получить платформо-зависимый литерал boolean.
     * PG: TRUE/FALSE. MySQL/Oracle: 1/0.
     */
    public function quoteBoolean(bool $value): string;

    /**
     * SQL для отключения проверки FK-constraints на текущей сессии.
     * Используется в импорте для возможности TRUNCATE/INSERT в порядке,
     * нарушающем FK (например, для разрыва циклов).
     *
     * @return string|null SQL или null если платформа не требует этого
     */
    public function disableForeignKeysSql(): ?string;

    /**
     * SQL для включения проверки FK-constraints обратно.
     *
     * @return string|null
     */
    public function enableForeignKeysSql(): ?string;
}
