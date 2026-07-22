<?php

namespace Timbrs\DatabaseDumps\Tests\Unit\Service\Analysis;

use PHPUnit\Framework\TestCase;
use Timbrs\DatabaseDumps\Config\DumpConfig;
use Timbrs\DatabaseDumps\Contract\ConfigLoaderInterface;
use Timbrs\DatabaseDumps\Contract\FileSystemInterface;
use Timbrs\DatabaseDumps\Contract\LoggerInterface;
use Timbrs\DatabaseDumps\Service\Ai\DbdumpConfigStore;
use Timbrs\DatabaseDumps\Service\Analysis\AnalysisIngestor;
use Timbrs\DatabaseDumps\Service\Analysis\AnalysisRepairLoop;
use Timbrs\DatabaseDumps\Service\Analysis\ConfigCriteriaRepairer;
use Timbrs\DatabaseDumps\Service\Analysis\ConfigEnricher;
use Timbrs\DatabaseDumps\Service\Analysis\CriteriaSqlTester;
use Timbrs\DatabaseDumps\Service\Analysis\OpencodeRunner;

class ConfigCriteriaRepairerTest extends TestCase
{
    private const CONFIG = '/proj/database/dump_config.yaml';

    /** @var array<string, string> path => content записанного */
    private $written = [];

    /**
     * @param array<string, array<string, mixed>> $partialExport
     */
    private function dumpConfig(array $partialExport): DumpConfig
    {
        return new DumpConfig([], $partialExport);
    }

    /**
     * @param array<int, string> $failingWheres wheres, которые тестер считает падающими
     */
    private function repairer(
        DumpConfig $config,
        array $failingWheres,
        bool $opencodeAvailable,
        AnalysisRepairLoop $repairLoop = null,
        AnalysisIngestor $ingestor = null,
        ConfigEnricher $enricher = null
    ): ConfigCriteriaRepairer {
        $loader = $this->createMock(ConfigLoaderInterface::class);
        $loader->method('load')->willReturn($config);

        $tester = $this->createMock(CriteriaSqlTester::class);
        $tester->method('test')->willReturnCallback(function ($schema, $table, $where) use ($failingWheres) {
            return in_array($where, $failingWheres, true) ? 'ERROR: 42P01 bad' : null;
        });

        $runner = $this->createMock(OpencodeRunner::class);
        $runner->method('isAvailable')->willReturn($opencodeAvailable);

        $fs = $this->createMock(FileSystemInterface::class);
        $fs->method('exists')->willReturn(true);
        $fs->method('write')->willReturnCallback(function ($path, $content) {
            $this->written[$path] = $content;
        });

        $store = $this->createMock(DbdumpConfigStore::class);
        $store->method('getDataDir')->willReturn('database');

        return new ConfigCriteriaRepairer(
            $fs,
            $loader,
            $tester,
            $runner,
            $repairLoop ?? $this->createMock(AnalysisRepairLoop::class),
            $ingestor ?? $this->createMock(AnalysisIngestor::class),
            $enricher ?? $this->createMock(ConfigEnricher::class),
            $this->createMock(LoggerInterface::class),
            '/proj',
            $store
        );
    }

    /**
     * @param array<int, array{name: string, where: string}> $criteria
     * @return array<string, array<string, mixed>>
     */
    private function schemaWithCriteria(array $criteria): array
    {
        return ['users' => ['users' => ['limit' => 500, 'sample' => ['criteria' => $criteria]]]];
    }

    public function testAllValidCriteriaNoRepair(): void
    {
        $config = $this->dumpConfig($this->schemaWithCriteria([
            ['name' => 'active', 'where' => "active_flg = 1"],
        ]));
        $repairLoop = $this->createMock(AnalysisRepairLoop::class);
        $repairLoop->expects($this->never())->method('run');

        $result = $this->repairer($config, [], true, $repairLoop)->repair(self::CONFIG, 2, null);

        $this->assertSame(1, $result['tested']);
        $this->assertSame(0, $result['failing']);
        $this->assertFalse($result['repaired']);
    }

    public function testFailingCriterionTriggersRepairSeedAndEnrich(): void
    {
        $config = $this->dumpConfig($this->schemaWithCriteria([
            ['name' => 'active', 'where' => 't1.active_flg = 1'],
            ['name' => 'ok', 'where' => "active_flg = 1"],
        ]));

        $repairLoop = $this->createMock(AnalysisRepairLoop::class);
        $repairLoop->expects($this->once())->method('run');

        $ingestor = $this->createMock(AnalysisIngestor::class);
        $ingestor->method('ingest')->willReturn(['cascade_from' => [], 'sample_criteria' => [], 'relationships' => [], 'columns' => [], 'files' => []]);

        $enricher = $this->createMock(ConfigEnricher::class);
        $enricher->expects($this->once())->method('enrich')->willReturn(['cascade_added' => 0, 'criteria_added' => 1]);

        $result = $this->repairer($config, ['t1.active_flg = 1'], true, $repairLoop, $ingestor, $enricher)
            ->repair(self::CONFIG, 2, null);

        $this->assertSame(2, $result['tested']);
        $this->assertSame(1, $result['failing']);
        $this->assertTrue($result['repaired']);
        $this->assertSame(1, $result['criteria_added']);

        // Засеян out/<schema>.json падающим criterion.
        $this->assertArrayHasKey('/proj/database/analysis/out/users.json', $this->written);
        $this->assertStringContainsString('t1.active_flg', $this->written['/proj/database/analysis/out/users.json']);
    }

    public function testDryRunReportsButDoesNotRepair(): void
    {
        $config = $this->dumpConfig($this->schemaWithCriteria([
            ['name' => 'active', 'where' => 't1.active_flg = 1'],
        ]));
        $repairLoop = $this->createMock(AnalysisRepairLoop::class);
        $repairLoop->expects($this->never())->method('run');

        // maxAttempts = 0 → только проверка.
        $result = $this->repairer($config, ['t1.active_flg = 1'], true, $repairLoop)->repair(self::CONFIG, 0, null);

        $this->assertSame(1, $result['failing']);
        $this->assertFalse($result['repaired']);
    }

    public function testNoOpencodeReportsOnly(): void
    {
        $config = $this->dumpConfig($this->schemaWithCriteria([
            ['name' => 'active', 'where' => 't1.active_flg = 1'],
        ]));
        $repairLoop = $this->createMock(AnalysisRepairLoop::class);
        $repairLoop->expects($this->never())->method('run');

        $result = $this->repairer($config, ['t1.active_flg = 1'], false, $repairLoop)->repair(self::CONFIG, 2, null);

        $this->assertSame(1, $result['failing']);
        $this->assertFalse($result['repaired']);
    }
}
