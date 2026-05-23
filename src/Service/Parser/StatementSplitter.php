<?php

namespace Timbrs\DatabaseDumps\Service\Parser;

/**
 * Разбивает SQL файл на отдельные statements.
 *
 * Поддерживает:
 * - Однострочные комментарии (-- ...)
 * - Многострочные комментарии (/ * ... * /)
 * - Строковые литералы в одинарных кавычках (с SQL '' и опционально MySQL \-escape)
 * - Идентификаторы в двойных кавычках ("...", удвоение "")
 * - PostgreSQL dollar-quoted strings ($$...$$ и $tag$...$tag$)
 * - Базовая поддержка Oracle PL/SQL: BEGIN...END блоки трактуются как один statement
 *   (счётчик глубины по ключевым словам BEGIN/CASE и END).
 */
class StatementSplitter
{
    /**
     * @param bool $backslashEscapes MySQL-style backslash-escape (\' и \\). Для PG/Oracle — false.
     * @return array<string>
     */
    public function split(string $sql, $backslashEscapes = false): array
    {
        $statements = [];
        $current = '';
        $len = strlen($sql);
        $i = 0;
        $plsqlDepth = 0;

        while ($i < $len) {
            $char = $sql[$i];

            // Однострочный комментарий
            if ($char === '-' && $i + 1 < $len && $sql[$i + 1] === '-') {
                $end = strpos($sql, "\n", $i);
                $i = $end === false ? $len : $end + 1;
                continue;
            }

            // Многострочный комментарий
            if ($char === '/' && $i + 1 < $len && $sql[$i + 1] === '*') {
                $end = strpos($sql, '*/', $i + 2);
                $i = $end === false ? $len : $end + 2;
                continue;
            }

            // PostgreSQL dollar-quoted string: $$ или $tag$
            if ($char === '$') {
                $tag = $this->matchDollarTag($sql, $i);
                if ($tag !== null) {
                    $tagLen = strlen($tag);
                    $current .= $tag;
                    $i += $tagLen;
                    // Ищем закрывающий тег
                    $end = strpos($sql, $tag, $i);
                    if ($end === false) {
                        // Незакрытый dollar-tag — копируем остаток
                        $current .= substr($sql, $i);
                        $i = $len;
                    } else {
                        $current .= substr($sql, $i, $end - $i + $tagLen);
                        $i = $end + $tagLen;
                    }
                    continue;
                }
            }

            // Одинарные кавычки
            if ($char === '\'') {
                $current .= $char;
                $i++;
                while ($i < $len) {
                    $c = $sql[$i];
                    if ($backslashEscapes && $c === '\\' && $i + 1 < $len) {
                        $current .= $c . $sql[$i + 1];
                        $i += 2;
                        continue;
                    }
                    if ($c === '\'' && $i + 1 < $len && $sql[$i + 1] === '\'') {
                        $current .= $c . $sql[$i + 1];
                        $i += 2;
                        continue;
                    }
                    $current .= $c;
                    $i++;
                    if ($c === '\'') {
                        break;
                    }
                }
                continue;
            }

            // Двойные кавычки (идентификаторы)
            if ($char === '"') {
                $current .= $char;
                $i++;
                while ($i < $len) {
                    $c = $sql[$i];
                    if ($c === '"' && $i + 1 < $len && $sql[$i + 1] === '"') {
                        $current .= $c . $sql[$i + 1];
                        $i += 2;
                        continue;
                    }
                    $current .= $c;
                    $i++;
                    if ($c === '"') {
                        break;
                    }
                }
                continue;
            }

            // Backticks (MySQL идентификаторы)
            if ($char === '`') {
                $current .= $char;
                $i++;
                while ($i < $len) {
                    $c = $sql[$i];
                    $current .= $c;
                    $i++;
                    if ($c === '`') {
                        break;
                    }
                }
                continue;
            }

            // PL/SQL BEGIN/END detection (только на границах слов).
            // CASE..END в выражениях SELECT тоже даёт BEGIN/END-подобные пары —
            // отслеживаем их через counter caseDepth, чтобы END от CASE не закрывал PL/SQL block.
            if (($char === 'C' || $char === 'c') && $this->matchWord($sql, $i, 'CASE') && $plsqlDepth > 0) {
                $plsqlDepth++;
                $current .= substr($sql, $i, 4);
                $i += 4;
                continue;
            }
            if (($char === 'B' || $char === 'b') && $this->matchWord($sql, $i, 'BEGIN')) {
                $plsqlDepth++;
                $current .= substr($sql, $i, 5);
                $i += 5;
                continue;
            }
            if (($char === 'E' || $char === 'e') && $this->matchWord($sql, $i, 'END') && $plsqlDepth > 0) {
                $current .= substr($sql, $i, 3);
                $i += 3;
                $plsqlDepth--;
                continue;
            }

            // Разделитель
            if ($char === ';' && $plsqlDepth === 0) {
                $trimmed = trim($current);
                if ($trimmed !== '') {
                    $statements[] = $trimmed;
                }
                $current = '';
                $i++;
                continue;
            }

            $current .= $char;
            $i++;
        }

        $trimmed = trim($current);
        if ($trimmed !== '') {
            $statements[] = $trimmed;
        }

        return $statements;
    }

    /**
     * Распознать dollar-quoted tag в позиции $i.
     *
     * По спецификации PostgreSQL tag должен начинаться с буквы или подчёркивания
     * ([A-Za-z_]), а внутри допускаются цифры. Это предотвращает ложное
     * срабатывание на арифметику типа `WHERE price > $100$`.
     *
     * Возвращает строку тега ($$ или $tag$), или null.
     */
    private function matchDollarTag(string $sql, int $i): ?string
    {
        $len = strlen($sql);
        if ($i >= $len || $sql[$i] !== '$') {
            return null;
        }

        // $$ — пустой тег
        if ($i + 1 < $len && $sql[$i + 1] === '$') {
            return '$$';
        }

        // $tag$ — первый символ обязан быть [A-Za-z_]
        if ($i + 1 >= $len || !preg_match('/[A-Za-z_]/', $sql[$i + 1])) {
            return null;
        }

        $j = $i + 1;
        while ($j < $len) {
            $c = $sql[$j];
            if ($c === '$') {
                if ($j > $i + 1) {
                    return substr($sql, $i, $j - $i + 1);
                }
                return null;
            }
            if (!preg_match('/[A-Za-z0-9_]/', $c)) {
                return null;
            }
            $j++;
        }
        return null;
    }

    /**
     * Проверить, что в позиции $i стоит целое слово $word (на границе non-word).
     */
    private function matchWord(string $sql, int $i, string $word): bool
    {
        $wordLen = strlen($word);
        $len = strlen($sql);
        if ($i + $wordLen > $len) {
            return false;
        }
        if (strcasecmp(substr($sql, $i, $wordLen), $word) !== 0) {
            return false;
        }
        // Проверка границы слова с обеих сторон
        $before = $i > 0 ? $sql[$i - 1] : ' ';
        $after = $i + $wordLen < $len ? $sql[$i + $wordLen] : ' ';
        if (preg_match('/[A-Za-z0-9_]/', $before) || preg_match('/[A-Za-z0-9_]/', $after)) {
            return false;
        }
        return true;
    }
}
