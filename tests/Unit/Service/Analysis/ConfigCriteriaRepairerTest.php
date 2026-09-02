<?php

namespace Timbrs\DatabaseDumps\Tests\Unit\Service\Analysis;

use PHPUnit\Framework\TestCase;
use Timbrs\DatabaseDumps\Config\DumpConfig;
use Timbrs\DatabaseDumps\Contract\ConfigLoaderInterface;
use Timbrs\DatabaseDumps\Contract\FileSystemInterface;
use Timbrs\DatabaseDumps\Contract\LoggerInterface;
use Timbrs\DatabaseDumps\Service\Ai\DbdumpConfigStore;
use Timbrs\DatabaseDumps\Service\Analysis\ConfigCriteriaRepairer;
use Timbrs\DatabaseDumps\Service\Analysis\CriteriaSqlTester;

class ConfigCriteriaRepairerTest extends TestCase
{
    private const CONFIG = '/proj/docker/database/dump_config.yaml';
    private const REPORT = '/proj/docker/database/analysis/failing-criteria.json';

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
    private function repairer(DumpConfig $config, array $failingWheres): ConfigCriteriaRepairer
    {
        $loader = $this->createMock(ConfigLoaderInterface::class);
        $loader->method('load')->willReturn($config);

        $tester = $this->createMock(CriteriaSqlTester::class);
        $tester->method('test')->willReturnCallback(function ($schema, $table, $where) use ($failingWheres) {
            return in_array($where, $failingWheres, true) ? 'ERROR: 42P01 bad' : null;
        });

        $fs = $this->createMock(FileSystemInterface::class);
        $fs->method('exists')->willReturn(true);
        $fs->method('write')->willReturnCallback(function ($path, $content) {
            $this->written[$path] = $content;
        });

        $store = $this->createMock(DbdumpConfigStore::class);
        $store->method('getDataDir')->willReturn('docker/database');

        return new ConfigCriteriaRepairer(
            $fs,
            $loader,
            $tester,
            $this->createMock(LoggerInterface::class),
            '/proj',
            $store
        );
    }

    /**
     * Тип нарочно широкий: часть тестов подаёт неполные criteria (без name или без where) —
     * это и есть проверяемый вход.
     *
     * @param array<int, array<string, string>> $criteria
     * @return array<string, array<string, mixed>>
     */
    private function schemaWithCriteria(array $criteria): array
    {
        return ['users' => ['users' => ['limit' => 500, 'sample' => ['criteria' => $criteria]]]];
    }

    public function testAllCriteriaValidWritesNoReport(): void
    {
        $config = $this->dumpConfig($this->schemaWithCriteria([
            ['name' => 'active', 'where' => 'active_flg = 1'],
            ['name' => 'recent', 'where' => 'created_at > now() - interval \'1 year\''],
        ]));

        $result = $this->repairer($config, [])->inspect(self::CONFIG, null);

        $this->assertSame(2, $result['tested']);
        $this->assertSame(0, $result['failing']);
        $this->assertSame(0, $result['schemas']);
        $this->assertNull($result['report']);
        $this->assertSame([], $this->written, 'отчёта быть не должно');
    }

    public function testFailingCriteriaCounted(): void
    {
        $config = $this->dumpConfig($this->schemaWithCriteria([
            ['name' => 'active', 'where' => 'active_flg = 1'],
            ['name' => 'broken', 'where' => 't1.nope = 1'],
        ]));

        $result = $this->repairer($config, ['t1.nope = 1'])->inspect(self::CONFIG, null);

        $this->assertSame(2, $result['tested']);
        $this->assertSame(1, $result['failing']);
        $this->assertSame(1, $result['schemas']);
        $this->assertSame(self::REPORT, $result['report']);
    }

    /**
     * Текст ошибки СУБД — то, ради чего отчёт и существует: по нему видно, чего именно нет.
     */
    public function testReportCarriesDbErrorAndCriterion(): void
    {
        $config = $this->dumpConfig($this->schemaWithCriteria([
            ['name' => 'broken', 'where' => 't1.nope = 1'],
        ]));

        $this->repairer($config, ['t1.nope = 1'])->inspect(self::CONFIG, null);

        $this->assertArrayHasKey(self::REPORT, $this->written);
        $payload = json_decode($this->written[self::REPORT], true);

        $this->assertSame(1, $payload['tested']);
        $this->assertSame(1, $payload['failing']);
        $this->assertSame(self::CONFIG, $payload['config_path']);

        $entry = $payload['schemas']['users'][0];
        $this->assertSame('users.users', $entry['table']);
        $this->assertSame('broken', $entry['name']);
        $this->assertSame('t1.nope = 1', $entry['sql_where']);
        $this->assertStringContainsString('42P01', $entry['error']);
    }

    public function testReportGroupsBySchema(): void
    {
        $config = $this->dumpConfig([
            'users' => ['users' => ['sample' => ['criteria' => [['name' => 'a', 'where' => 'bad1']]]]],
            'tasks' => ['tasks' => ['sample' => ['criteria' => [['name' => 'b', 'where' => 'bad2']]]]],
        ]);

        $result = $this->repairer($config, ['bad1', 'bad2'])->inspect(self::CONFIG, null);

        $this->assertSame(2, $result['failing']);
        $this->assertSame(2, $result['schemas']);

        $payload = json_decode($this->written[self::REPORT], true);
        $this->assertSame(['users', 'tasks'], array_keys($payload['schemas']));
    }

    public function testCriteriaWithoutWhereOrNameAreSkipped(): void
    {
        $config = $this->dumpConfig($this->schemaWithCriteria([
            ['name' => 'ok', 'where' => 'active_flg = 1'],
            ['name' => 'no-where'],
            ['where' => 'no-name = 1'],
        ]));

        $result = $this->repairer($config, [])->inspect(self::CONFIG, null);

        $this->assertSame(1, $result['tested'], 'неполные criteria не считаются проверенными');
    }

    public function testTableWithoutSampleIsSkipped(): void
    {
        $config = $this->dumpConfig([
            'users' => ['users' => ['limit' => 500]],
        ]);

        $result = $this->repairer($config, [])->inspect(self::CONFIG, null);

        $this->assertSame(0, $result['tested']);
        $this->assertSame(0, $result['failing']);
    }
}
