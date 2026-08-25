<?php

namespace Timbrs\DatabaseDumps\Tests\Unit\Service\Graph;

use Timbrs\DatabaseDumps\Service\ConfigGenerator\ForeignKeyInspector;
use Timbrs\DatabaseDumps\Service\Graph\TableDependencyResolver;
use Timbrs\DatabaseDumps\Service\Graph\TopologicalSorter;
use PHPUnit\Framework\TestCase;

class TableDependencyResolverTest extends TestCase
{
    /** @var ForeignKeyInspector&\PHPUnit\Framework\MockObject\MockObject */
    private $fkInspector;
    /** @var TableDependencyResolver */
    private $resolver;

    protected function setUp(): void
    {
        $this->fkInspector = $this->createMock(ForeignKeyInspector::class);
        $this->fkInspector->method('getForeignKeyNullability')->willReturn([]);
        $this->resolver = new TableDependencyResolver($this->fkInspector, new TopologicalSorter());
    }

    public function testSortForExportWithFkGraph(): void
    {
        // orders -> users (orders depends on users)
        $this->fkInspector->method('getForeignKeys')->willReturn([
            [
                'constraint_name' => 'fk_orders_user',
                'source_schema' => 'public',
                'source_table' => 'orders',
                'source_column' => 'user_id',
                'target_schema' => 'public',
                'target_table' => 'users',
                'target_column' => 'id',
            ],
        ]);

        $sorted = $this->resolver->sortForExport(['public.users', 'public.orders']);
        $this->assertEquals(['public.users', 'public.orders'], $sorted);
    }

    /**
     * В базе без FK-констрейнтов (0 из 245 таблиц в crm) граф пуст, и топологическая
     * сортировка вырождается в алфавитную: `activities` уезжает раньше своего родителя
     * `jobs`. К моменту построения WHERE ребёнка реестр выбранных id пуст, резолвер
     * откатывается к подзапросу, а подзапрос не повторяет sample.criteria родителя —
     * это и есть находка G-4. cascade_from из конфига задаёт то самое ребро.
     */
    public function testCascadeFromOrdersParentBeforeChildWithoutForeignKeys(): void
    {
        $this->fkInspector->method('getForeignKeys')->willReturn([]);

        $keys = ['tasks.activities', 'tasks.jobs'];

        $withoutConfig = $this->resolver->sortForExport($keys);
        $this->assertEquals(
            ['tasks.activities', 'tasks.jobs'],
            $withoutConfig,
            'без рёбер порядок алфавитный — ребёнок раньше родителя, ровно как в G-4'
        );

        $edges = TableDependencyResolver::cascadeEdges([
            'tasks.activities' => [
                ['parent' => 'tasks.jobs', 'fk_column' => 'job_id', 'parent_column' => 'id'],
            ],
        ]);

        $withConfig = $this->resolver->sortForExport($keys, null, $edges);
        $this->assertEquals(
            ['tasks.jobs', 'tasks.activities'],
            $withConfig,
            'ребро из cascade_from обязано поставить родителя впереди ребёнка'
        );
    }

    /**
     * Родителя нет в выгрузке — ребро не выдумывается. Это находка G-1, и решать её
     * должен валидатор, а не сортировщик, молча дорисовавший в граф чужой узел.
     */
    public function testCascadeFromIgnoresParentOutsideExport(): void
    {
        $this->fkInspector->method('getForeignKeys')->willReturn([]);

        $edges = TableDependencyResolver::cascadeEdges([
            'tasks.activities' => [
                ['parent' => 'other.absent', 'fk_column' => 'x_id', 'parent_column' => 'id'],
            ],
        ]);

        $sorted = $this->resolver->sortForExport(['tasks.activities'], null, $edges);
        $this->assertEquals(['tasks.activities'], $sorted);
    }

    /**
     * Цикл, собранный только из конфиг-рёбер, разрывается, а не уводит сортировку в
     * бесконечность: у таких рёбер нет признака nullable, на котором построен приоритет,
     * поэтому рвётся любое — но рвётся.
     */
    public function testCascadeFromCycleIsBroken(): void
    {
        $this->fkInspector->method('getForeignKeys')->willReturn([]);

        $edges = TableDependencyResolver::cascadeEdges([
            'a.one' => [['parent' => 'a.two', 'fk_column' => 'two_id', 'parent_column' => 'id']],
            'a.two' => [['parent' => 'a.one', 'fk_column' => 'one_id', 'parent_column' => 'id']],
        ]);

        $result = $this->resolver->sort(['a.one', 'a.two'], null, $edges);
        $this->assertCount(2, $result->getSorted());
        $this->assertTrue($result->hasDeferredEdges(), 'разорванное ребро обязано попасть в deferred');
    }

    /**
     * Самоссылка порядок не задаёт и в граф не попадает.
     */
    public function testCascadeEdgesDropsSelfReference(): void
    {
        $edges = TableDependencyResolver::cascadeEdges([
            'a.one' => [['parent' => 'a.one', 'fk_column' => 'parent_id', 'parent_column' => 'id']],
        ]);
        $this->assertSame([], $edges);
    }

