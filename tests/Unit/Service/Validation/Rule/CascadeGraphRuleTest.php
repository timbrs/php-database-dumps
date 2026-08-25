<?php

namespace Timbrs\DatabaseDumps\Tests\Unit\Service\Validation\Rule;

use Timbrs\DatabaseDumps\Service\Validation\Finding;
use Timbrs\DatabaseDumps\Service\Validation\Rule\CascadeGraphRule;
use Timbrs\DatabaseDumps\Tests\Support\ValidationTestCase;

/**
 * G-4 было самым ценным правилом набора, пока порядок экспорта строился на одних лишь
 * FK-констрейнтах: их в этой базе 0 из 245 таблиц, порядок вырождался в алфавитный, и
 * родитель с sample, чьё имя идёт позже имени ребёнка, попадал в дамп уже после него.
 *
 * Теперь cascade_from подаётся в граф порядка вторым источником рёбер, и штатный случай
 * закрыт кодом. Правило остаётся детектором ОСТАТОЧНОЙ опасности: ребро могли разорвать
 * ради цикла, и тогда всё возвращается ровно туда, где было.
 */
class CascadeGraphRuleTest extends ValidationTestCase
{
    /**
     * @param array<string, array<string, mixed>> $tables schema.table => настройки
     * @param array<string, array<string, array<string, mixed>>> $inventory
     * @return array<int, Finding>
     */
    private function findings(array $tables, array $inventory = []): array
    {
        $bySchema = [];
        foreach ($tables as $key => $conf) {
            [$schema, $table] = explode('.', $key, 2);
            if (!isset($bySchema[$schema])) {
                $bySchema[$schema] = ['partial_export' => []];
            }
            $bySchema[$schema]['partial_export'][$table] = $conf;
        }

        $files = $this->splitConfig($bySchema);
        if (empty($inventory)) {
            $inventory = $this->inventoryFor(array_keys($tables));
        }
        $files[self::INVENTORY_PATH] = $this->inventoryJson($inventory);

        return (new CascadeGraphRule())->apply($this->context($files));
    }

    /**
     * Плоский слепок без FK: ровно та ситуация, в которой топосортировка вырождается
     * в алфавитный порядок.
     *
     * @param array<int, string> $keys
     * @return array<string, array<string, array<string, mixed>>>
     */
    private function inventoryFor(array $keys): array
    {
        $inventory = [];
        foreach ($keys as $key) {
            [$schema, $table] = explode('.', $key, 2);
            $inventory[$schema][$table] = [
                'row_count' => 100,
                'columns' => ['id' => 'bigint', 'job_id' => 'bigint', 'user_id' => 'bigint'],
            ];
        }
        return $inventory;
    }

    /**
     * @return array<string, mixed>
     */
    private function sampled(): array
    {
        return [
            'limit' => 500,
            'sample' => ['criteria' => [['name' => 'any', 'where' => 'id > 0', 'limit' => 10]]],
        ];
    }

    /**
     * @param string $parent
     * @return array<string, mixed>
     */
    private function cascadeTo(string $parent, string $column = 'job_id'): array
    {
        return [
            'limit' => 500,
            'cascade_from' => [['parent' => $parent, 'fk_column' => $column, 'parent_column' => $column]],
        ];
    }

    /**
     * Тот самый случай, ради которого правило писалось: «tasks.activities» < «tasks.jobs»,
     * FK в схеме нет. Раньше здесь была находка G-4; теперь ребро cascade_from ставит
     * родителя впереди, и остаётся только заметка G-5.
     *
     * Это ИЗМЕНЕНИЕ СОСТАВА ДАМПА, а не перестановка: строки детей начинают соответствовать
     * выборке родителя, чего до фикса не было.
     */
    public function testCascadeFromNowOrdersParentBeforeChild(): void
    {
        $findings = $this->findings([
            'tasks.activities' => $this->cascadeTo('tasks.jobs'),
            'tasks.jobs' => $this->sampled(),
        ]);

        $this->assertSame(0, $this->countCode($findings, 'G-4'));
        $this->assertSame(1, $this->countCode($findings, 'G-5'));
    }

    /**
     * У правила остаются зубы. Цикл в cascade_from заставляет сортировщик разорвать одно
     * из рёбер, и разорванное возвращает исходную опасность: родитель с sample снова
     * уезжает позже ребёнка. Показать это важнее, чем показать красивый порядок.
     */
    public function testG4StillFiresWhenCascadeEdgeIsBrokenByCycle(): void
    {
        $parent = $this->cascadeTo('tasks.activities', 'id');
        $parent['sample'] = ['criteria' => [['name' => 'any', 'where' => 'id > 0', 'limit' => 10]]];

        $findings = $this->findings([
            'tasks.activities' => $this->cascadeTo('tasks.jobs'),
            'tasks.jobs' => $parent,
        ]);

        $this->assertSame(1, $this->countCode($findings, 'G-2'), 'цикл обязан быть показан');
        $this->assertGreaterThan(
            0,
            $this->countCode($findings, 'G-4'),
            'разорванное ребро возвращает исходную опасность, и молчать об этом нельзя'
        );
    }

