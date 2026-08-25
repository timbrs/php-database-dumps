<?php

namespace Timbrs\DatabaseDumps\Service\Graph;

use Timbrs\DatabaseDumps\Service\ConfigGenerator\ForeignKeyInspector;

/**
 * Резолвер FK-зависимостей.
 *
 * Семантика порядка:
 * - sortForExport — родители впереди (для генерации дампа: чтобы при импорте
 *   родительские строки уже существовали к моменту INSERT детей).
 * - sortForImport — то же самое (parents first), так как INSERT детей нуждается
 *   в существующих родителях; TRUNCATE/DELETE для MySQL/Oracle делаются
 *   с временно отключёнными FK-checks, для PG — через CASCADE.
 *
 * Циклы FK разрываются автоматически через TopologicalSorter::sortWithCycleBreaking
 * (предпочитая разорвать nullable-рёбра — они потом восстанавливаются через
 * DeferredUpdateGenerator после основного INSERT).
 */
class TableDependencyResolver
{
    /** @var ForeignKeyInspector */
    private $fkInspector;

    /** @var TopologicalSorter */
    private $sorter;

    /** @var array<string, array<string, array<string, array{source_column: string, target_column: string}>>> */
    private $graphCache = [];

    public function __construct(ForeignKeyInspector $fkInspector, TopologicalSorter $sorter)
    {
        $this->fkInspector = $fkInspector;
        $this->sorter = $sorter;
    }

    /**
     * Универсальный метод сортировки.
     *
     * @param array<string> $tableKeys "schema.table" keys
     * @param array<string, array<string, array{source_column: string, target_column: string}>> $extraEdges
     */
    public function sort(array $tableKeys, ?string $connectionName = null, array $extraEdges = []): SortResult
    {
        return $this->sortTablesWithCycleBreaking($tableKeys, $connectionName, $extraEdges);
    }

    /**
     * @param array<string> $tableKeys
     * @param array<string, array<string, array{source_column: string, target_column: string}>> $extraEdges
     * @return array<string>
     */
    public function sortForExport(array $tableKeys, ?string $connectionName = null, array $extraEdges = []): array
    {
        return $this->sort($tableKeys, $connectionName, $extraEdges)->getSorted();
    }

    /**
     * Импорт — тот же порядок (родители первыми), поскольку INSERT детей
     * требует существующих родителей.
     *
     * @param array<string> $tableKeys
     * @param array<string, array<string, array{source_column: string, target_column: string}>> $extraEdges
     * @return array<string>
     */
    public function sortForImport(array $tableKeys, ?string $connectionName = null, array $extraEdges = []): array
    {
        return $this->sortForExport($tableKeys, $connectionName, $extraEdges);
    }

    /**
     * @param array<string> $tableKeys
     * @param array<string, array<string, array{source_column: string, target_column: string}>> $extraEdges
     */
    public function sortForExportWithResult(array $tableKeys, ?string $connectionName = null, array $extraEdges = []): SortResult
    {
        return $this->sort($tableKeys, $connectionName, $extraEdges);
    }

    /**
     * Превращает `cascade_from` из конфига выгрузки в рёбра графа порядка.
     *
     * Зачем это вообще нужно. Граф зависимостей строится только из FK-констрейнтов
     * (getDependencyGraph выше), а в этой базе их 0 из 245 таблиц — топологическая
     * сортировка получает граф без рёбер и вырождается в алфавитный порядок. Родитель,
     * чьё имя сортируется позже ребёнка, выгружается ПОСЛЕ него; к моменту построения
     * WHERE ребёнка SelectedPkRegistry пуст, CascadeWhereResolver откатывается к
     * подзапросу, а подзапрос не повторяет sample.criteria родителя — и строки детей
     * расходятся с реально выгруженными родителями.
     *
     * Связи между таблицами в такой базе живут только в конфиге и в коде, и `cascade_from` —
     * это ровно они. Отсюда второй источник рёбер.
     *
     * ВАЖНО, и это не перестановка ради красоты: правильный порядок МЕНЯЕТ СОСТАВ ДАМПА.
     * Строки детей начинают соответствовать выборке родителей — в этом и смысл, — но это
     * осознанное изменение того, какие строки попадут в дамп, а не безопасная сортировка.
     *
     * Тип входа намеренно широкий: сюда приходит и разобранный TableConfig, и сырой массив
     * из YAML — импорт читает конфиг напрямую, без валидации. Обещать здесь строгую форму
     * значило бы снять проверки ровно там, где данные и правда бывают кривыми.
     *
     * @param array<string, array<int, mixed>> $cascadeByChild
     * @return array<string, array<string, array{source_column: string, target_column: string}>>
     */
    public static function cascadeEdges(array $cascadeByChild): array
    {
        $edges = [];
        foreach ($cascadeByChild as $childKey => $entries) {
            foreach ($entries as $entry) {
                if (!is_array($entry) || !isset($entry['parent'])) {
                    continue;
                }
                $parentKey = (string) $entry['parent'];
                // Самоссылка порядок не задаёт: сортировщик всё равно отправит её в deferred.
                if ($parentKey === '' || $parentKey === $childKey) {
                    continue;
                }
                if (!isset($edges[$childKey])) {
                    $edges[$childKey] = [];
                }
                $edges[$childKey][$parentKey] = [
                    'source_column' => isset($entry['fk_column']) ? (string) $entry['fk_column'] : '',
                    'target_column' => isset($entry['parent_column']) ? (string) $entry['parent_column'] : '',
                ];
            }
        }
        return $edges;
    }

