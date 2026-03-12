<?php

namespace Timbrs\DatabaseDumps\Service\Parser;

/**
 * Парсинг SQL файлов
 */
class SqlParser
{
    /** @var StatementSplitter */
    private $splitter;

    public function __construct(StatementSplitter $splitter)
    {
        $this->splitter = $splitter;
    }

    /**
     * Распарсить SQL файл на отдельные statements
     *
     * @param string $sqlContent
     * @param bool $backslashEscapes Включить MySQL-style backslash-экранирование
     * @return array<string>
     */
    public function parseFile(string $sqlContent, $backslashEscapes = false): array
    {
        return $this->splitter->split($sqlContent, $backslashEscapes);
    }

    /**
     * Проверить, является ли SQL валидным (базовая проверка)
     */
    public function isValid(string $sql): bool
    {
        $sql = trim($sql);

        if (empty($sql)) {
            return false;
        }

        // Базовая проверка на наличие SQL ключевых слов
        $keywords = ['SELECT', 'INSERT', 'UPDATE', 'DELETE', 'TRUNCATE', 'CREATE', 'DROP', 'ALTER', 'SET'];

        foreach ($keywords as $keyword) {
            if (stripos($sql, $keyword) === 0) {
                return true;
            }
        }

        return false;
    }
}
