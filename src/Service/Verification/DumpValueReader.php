<?php

namespace Timbrs\DatabaseDumps\Service\Verification;

/**
 * Читает значения ОДНОЙ колонки из файла дампа, не загружая файл в память.
 *
 * Формат разбирается тот, который пишет InsertGenerator, и только он:
 *
 *     INSERT INTO "schema"."table" ("a", "b") VALUES
 *     (1, 'text'),
 *     (2, NULL);
 *
 * Поэтому парсер не универсальный, а ровно под свой генератор: другого SQL
 * в dumps/ не бывает. Значения строк при этом никуда не сохраняются — наружу
 * отдаётся только запрошенная колонка, что важно, когда в соседних лежат ПД.
 */
class DumpValueReader
{
    /** Значение колонки, когда в строке дампа стоит NULL. */
    public const NULL_MARKER = null;

    /**
     * Прочитать значения колонки из файла дампа.
     *
     * @return array{found: bool, values: array<int, string|null>, rows: int}
     *         found=false — такой колонки нет ни в одном INSERT файла
     *         (в values в этом случае пусто, а rows считает строки дампа).
     */
    public function readColumn(string $dumpPath, string $column): array
    {
        $handle = @fopen($dumpPath, 'rb');
        if ($handle === false) {
            throw new \RuntimeException('Не удалось открыть дамп: ' . $dumpPath);
        }

        $values = [];
        $rows = 0;
        $found = false;
        $index = null;
        $inValues = false;
        $buffer = '';

        try {
            while (($line = fgets($handle)) !== false) {
                if (!$inValues) {
                    if (strncasecmp(ltrim($line), 'INSERT INTO', 11) !== 0) {
                        continue;
                    }
                    $header = $this->parseHeader($line);
                    if ($header === null) {
                        continue;
                    }
                    $index = $this->indexOfColumn($header, $column);
                    if ($index !== null) {
                        $found = true;
                    }
                    $inValues = true;
                    // Хвост после VALUES на той же строке — начало кортежей.
                    $line = $header['__tail'];
                }

                $buffer .= $line;
                $incomplete = false;
                foreach ($this->extractTuples($buffer, $incomplete) as $tuple) {
                    ++$rows;
                    if ($index !== null && array_key_exists($index, $tuple)) {
                        $values[] = $tuple[$index];
                    }
                }

                // `;` после последнего кортежа закрывает INSERT: дальше идут TRUNCATE,
                // setval и прочие statements, чьи скобки кортежами не являются.
                // Пока кортеж не закрыт, точка с запятой — это содержимое значения.
                if (!$incomplete && strpos($buffer, ';') !== false) {
                    $inValues = false;
                    $index = null;
                    $buffer = '';
                }
            }
        } finally {
            fclose($handle);
        }

        return ['found' => $found, 'values' => $values, 'rows' => $rows];
    }

    /**
     * Разобрать шапку INSERT: список колонок плюс остаток строки после VALUES.
     *
     * @return array<int|string, string>|null
     */
    private function parseHeader(string $line): ?array
    {
        if (!preg_match('/^\s*INSERT\s+INTO\s+\S+\s*\((.*?)\)\s*VALUES\b(.*)$/is', $line, $m)) {
            return null;
        }

        $columns = [];
        foreach (explode(',', $m[1]) as $raw) {
            $columns[] = $this->unquoteIdentifier(trim($raw));
        }
        $columns['__tail'] = $m[2];

        return $columns;
    }

    /**
     * @param array<int|string, string> $header
     */
    private function indexOfColumn(array $header, string $column): ?int
    {
        $needle = strtolower($this->unquoteIdentifier($column));
        foreach ($header as $i => $name) {
            if (!is_int($i)) {
                continue;
            }
            if (strtolower($name) === $needle) {
                return $i;
            }
        }

        return null;
    }

    private function unquoteIdentifier(string $identifier): string
    {
        $trimmed = trim($identifier);
        if (strlen($trimmed) >= 2) {
            $first = $trimmed[0];
            $last = substr($trimmed, -1);
            if (($first === '"' && $last === '"') || ($first === '`' && $last === '`')) {
                return substr($trimmed, 1, -1);
            }
        }

        return $trimmed;
    }

    /**
     * Выбрать из буфера завершённые кортежи `(...)`, остаток вернуть в буфер.
     *
     * Скобки внутри строковых литералов не считаются — иначе значение с «(»
     * рвало бы кортеж пополам.
     *
     * @return array<int, array<int, string|null>>
     */
    private function extractTuples(string &$buffer, bool &$incomplete = false): array
    {
        $tuples = [];
        $length = strlen($buffer);
        $consumed = 0;
        $start = null;
        $depth = 0;
        $inString = false;

        for ($i = 0; $i < $length; ++$i) {
            $char = $buffer[$i];

            if ($inString) {
                if ($char === "'") {
                    // '' внутри литерала — экранированная кавычка, не конец строки.
                    if ($i + 1 < $length && $buffer[$i + 1] === "'") {
                        ++$i;
                        continue;
                    }
                    $inString = false;
                }
                continue;
            }

            if ($char === "'") {
                $inString = true;
                continue;
            }

            if ($char === '(') {
                if ($depth === 0) {
                    $start = $i;
                }
                ++$depth;
                continue;
            }

            if ($char === ')') {
                --$depth;
                if ($depth === 0 && $start !== null) {
                    $tuples[] = $this->splitTuple(substr($buffer, $start + 1, $i - $start - 1));
                    $consumed = $i + 1;
                    $start = null;
                }
            }
        }

        // Незакрытый кортеж (строка с переводом строки внутри) остаётся ждать следующей порции.
        $incomplete = $start !== null;
        $buffer = $incomplete ? substr($buffer, $start) : substr($buffer, $consumed);

        return $tuples;
    }

    /**
     * Разбить содержимое кортежа по запятым верхнего уровня.
     *
     * @return array<int, string|null>
     */
    private function splitTuple(string $body): array
    {
        $values = [];
        $current = '';
        $length = strlen($body);
        $inString = false;
        $quoted = false;
        $closed = false;

        for ($i = 0; $i < $length; ++$i) {
            $char = $body[$i];

            if ($inString) {
                if ($char === "'") {
                    if ($i + 1 < $length && $body[$i + 1] === "'") {
                        $current .= "'";
                        ++$i;
                        continue;
                    }
                    $inString = false;
                    $closed = true;
                    continue;
                }
                $current .= $char;
                continue;
            }

            if ($char === "'") {
                // Пробелы до литерала — синтаксис, а не значение.
                $inString = true;
                $quoted = true;
                $current = '';
                continue;
            }

            if ($char === ',') {
                $values[] = $this->normalize($current, $quoted);
                $current = '';
                $quoted = false;
                $closed = false;
                continue;
            }

            // После закрывающей кавычки до запятой идут только пробелы — они не часть значения.
            if (!$closed) {
                $current .= $char;
            }
        }

        $values[] = $this->normalize($current, $quoted);

        return $values;
    }

    /**
     * Литерал отдаётся как есть — внутренние пробелы значимы. Всё остальное
     * (число, boolean, NULL) обрезается по краям.
     */
    private function normalize(string $value, bool $quoted): ?string
    {
        if ($quoted) {
            return $value;
        }

        $trimmed = trim($value);

        return strcasecmp($trimmed, 'NULL') === 0 ? self::NULL_MARKER : $trimmed;
    }
}