    /**
     * @return array<string, array<string, array{source_column: string, target_column: string}>>
     */
    public function getDependencyGraph(?string $connectionName = null): array
    {
        $cacheKey = $connectionName ?? '__default__';
        if (isset($this->graphCache[$cacheKey])) {
            return $this->graphCache[$cacheKey];
        }

        $fks = $this->fkInspector->getForeignKeys($connectionName);
        $graph = [];

        foreach ($fks as $fk) {
            $child = $fk['source_schema'] . '.' . $fk['source_table'];
            $parent = $fk['target_schema'] . '.' . $fk['target_table'];

            if (!isset($graph[$child])) {
                $graph[$child] = [];
            }
            $graph[$child][$parent] = [
                'source_column' => $fk['source_column'],
                'target_column' => $fk['target_column'],
            ];
        }

        $this->graphCache[$cacheKey] = $graph;
        return $graph;
    }

    /**
     * @return array<int, array{parent: string, fk_column: string, parent_column: string}>
     */
    public function getCascadeFromCandidates(string $childSchema, string $childTable, ?string $connectionName = null): array
    {
        $graph = $this->getDependencyGraph($connectionName);
        $childKey = $childSchema . '.' . $childTable;

        if (!isset($graph[$childKey])) {
            return [];
        }

        $candidates = [];
        foreach ($graph[$childKey] as $parentKey => $columns) {
            $candidates[] = [
                'parent' => $parentKey,
                'fk_column' => $columns['source_column'],
                'parent_column' => $columns['target_column'],
            ];
        }

        return $candidates;
    }

    /**
     * @param array<string> $tableKeys
     * @param array<string, array<string, array{source_column: string, target_column: string}>> $extraEdges
     */
    private function sortTablesWithCycleBreaking(array $tableKeys, ?string $connectionName, array $extraEdges = []): SortResult
    {
        $graph = $this->getDependencyGraph($connectionName);
        $fks = $this->fkInspector->getForeignKeys($connectionName);

        $tableKeySet = array_flip($tableKeys);
        $adjacency = [];
        $edgeDetails = [];

        foreach ($tableKeys as $key) {
            $adjacency[$key] = [];
        }

        foreach ($tableKeys as $childKey) {
            if (!isset($graph[$childKey])) {
                continue;
            }
            foreach ($graph[$childKey] as $parentKey => $columns) {
                if (!isset($tableKeySet[$parentKey])) {
                    continue;
                }
                // Дедуп — несколько FK на одного родителя не должны давать дубль ребра
                if (!in_array($parentKey, $adjacency[$childKey], true)) {
                    $adjacency[$childKey][] = $parentKey;
                }
                if (!isset($edgeDetails[$childKey])) {
                    $edgeDetails[$childKey] = [];
                }
                $edgeDetails[$childKey][$parentKey] = $columns;
            }
        }

        // Рёбра из конфига добавляются ПОСЛЕ FK и не затирают их детали: FK — факт схемы,
        // cascade_from — утверждение конфига, и при расхождении верить надо схеме.
        foreach ($extraEdges as $childKey => $parents) {
            if (!isset($tableKeySet[$childKey])) {
                continue;
            }
            foreach ($parents as $parentKey => $columns) {
                if (!isset($tableKeySet[$parentKey]) || $parentKey === $childKey) {
                    continue;
                }
                if (!in_array($parentKey, $adjacency[$childKey], true)) {
                    $adjacency[$childKey][] = $parentKey;
                }
                if (!isset($edgeDetails[$childKey])) {
                    $edgeDetails[$childKey] = [];
                }
                if (!isset($edgeDetails[$childKey][$parentKey])) {
                    $edgeDetails[$childKey][$parentKey] = $columns;
                }
            }
        }

        // У ребра из конфига нет признака nullable — на нём построен приоритет разрыва
        // циклов, и подделывать его нечем. Отсутствие записи означает «рвать в последнюю
        // очередь»: сортировщик сперва ищет nullable-ребро и только потом любое.
        $nullability = $this->fkInspector->getForeignKeyNullability($fks, $connectionName);
        $nullableEdges = [];
        foreach ($tableKeys as $childKey) {
            if (!isset($graph[$childKey])) {
                continue;
            }
            foreach ($graph[$childKey] as $parentKey => $columns) {
                if (!isset($tableKeySet[$parentKey])) {
                    continue;
                }
                $parts = explode('.', $childKey, 2);
                if (count($parts) !== 2) {
                    continue;
                }
                $nullKey = $parts[0] . '.' . $parts[1] . '.' . $columns['source_column'];
                $edgeKey = $childKey . '->' . $parentKey;
                if (isset($nullability[$nullKey])) {
                    $nullableEdges[$edgeKey] = $nullability[$nullKey];
                }
            }
        }

        return $this->sorter->sortWithCycleBreaking($adjacency, $nullableEdges, $edgeDetails);
    }
}
