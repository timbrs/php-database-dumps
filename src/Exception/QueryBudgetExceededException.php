<?php

namespace Timbrs\DatabaseDumps\Exception;

/**
 * Профиль analyze исчерпал бюджет запросов к БД (database_dumps.db.query_budget).
 *
 * Бюджет — предохранитель от разведки, которая незаметно превращается в полный обход
 * боевой базы: каждая таблица × каждая колонка × каждый DISTINCT. Выгрузка и импорт
 * бюджет не считают — им положено читать много.
 */
class QueryBudgetExceededException extends \RuntimeException
{
    public static function forBudget(int $budget, string $connectionName): self
    {
        return new self(sprintf(
            'Бюджет запросов к БД (%d) исчерпан на подключении "%s". '
            . 'Увеличьте database_dumps.db.query_budget или сузьте анализ до одной схемы.',
            $budget,
            $connectionName
        ));
    }
}
