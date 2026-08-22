<?php

namespace Timbrs\DatabaseDumps\Tests\Unit\Service\Validation\Rule;

use Timbrs\DatabaseDumps\Service\Validation\Finding;
use Timbrs\DatabaseDumps\Service\Validation\Rule\CoverageRule;
use Timbrs\DatabaseDumps\Tests\Support\ValidationTestCase;

class CoverageRuleTest extends ValidationTestCase
{
    /**
     * @param array<string, array<string, mixed>> $configBySchema
     * @param array<string, array<string, array<string, mixed>>> $inventory
     * @return array<int, Finding>
     */
    private function findings(array $configBySchema, array $inventory): array
    {
        $files = $this->splitConfig($configBySchema);
        $files[self::INVENTORY_PATH] = $this->inventoryJson($inventory);

        return (new CoverageRule())->apply($this->context($files));
    }

    /**
     * @return array<string, mixed>
     */
    private function table(int $rows = 10): array
    {
        return ['row_count' => $rows, 'columns' => ['id' => 'bigint']];
    }

    public function testTableInInventoryButNotInConfig(): void
    {
        $findings = $this->findings(
            ['pub' => ['partial_export' => ['orders' => ['limit' => 10]]]],
            ['pub' => ['orders' => $this->table(), 'forgotten' => $this->table(970958)]]
        );

        $finding = $this->firstWithCode($findings, 'C-1');
        $this->assertNotNull($finding);
        $this->assertSame('pub.forgotten', $finding->getTarget());
        $this->assertSame(Finding::SEVERITY_WARNING, $finding->getSeverity());
        $this->assertStringContainsString('970958', $finding->getMessage());
    }

    /**
     * Случай tasks.jobs: таблица есть в конфиге и в БД, но в слепок не попала —
     * её отфильтровал ServiceTableFilter.
     */
    public function testTableInConfigButNotInInventory(): void
    {
        $findings = $this->findings(
            ['tasks' => ['partial_export' => ['jobs' => ['limit' => 10], 'events' => ['limit' => 10]]]],
            ['tasks' => ['events' => $this->table()]]
        );

        $finding = $this->firstWithCode($findings, 'C-2');
        $this->assertNotNull($finding);
        $this->assertSame('tasks.jobs', $finding->getTarget());
    }

    public function testSchemaMissingFromIncludes(): void
    {
        $findings = $this->findings(
            ['pub' => ['partial_export' => ['orders' => ['limit' => 10]]]],
            ['pub' => ['orders' => $this->table()], 'ag_catalog' => ['ag_graph' => $this->table(0)]]
        );

        $c3 = $this->firstWithCode($findings, 'C-3');
        $this->assertNotNull($c3);
        $this->assertSame('ag_catalog', $c3->getSchema());
        // Про каждую таблицу невыгружаемой схемы говорить отдельно не нужно — кроме C-1.
        $this->assertSame(1, $this->countCode($findings, 'C-1'));
    }

    public function testSchemaInConfigButNotInInventory(): void
    {
        $findings = $this->findings(
            ['gone' => ['partial_export' => ['orders' => ['limit' => 10]]]],
            ['pub' => ['orders' => $this->table()]]
        );

        $codes = $this->codes($findings);
        $this->assertContains('C-3', $codes);
        // Таблицы отсутствующей схемы не разбираются поштучно.
        $this->assertNotContains('C-2', $codes);
    }

    public function testFullCoverageIsSilent(): void
    {
        $findings = $this->findings(
            ['pub' => ['full_export' => ['dict'], 'partial_export' => ['orders' => ['limit' => 10]]]],
            ['pub' => ['orders' => $this->table(), 'dict' => $this->table()]]
        );

        $this->assertSame([], $this->codes($findings));
    }
}
