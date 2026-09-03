<?php

namespace Timbrs\DatabaseDumps\Service\Verification;

/**
 * Потоковый разбор файла дампа: значения запрошенных колонок отдаются по кортежу,
 * файл в память не поднимается.
 *
 * Формат разбирается тот, который пишет InsertGenerator, и только он:
 *
 *     INSERT INTO "schema"."table" ("a", "b") VALUES
 *     (1, 'text'),
 *     (2, NULL);
 *
 * Поэтому парсер не универсальный, а ровно под свой генератор: другого SQL
 * в dumps/ не бывает. Символы незапрошенных колонок не собираются даже в строку —
 * в них могут лежать ПД, и наружу уходит только то, что попросили.
 */
class DumpValueReader
{
    /** Значение колонки, когда в строке дампа стоит NULL. */
    public const NULL_MARKER = null;

    /** Запросить все колонки файла (их имена известны только из шапки INSERT). */
    public const ALL_COLUMNS = '*';

    /**
     * Прочитать значения одной колонки.
     *
     * @return array{found: bool, values: array<int, string|null>, rows: int}
     *         found=false — такой колонки нет ни в одном INSERT файла
     *         (в values в этом случае пусто, а rows считает строки дампа).
     */
    public function readColumn(string $dumpPath, string $column): array
    {
        $values = [];
        $result = $this->scan($dumpPath, [$column], function (array $row) use (&$values, $column): void {
            if (array_key_exists($column, $row)) {
                $values[] = $row[$column];
            }
        });

        return [
            'found' => $result['found'][$column] ?? false,
            'values' => $values,
            'rows' => $result['rows'],
        ];
    }

    /**
     * Один проход по файлу для нескольких колонок.
     *
     * @param array<int, string> $columns  имена колонок; [self::ALL_COLUMNS] — все колонки файла
     * @param callable           $visitor  получает array<string, string|null>: значения запрошенных
     *                                     колонок кортежа (только найденных в шапке INSERT)
     * @param callable|null      $onHeader получает array<int, string> — колонки очередного INSERT
     *
     * @return array{found: array<string, bool>, rows: int, columns: array<int, string>}
     */
    public function scan(string $dumpPath, array $columns, callable $visitor, callable $onHeader = null): array
    {
        $handle = @fopen($dumpPath, 'rb');
        if ($handle === false) {
            throw new \RuntimeException('Не удалось открыть дамп: ' . $dumpPath);
        }

        $all = in_array(self::ALL_COLUMNS, $columns, true);
        $found = [];
        foreach ($columns as $column) {
            if ($column !== self::ALL_COLUMNS) {
                $found[$column] = false;
            }
        }

        $headerColumns = [];
        $rows = 0;
        $inValues = false;
        $buffer = '';
        /** @var array<int, string> $names индекс в кортеже => имя для visitor */
        $names = [];
        /** @var array<int, true>|null $wanted индексы, чьи символы собирать; null — все */
        $wanted = [];

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
                    $tail = $header['__tail'];
                    unset($header['__tail']);
                    /** @var array<int, string> $header */
                    if ($headerColumns === []) {
                        $headerColumns = array_values($header);
                    }
                    if ($onHeader !== null) {
                        $onHeader(array_values($header));
                    }

                    $names = [];
                    $wanted = [];
                    if ($all) {
                        foreach ($header as $i => $name) {
                            $names[$i] = $name;
                        }
                        $wanted = null;
                    } else {
                        foreach ($columns as $column) {
                            $index = $this->indexOfColumn($header, $column);
                            if ($index !== null) {
                                $names[$index] = $column;
                                $wanted[$index] = true;
                                $found[$column] = true;
                            }
                        }
                    }

                    $inValues = true;
                    // Хвост после VALUES на той же строке — начало кортежей.
                    $line = $tail;
                }

                $buffer .= $line;
                $incomplete = false;
                foreach ($this->extractTuples($buffer, $incomplete, $wanted) as $tuple) {
                    ++$rows;
                    if ($names === []) {
                        continue;
                    }
                    $row = [];
                    foreach ($names as $index => $name) {
                        $row[$name] = array_key_exists($index, $tuple) ? $tuple[$index] : null;
                    }
                    $visitor($row);
                }

                // `;` после последнего кортежа закрывает INSERT: дальше идут TRUNCATE,
                // setval и прочие statements, чьи скобки кортежами не являются.
                // Пока кортеж не закрыт, точка с запятой — это содержимое значения.
                if (!$incomplete && strpos($buffer, ';') !== false) {
                    $inValues = false;
                    $names = [];
                    $wanted = [];
                    $buffer = '';
                }
            }
        } finally {
            fclose($handle);
        }

        return ['found' => $found, 'rows' => $rows, 'columns' => $headerColumns];
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
     * @param array<int, true>|null $wanted индексы значений, которые собирать; null — все
     *
     * @return array<int, array<int, string|null>>
     */
    private function extractTuples(string &$buffer, bool &$incomplete = false, ?array $wanted = null): array
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
                    $tuples[] = $this->splitTuple(substr($buffer, $start + 1, $i - $start - 1), $wanted);
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
     * Разбить содержимое кортежа по запятым верхнего уровня. Символы позиций, которых нет
     * в $wanted, не накапливаются — только пропускаются.
     *
     * @param array<int, true>|null $wanted
     *
     * @return array<int, string|null> только запрошенные позиции
     */
    private function splitTuple(string $body, ?array $wanted): array
    {
        $values = [];
        $current = '';
        $length = strlen($body);
        $inString = false;
        $quoted = false;
        $closed = false;
        $position = 0;
        $collect = $wanted === null || isset($wanted[0]);

        for ($i = 0; $i < $length; ++$i) {
            $char = $body[$i];

            if ($inString) {
                if ($char === "'") {
                    if ($i + 1 < $length && $body[$i + 1] === "'") {
                        if ($collect) {
                            $current .= "'";
                        }
                        ++$i;
                        continue;
                    }
                    $inString = false;
                    $closed = true;
                    continue;
                }
                if ($collect) {
                    $current .= $char;
                }
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
                if ($collect) {
                    $values[$position] = $this->normalize($current, $quoted);
                }
                ++$position;
                $collect = $wanted === null || isset($wanted[$position]);
                $current = '';
                $quoted = false;
                $closed = false;
                continue;
            }

            // После закрывающей кавычки до запятой идут только пробелы — они не часть значения.
            if (!$closed && $collect) {
                $current .= $char;
            }
        }

        if ($collect) {
            $values[$position] = $this->normalize($current, $quoted);
        }

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
