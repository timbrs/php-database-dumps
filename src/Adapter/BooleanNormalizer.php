<?php

namespace Timbrs\DatabaseDumps\Adapter;

/**
 * Нормализация boolean-колонок PostgreSQL через PDOStatement метаданные
 *
 * PostgreSQL PDO возвращает boolean как строки 't'/'f' (PHP < 8.1)
 * или PHP boolean (PHP >= 8.1). Используем getColumnMeta() для определения
 * boolean-колонок без дополнительных SQL-запросов.
 */
class BooleanNormalizer
{
    /**
     * @param \PDOStatement $stmt
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    public static function normalize(\PDOStatement $stmt, array $rows)
    {
        if (empty($rows)) {
            return $rows;
        }

        $boolColumns = array();
        $columnCount = $stmt->columnCount();
        for ($i = 0; $i < $columnCount; $i++) {
            $meta = $stmt->getColumnMeta($i);
            if ($meta !== false && isset($meta['native_type']) && $meta['native_type'] === 'bool') {
                $boolColumns[] = $meta['name'];
            }
        }

        if (empty($boolColumns)) {
            return $rows;
        }

        foreach ($rows as &$row) {
            foreach ($boolColumns as $col) {
                if (array_key_exists($col, $row) && $row[$col] !== null) {
                    $row[$col] = ($row[$col] === 't' || $row[$col] === true || $row[$col] === '1' || $row[$col] === 1);
                }
            }
        }
        unset($row);

        return $rows;
    }
}
