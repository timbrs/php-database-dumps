<?php

namespace Timbrs\DatabaseDumps\Service\Analysis;

/**
 * Валидатор WHERE-фрагментов sample.criteria на пригодность для дампера.
 *
 * Дампер (SampleQueryBuilder) выполняет ОДНОТАБЛИЧНЫЙ запрос:
 *   SELECT <pk> FROM <schema.table> WHERE (base) AND (<sql_where>)
 * — без алиасов таблицы, без JOIN'ов и без bind-параметров. Слабые модели часто копируют
 * WHERE прямо из ORM/DQL приложения (t1.col, :param, camelCase-свойства), из-за чего экспорт
 * падает («missing FROM-clause entry for table "t1"» / несвязанный параметр / нет колонки).
 *
 * Класс stateless (без зависимостей) — используется и на ингесте (синтаксическая отбраковка),
 * и в цикле исправления (полная проверка + сверка колонок с инвентарём для корректирующего промпта).
 */
class CriteriaValidator
{
    /** Алиас таблицы вида t1./t2./t3. (конвенция анализатора) — в однотабличном SELECT его нет. */
    private const ALIAS_REGEX = '/\bt\d+\s*\./i';

    /** Именованный bind-параметр :name, НО не Postgres-каст ::type (двойное двоеточие). */
    private const BIND_PARAM_REGEX = '/(?<![:\w]):[A-Za-z_]\w*/';

    /**
     * SQL-ключевые слова / литералы / типы, которые НЕ являются колонками. Регистр не важен
     * (сравнение в нижнем). Функции (идентификатор + «(») отсекаются отдельно по контексту.
     *
     * @var array<int, string>
     */
    private const SQL_WORDS = [
        'and', 'or', 'not', 'null', 'is', 'in', 'between', 'like', 'ilike', 'similar', 'to',
        'exists', 'any', 'all', 'some', 'case', 'when', 'then', 'else', 'end', 'true', 'false',
        'asc', 'desc', 'distinct', 'as', 'on', 'using', 'cast', 'interval', 'escape', 'select',
        'from', 'where', 'group', 'by', 'having', 'order', 'limit', 'offset', 'union', 'nulls',
        'current_date', 'current_time', 'current_timestamp', 'localtime', 'localtimestamp',
        // имена типов (на случай CAST(x AS text) / несъеденного каста)
        'int', 'integer', 'bigint', 'smallint', 'serial', 'text', 'varchar', 'char', 'boolean',
        'bool', 'numeric', 'decimal', 'real', 'double', 'precision', 'date', 'time', 'timestamp',
        'timestamptz', 'uuid', 'json', 'jsonb', 'bytea', 'float',
    ];

    /**
     * Синтаксические проблемы WHERE, гарантированно ломающие однотабличный дамп: алиас таблицы
     * и bind-параметр. Пустой массив — синтаксически пригоден. Колонки НЕ проверяются.
     *
     * @return array<int, string> человекочитаемые проблемы (для лога/промпта)
     */
    public function syntaxProblems(string $where): array
    {
        $problems = [];
        if (preg_match(self::ALIAS_REGEX, $where)) {
            $problems[] = 'алиас таблицы (t1./t2.) — дампер выполняет однотабличный SELECT без JOIN';
        }
        if (preg_match(self::BIND_PARAM_REGEX, $where)) {
            $problems[] = 'bind-параметр (:name) — дампер не заполняет параметры, нужны литералы';
        }
        return $problems;
    }

    /**
     * Полная проверка: синтаксис + сверка колонок с реальными из инвентаря (если переданы).
     * Пустой массив — критерий пригоден.
     *
     * @param array<int, string> $knownColumns реальные колонки таблицы (из schema_inventory)
     * @return array<int, string> человекочитаемые проблемы
     */
    public function problems(string $where, array $knownColumns = []): array
    {
        $problems = $this->syntaxProblems($where);

        if (!empty($knownColumns)) {
            $unknown = $this->unknownColumns($where, $knownColumns);
            if (!empty($unknown)) {
                $problems[] = 'неизвестные колонки (проверь имена в инвентаре): ' . implode(', ', $unknown);
            }
        }

        return $problems;
    }

    /**
     * Пригоден ли фрагмент синтаксически (нет алиаса/параметра). Удобная обёртка для ингеста.
     */
    public function isDumpable(string $where): bool
    {
        return empty($this->syntaxProblems($where));
    }

    /**
     * Идентификаторы из WHERE, которых нет среди реальных колонок таблицы. Эвристика:
     * убираем строковые литералы и ::type-касты, отбрасываем функции (идентификатор + «(»)
     * и SQL-ключевые слова, сравниваем в нижнем регистре (PG сворачивает неэкранированные
     * идентификаторы к lower). Точечные ссылки (alias.col) ловит отдельная проверка алиасов.
     *
     * @param array<int, string> $knownColumns
     * @return array<int, string> уникальные неизвестные идентификаторы (в нижнем регистре)
     */
    private function unknownColumns(string $where, array $knownColumns): array
    {
        $known = [];
        foreach ($knownColumns as $col) {
            $known[strtolower((string) $col)] = true;
        }

        // Убрать строковые литералы '...' (с экранированием '') и "..." и ::type-касты.
        $stripped = preg_replace("/'(?:[^']|'')*'/", ' ', $where);
        $stripped = $stripped === null ? $where : $stripped;
        $stripped = preg_replace('/"[^"]*"/', ' ', $stripped);
        $stripped = $stripped === null ? $where : $stripped;
        $noCast = preg_replace('/::\s*[A-Za-z_][A-Za-z0-9_]*/', ' ', $stripped);
        $stripped = $noCast === null ? $stripped : $noCast;

        if (!preg_match_all('/[A-Za-z_][A-Za-z0-9_]*/', $stripped, $matches, PREG_OFFSET_CAPTURE)) {
            return [];
        }

        $unknown = [];
        foreach ($matches[0] as $match) {
            $token = $match[0];
            $offset = (int) $match[1];
            $lower = strtolower($token);

            if (isset($known[$lower]) || in_array($lower, self::SQL_WORDS, true)) {
                continue;
            }
            // Функция? Следующий непробельный символ — «(».
            $rest = substr($stripped, $offset + strlen($token));
            if ($rest !== false && preg_match('/^\s*\(/', $rest)) {
                continue;
            }
            $unknown[$lower] = true;
        }

        return array_keys($unknown);
    }
}
