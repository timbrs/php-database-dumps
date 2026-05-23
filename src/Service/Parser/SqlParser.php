<?php

namespace Timbrs\DatabaseDumps\Service\Parser;

/**
 * Парсинг SQL дампов.
 *
 * Тонкая обёртка над StatementSplitter + утилитарный parseColumnList для
 * извлечения списка колонок из первого INSERT (используется SchemaValidator).
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
     * Распарсить SQL дамп на отдельные statements.
     *
     * @return array<string>
     */
    public function parseFile(string $sqlContent, bool $backslashEscapes = false): array
    {
        return $this->splitter->split($sqlContent, $backslashEscapes);
    }

    /**
     * Извлечь список столбцов из первого INSERT-выражения.
     *
     * Использует character-by-character парсер, аналогичный StatementSplitter,
     * чтобы корректно работать с запятыми в строковых литералах внутри INSERT
     * (теоретически — внутри списка колонок их быть не должно, но защищаемся).
     *
     * @return array<string>|null
     */
    public function parseColumnList(string $sqlContent): ?array
    {
        if (!preg_match('/INSERT\s+INTO\s+\S+\s*\(/i', $sqlContent, $matches, PREG_OFFSET_CAPTURE)) {
            return null;
        }

        $offset = $matches[0][1] + strlen($matches[0][0]);
        $len = strlen($sqlContent);
        $depth = 1;
        $collected = '';

        for ($i = $offset; $i < $len; $i++) {
            $c = $sqlContent[$i];

            // Пропускаем кавычки целиком, чтобы запятая внутри не сбила
            if ($c === '"' || $c === '`' || $c === "'") {
                $closing = $c;
                $collected .= $c;
                $i++;
                while ($i < $len) {
                    $cc = $sqlContent[$i];
                    $collected .= $cc;
                    if ($cc === $closing) {
                        if ($i + 1 < $len && $sqlContent[$i + 1] === $closing) {
                            $collected .= $sqlContent[$i + 1];
                            $i++;
                            continue;
                        }
                        break;
                    }
                    $i++;
                }
                continue;
            }

            if ($c === '(') {
                $depth++;
                $collected .= $c;
                continue;
            }
            if ($c === ')') {
                $depth--;
                if ($depth === 0) {
                    break;
                }
                $collected .= $c;
                continue;
            }
            $collected .= $c;
        }

        $columns = [];
        foreach (explode(',', $collected) as $col) {
            $col = trim($col);
            $col = trim($col, '"');
            $col = trim($col, '`');
            $col = trim($col, '[]');
            if ($col !== '') {
                $columns[] = $col;
            }
        }

        return $columns;
    }
}
