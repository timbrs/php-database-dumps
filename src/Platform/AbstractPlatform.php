<?php

namespace Timbrs\DatabaseDumps\Platform;

use Timbrs\DatabaseDumps\Contract\DatabasePlatformInterface;

/**
 * Базовая платформа с общей логикой quoteIdentifier/getFullTableName/getLimitSql.
 *
 * Подклассы переопределяют getIdentifierQuote() (символ кавычки) и при необходимости
 * прочие методы.
 */
abstract class AbstractPlatform implements DatabasePlatformInterface
{
    /**
     * Символ кавычки идентификатора (" для PG/Oracle, ` для MySQL)
     */
    abstract protected function getIdentifierQuote(): string;

    /**
     * Поддерживает ли платформа multi-row INSERT (INSERT INTO ... VALUES (...), (...))
     */
    public function supportsMultiRowInsert(): bool
    {
        return true;
    }

    /**
     * Преобразовать имя идентификатора перед квотированием
     * (Oracle делает UPPERCASE для unquoted; здесь мы оставляем как есть для quoted-режима)
     */
    protected function normalizeIdentifier(string $identifier): string
    {
        return $identifier;
    }

    /**
     * Экранировать идентификатор. Внутренние кавычки удваиваются (защита от SQL-инъекций).
     */
    public function quoteIdentifier(string $identifier): string
    {
        $quote = $this->getIdentifierQuote();
        $normalized = $this->normalizeIdentifier($identifier);
        $escaped = str_replace($quote, $quote . $quote, $normalized);

        return $quote . $escaped . $quote;
    }

    public function getFullTableName(string $schema, string $table): string
    {
        return $this->quoteIdentifier($schema) . '.' . $this->quoteIdentifier($table);
    }

    public function getLimitSql(int $limit): string
    {
        return 'LIMIT ' . $limit;
    }

    /**
     * Платформо-зависимый литерал boolean.
     * По умолчанию используется TRUE/FALSE (Postgres). Oracle/MySQL переопределяют.
     */
    public function quoteBoolean(bool $value): string
    {
        return $value ? 'TRUE' : 'FALSE';
    }

    public function disableForeignKeysSql(): ?string
    {
        return null;
    }

    public function enableForeignKeysSql(): ?string
    {
        return null;
    }
}