    public function testParentSortedBeforeChildIsOnlyANote(): void
    {
        // «tasks.aaa_parent» < «tasks.children» — родитель уедет первым, реестр сработает.
        $findings = $this->findings([
            'tasks.children' => $this->cascadeTo('tasks.aaa_parent'),
            'tasks.aaa_parent' => $this->sampled(),
        ]);

        $this->assertSame(0, $this->countCode($findings, 'G-4'));
        $this->assertSame(1, $this->countCode($findings, 'G-5'));
    }

    public function testParentWithoutSampleIsNotReported(): void
    {
        // Подзапрос по limit/where детерминирован и повторяет выборку родителя.
        $findings = $this->findings([
            'tasks.activities' => $this->cascadeTo('tasks.jobs'),
            'tasks.jobs' => ['limit' => 500],
        ]);

        $this->assertSame([], $this->codes($findings));
    }

    public function testFullExportParentIsNotReported(): void
    {
        $files = $this->splitConfig([
            'tasks' => [
                'full_export' => ['jobs'],
                'partial_export' => ['activities' => $this->cascadeTo('tasks.jobs')],
            ],
        ]);
        $files[self::INVENTORY_PATH] = $this->inventoryJson($this->inventoryFor(['tasks.jobs', 'tasks.activities']));

        $this->assertSame([], $this->codes((new CascadeGraphRule())->apply($this->context($files))));
    }

    public function testMissingParentIsError(): void
    {
        $findings = $this->findings(['tasks.activities' => $this->cascadeTo('tasks.jobs')]);

        $g1 = $this->firstWithCode($findings, 'G-1');
        $this->assertNotNull($g1);
        $this->assertSame(Finding::SEVERITY_ERROR, $g1->getSeverity());
    }

    public function testCycleIsError(): void
    {
        $findings = $this->findings([
            'tasks.a' => $this->cascadeTo('tasks.b'),
            'tasks.b' => $this->cascadeTo('tasks.a'),
        ]);

        $this->assertSame(1, $this->countCode($findings, 'G-2'));
    }

    public function testChainLongerThanMaxDepthIsReported(): void
    {
        $bySchema = ['tasks' => ['partial_export' => []]];
        // Цепочка t0 → t1 → … → t11 при max_cascade_depth = 2.
        for ($i = 0; $i < 12; $i++) {
            $bySchema['tasks']['partial_export']['t' . $i] = $i === 11
                ? ['limit' => 10]
                : $this->cascadeTo('tasks.t' . ($i + 1), 'id');
        }

        $files = $this->splitConfig($bySchema);
        $files[self::CONFIG_PATH] = str_replace(
            'includes:',
            "settings:\n  max_cascade_depth: 2\nincludes:",
            $files[self::CONFIG_PATH]
        );
        $keys = [];
        for ($i = 0; $i < 12; $i++) {
            $keys[] = 'tasks.t' . $i;
        }
        $files[self::INVENTORY_PATH] = $this->inventoryJson($this->inventoryFor($keys));

        $findings = (new CascadeGraphRule())->apply($this->context($files));

        $this->assertGreaterThan(0, $this->countCode($findings, 'G-3'));
    }

    public function testSampleTogetherWithCascadeIsANote(): void
    {
        $config = $this->cascadeTo('tasks.jobs');
        $config['sample'] = ['criteria' => [['name' => 'any', 'where' => 'id > 0', 'limit' => 5]]];

        $findings = $this->findings([
            'tasks.activities' => $config,
            'tasks.jobs' => ['limit' => 500],
        ]);

        $g6 = $this->firstWithCode($findings, 'G-6');
        $this->assertNotNull($g6);
        $this->assertSame(Finding::SEVERITY_NOTE, $g6->getSeverity());
    }

    /**
     * FK в слепке возвращают топосортировке рёбра — порядок перестаёт быть алфавитным,
     * и родитель уезжает раньше ребёнка даже при «неудачном» имени.
     */
    public function testForeignKeyInInventoryFixesTheOrder(): void
    {
        $inventory = [
            'tasks' => [
                'activities' => [
                    'row_count' => 100,
                    'columns' => ['id' => 'bigint', 'job_id' => 'bigint'],
                    'foreign_keys' => [[
                        'column' => 'job_id',
                        'references_table' => 'tasks.jobs',
                        'references_column' => 'job_id',
                    ]],
                ],
                'jobs' => ['row_count' => 100, 'columns' => ['id' => 'bigint', 'job_id' => 'bigint']],
            ],
        ];

        $findings = $this->findings([
            'tasks.activities' => $this->cascadeTo('tasks.jobs'),
            'tasks.jobs' => $this->sampled(),
        ], $inventory);

        $this->assertSame(0, $this->countCode($findings, 'G-4'));
        $this->assertSame(1, $this->countCode($findings, 'G-5'));
    }
}
