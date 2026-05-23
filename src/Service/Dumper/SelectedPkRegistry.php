<?php

namespace Timbrs\DatabaseDumps\Service\Dumper;

/**
 * Реестр фактически выбранных значений колонок по таблицам.
 *
 * Заполняется при выборке таблицы с директивой sample (SampleQueryBuilder через
 * DataFetcher): сохраняются значения колонок первичного ключа отобранных строк.
 *
 * Читается CascadeWhereResolver: когда у родителя задан sample, дочерние таблицы
 * должны ссылаться на ИМЕННО выбранные id родителя (а не повторять критерии
 * подзапросом). Так дамп остаётся консистентным (orders ссылаются на тех clients,
 * что реально попали в выборку).
 *
 * Порядок экспорта гарантирует, что родитель дампится раньше детей
 * (TableDependencyResolver::sortForExport), поэтому к моменту построения WHERE
 * для ребёнка значения родителя уже зарегистрированы.
 */
class SelectedPkRegistry
{
    /**
     * key = "schema.table", value = [columnName => list<scalar>]
     *
     * @var array<string, array<string, array<int, mixed>>>
     */
    private $byTable = [];

    /**
     * Зарегистрировать выбранные значения колонок для таблицы.
     *
     * @param array<string, array<int, mixed>> $columnValues columnName => список значений
     */
    public function record(string $schema, string $table, array $columnValues): void
    {
        $this->byTable[$schema . '.' . $table] = $columnValues;
    }

    public function hasTable(string $schema, string $table): bool
    {
        return isset($this->byTable[$schema . '.' . $table]);
    }

    /**
     * Получить выбранные значения конкретной колонки таблицы.
     *
     * @return array<int, mixed>|null Список значений или null, если колонка/таблица не зарегистрированы.
     */
    public function getColumnValues(string $schema, string $table, string $column): ?array
    {
        $key = $schema . '.' . $table;
        if (!isset($this->byTable[$key]) || !array_key_exists($column, $this->byTable[$key])) {
            return null;
        }
        return $this->byTable[$key][$column];
    }

    /**
     * Сбросить реестр (полезно между независимыми прогонами экспорта).
     */
    public function clear(): void
    {
        $this->byTable = [];
    }
}