    /**
     * Порядок выгрузки НЕ зависит от того, как таблицы расположены в .yaml.
     *
     * Это не побочное свойство, а весь смысл: перекладывать таблицы в конфиге руками
     * никто не должен. Сортировщик на каждом шаге берёт готовые узлы по алфавиту
     * (TopologicalSorter::sort), поэтому результат определяется ГРАФОМ и ничем больше —
     * а граф теперь строится из cascade_from, объявленных в тех же .yaml.
     */
    public function testExportOrderDoesNotDependOnConfigOrder(): void
    {
        $this->fkInspector->method('getForeignKeys')->willReturn([]);

        // Родитель назван так, что алфавит ставит его ПОСЛЕДНИМ из трёх.
        $edges = TableDependencyResolver::cascadeEdges([
            'tasks.activities' => [
                ['parent' => 'tasks.zzz_jobs', 'fk_column' => 'job_id', 'parent_column' => 'id'],
            ],
            'tasks.bbb_notes' => [
                ['parent' => 'tasks.activities', 'fk_column' => 'activity_id', 'parent_column' => 'id'],
            ],
        ]);

        $expected = ['tasks.zzz_jobs', 'tasks.activities', 'tasks.bbb_notes'];

        foreach ([
            'как в конфиге'   => ['tasks.activities', 'tasks.bbb_notes', 'tasks.zzz_jobs'],
            'наоборот'        => ['tasks.zzz_jobs', 'tasks.bbb_notes', 'tasks.activities'],
            'вперемешку'      => ['tasks.bbb_notes', 'tasks.zzz_jobs', 'tasks.activities'],
        ] as $label => $keys) {
            $this->assertEquals(
                $expected,
                $this->resolver->sortForExport($keys, null, $edges),
                'порядок изменился от перестановки входа (' . $label . ') — значит он берётся не из графа'
            );
        }
    }

    public function testSortForExportIgnoresExternalParents(): void
    {
        // orders -> users, but users not in tableKeys
        $this->fkInspector->method('getForeignKeys')->willReturn([
            [
                'constraint_name' => 'fk_orders_user',
                'source_schema' => 'public',
                'source_table' => 'orders',
                'source_column' => 'user_id',
                'target_schema' => 'public',
                'target_table' => 'users',
                'target_column' => 'id',
            ],
        ]);

        $sorted = $this->resolver->sortForExport(['public.orders']);
        $this->assertEquals(['public.orders'], $sorted);
    }

    public function testGetDependencyGraph(): void
    {
        $this->fkInspector->method('getForeignKeys')->willReturn([
            [
                'constraint_name' => 'fk_orders_user',
                'source_schema' => 'public',
                'source_table' => 'orders',
                'source_column' => 'user_id',
                'target_schema' => 'public',
                'target_table' => 'users',
                'target_column' => 'id',
            ],
        ]);

        $graph = $this->resolver->getDependencyGraph();
        $this->assertArrayHasKey('public.orders', $graph);
        $this->assertArrayHasKey('public.users', $graph['public.orders']);
        $this->assertEquals('user_id', $graph['public.orders']['public.users']['source_column']);
    }

    public function testGetCascadeFromCandidates(): void
    {
        $this->fkInspector->method('getForeignKeys')->willReturn([
            [
                'constraint_name' => 'fk_orders_user',
                'source_schema' => 'public',
                'source_table' => 'orders',
                'source_column' => 'user_id',
                'target_schema' => 'public',
                'target_table' => 'users',
                'target_column' => 'id',
            ],
        ]);

        $candidates = $this->resolver->getCascadeFromCandidates('public', 'orders');
        $this->assertCount(1, $candidates);
        $this->assertEquals('public.users', $candidates[0]['parent']);
        $this->assertEquals('user_id', $candidates[0]['fk_column']);
        $this->assertEquals('id', $candidates[0]['parent_column']);
    }

    public function testGetCascadeFromCandidatesEmpty(): void
    {
        $this->fkInspector->method('getForeignKeys')->willReturn([]);
        $candidates = $this->resolver->getCascadeFromCandidates('public', 'users');
        $this->assertEmpty($candidates);
    }

    public function testCaching(): void
    {
        $this->fkInspector->expects($this->once())->method('getForeignKeys')->willReturn([]);
        // Call twice — should only query once
        $this->resolver->getDependencyGraph();
        $this->resolver->getDependencyGraph();
    }

    public function testSortForImport(): void
    {
        $this->fkInspector->method('getForeignKeys')->willReturn([
            [
                'constraint_name' => 'fk_orders_user',
                'source_schema' => 'public',
                'source_table' => 'orders',
                'source_column' => 'user_id',
                'target_schema' => 'public',
                'target_table' => 'users',
                'target_column' => 'id',
            ],
        ]);

        $sorted = $this->resolver->sortForImport(['public.orders', 'public.users']);
        $this->assertEquals(['public.users', 'public.orders'], $sorted);
    }
}
