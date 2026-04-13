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
     * Извлечь список столбцов из первого INSERT-выражения в SQL
     *
     * @param string $sqlContent
     * @return array<string>|null Список столбцов или null, если INSERT не найден
     */
    public function parseColumnList(string $sqlContent): ?array
    {
        // Ищем первый INSERT INTO ... (columns) VALUES
        if (preg_match('/INSERT\s+INTO\s+\S+\s*\(([^)]+)\)\s*VALUES/i', $sqlContent, $matches)) {
            $columnsStr = $matches[1];
            $columns = array_map(function ($col) {
                // Убираем кавычки и пробелы
                $col = trim($col);
                $col = trim($col, '"');
                $col = trim($col, '`');
                $col = trim($col, '[]');
                return $col;
            }, explode(',', $columnsStr));

            return array_values(array_filter($columns, function ($col) {
                return $col !== '';
            }));
        }

        return null;
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
