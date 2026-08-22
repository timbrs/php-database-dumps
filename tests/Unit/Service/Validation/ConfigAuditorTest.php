<?php

namespace Timbrs\DatabaseDumps\Tests\Unit\Service\Validation;

use Timbrs\DatabaseDumps\Service\Validation\AuditResult;
use Timbrs\DatabaseDumps\Service\Validation\ConfigAuditor;
use Timbrs\DatabaseDumps\Service\Validation\Finding;
use Timbrs\DatabaseDumps\Service\Validation\InventoryReader;
use Timbrs\DatabaseDumps\Service\Validation\JsonReportWriter;
use Timbrs\DatabaseDumps\Tests\Support\InMemoryFileSystem;
use Timbrs\DatabaseDumps\Tests\Support\ValidationTestCase;

class ConfigAuditorTest extends ValidationTestCase
{
    /**
     * @param array<int, string> $schemaFilter
     */
    private function audit(array $schemaFilter = []): AuditResult
    {
        $files = $this->splitConfig([
            'pub' => [
                'full_export' => ['clients'],
                'partial_export' => [
                    'orders' => ['limit' => 10, 'order_by' => 'ghost DESC'],
                ],
            ],
            'tasks' => [
                'partial_export' => [
                    'jobs' => ['limit' => 10],
                    'activities' => ['limit' => 10],
                ],
            ],
        ]);
        $files[self::INVENTORY_PATH] = $this->inventoryJson([
            'pub' => [
                'clients' => ['row_count' => 10, 'columns' => ['id' => 'bigint']],
                'orders' => ['row_count' => 10, 'columns' => ['id' => 'bigint']],
                'forgotten' => ['row_count' => 10, 'columns' => ['id' => 'bigint']],
            ],
            'tasks' => ['activities' => ['row_count' => 10, 'columns' => ['id' => 'bigint']]],
        ]);

        $fs = new InMemoryFileSystem($files);

        return (new ConfigAuditor($fs))->audit(
            self::CONFIG_PATH,
            new InventoryReader($fs, self::INVENTORY_PATH),
            $schemaFilter
        );
    }

    public function testCoverageCounts(): void
    {
        $coverage = $this->audit()->getCoverage();

        $this->assertSame(4, $coverage['totals']['inventory']);
        $this->assertSame(4, $coverage['totals']['config']);
        $this->assertSame(3, $coverage['totals']['covered']);
        $this->assertSame(1, $coverage['totals']['uncovered']);
        $this->assertSame(1, $coverage['totals']['unknown'], 'tasks.jobs есть в конфиге, но не в слепке');
        $this->assertSame(1, $coverage['totals']['full_export']);
        $this->assertSame(3, $coverage['totals']['partial']);
        $this->assertSame(2, $coverage['schemas']['pub']['covered']);
    }

    public function testFindingsAreSortedBySeverityThenCode(): void
    {
        $findings = $this->audit()->getFindings();

        $this->assertNotEmpty($findings);
        $this->assertSame('L-1', $findings[0]->getCode(), 'единственная ошибка идёт первой');

        $previous = null;
        foreach ($findings as $finding) {
            if ($previous !== null) {
                $this->assertGreaterThanOrEqual($previous->severityRank(), $finding->severityRank());
            }
            $previous = $finding;
        }
    }

    public function testErrorsDriveTheVerdict(): void
    {
        $result = $this->audit();

        $this->assertTrue($result->hasErrors());
        $this->assertSame(1, $result->countBySeverity(Finding::SEVERITY_ERROR));
        $this->assertSame(['L-1'], $this->codes($result->findingsAtLeast(Finding::SEVERITY_ERROR)));
    }

    public function testSchemaFilterNarrowsTheReport(): void
    {
        $result = $this->audit(['tasks']);

        $this->assertSame(['tasks'], $result->getMeta()['schemas_checked']);
        foreach ($result->getFindings() as $finding) {
            $this->assertSame('tasks', $finding->getSchema());
        }
        $this->assertArrayNotHasKey('pub', $result->getCoverage()['schemas']);
    }

    public function testMissingConfigIsAFindingNotAnException(): void
    {
        $fs = new InMemoryFileSystem([self::INVENTORY_PATH => $this->inventoryJson(['pub' => []])]);
        $result = (new ConfigAuditor($fs))->audit(
            self::CONFIG_PATH,
            new InventoryReader($fs, self::INVENTORY_PATH)
        );

        $this->assertTrue($result->hasErrors());
        $this->assertContains('S-1', $this->codes($result->getFindings()));
    }

    public function testJsonReportShape(): void
    {
        $writer = new JsonReportWriter(new InMemoryFileSystem([]));
        $report = $writer->toArray($this->audit(), '2026-02-02T00:00:00Z');

        $this->assertSame('2026-02-02T00:00:00Z', $report['generated_at']);
        $this->assertSame(self::CONFIG_PATH, $report['config_path']);
        $this->assertSame(self::GENERATED_AT, $report['inventory_generated_at']);
        $this->assertTrue($report['inventory_present']);
        $this->assertSame(1, $report['summary']['error']);
        $this->assertArrayHasKey('L-1', $report['summary']['by_code']);
        $this->assertSame(4, $report['coverage']['totals']['inventory']);
        $this->assertSame('L-1', $report['findings'][0]['code']);
        $this->assertArrayHasKey('suggestion', $report['findings'][0]);

        $decoded = json_decode($writer->toJson($this->audit(), '2026-02-02T00:00:00Z'), true);
        $this->assertIsArray($decoded);
        $this->assertSame($report['summary']['total'], $decoded['summary']['total']);
    }

    public function testReportIsWrittenToFile(): void
    {
        $fs = new InMemoryFileSystem([]);
        (new JsonReportWriter($fs))->write('/proj/database/analysis/.dumpcheck/findings.json', $this->audit());

        $written = $fs->read('/proj/database/analysis/.dumpcheck/findings.json');
        $this->assertIsArray(json_decode($written, true));
    }
}
