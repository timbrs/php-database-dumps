<?php

namespace Timbrs\DatabaseDumps\Service\Db;

/**
 * Разбор текстового литерала массива PostgreSQL: {a,"b, c",NULL,"с \"кавычкой\""}.
 *
 * Так PDO отдаёт most_common_vals / histogram_bounds из pg_stats после приведения к text:
 * колонки типа anyarray наружу иначе не выходят. Вложенные массивы не поддерживаются —
 * в статистике их нет.
 */
class PgArrayLiteralParser
{
    /**
     * @return array<int, string|null>|null null — строка не является литералом массива
     */
    public static function parse(?string $literal): ?array
    {
        if ($literal === null) {
            return null;
        }
        $s = trim($literal);
        $brace = strpos($s, '{');
        if ($brace === false || substr($s, -1) !== '}') {
            return null;
        }
        // Декорация размерности вида [1:3]={...} допустима только перед скобкой.
        if ($brace > 0) {
            if ($s[0] !== '[') {
                return null;
            }
            $s = substr($s, $brace);
        }
        $inner = substr($s, 1, -1);
        if (trim($inner) === '') {
            return [];
        }

        $result = [];
        $len = strlen($inner);
        $i = 0;
        while (true) {
            while ($i < $len && $inner[$i] === ' ') {
                $i++;
            }
            if ($i < $len && $inner[$i] === '"') {
                $i++;
                $buf = '';
                $closed = false;
                while ($i < $len) {
                    $c = $inner[$i];
                    if ($c === '\\' && $i + 1 < $len) {
                        $buf .= $inner[$i + 1];
                        $i += 2;
                        continue;
                    }
                    if ($c === '"') {
                        $i++;
                        $closed = true;
                        break;
                    }
                    $buf .= $c;
                    $i++;
                }
                if (!$closed) {
                    return null;
                }
                $result[] = $buf;
            } else {
                $start = $i;
                while ($i < $len && $inner[$i] !== ',') {
                    $i++;
                }
                $raw = trim(substr($inner, $start, $i - $start));
                $result[] = $raw === 'NULL' ? null : $raw;
            }

            while ($i < $len && $inner[$i] === ' ') {
                $i++;
            }
            if ($i >= $len) {
                break;
            }
            if ($inner[$i] !== ',') {
                return null;
            }
            $i++;
        }

        return $result;
    }

    /**
     * Тот же разбор для массивов чисел (most_common_freqs — real[]).
     *
     * @return array<int, float>|null
     */
    public static function parseFloats(?string $literal): ?array
    {
        $parsed = self::parse($literal);
        if ($parsed === null) {
            return null;
        }
        $out = [];
        foreach ($parsed as $value) {
            if ($value === null || !is_numeric($value)) {
                return null;
            }
            $out[] = (float) $value;
        }

        return $out;
    }
}
