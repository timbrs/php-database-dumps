<?php

namespace Timbrs\DatabaseDumps\Service\Verification;

/**
 * Приёмник значений одной колонки дампа. Получает значения по одному во время прохода
 * по файлу и хранит ровно то, что нужно проверке, — а не все значения подряд: дамп
 * на сотни мегабайт целиком в память не поднимается.
 */
interface ColumnSinkInterface
{
    public function accept(?string $value): void;
}
