<?php

namespace Timbrs\DatabaseDumps\Tests\Unit\Service\Analysis;

use PHPUnit\Framework\TestCase;
use Timbrs\DatabaseDumps\Contract\FileSystemInterface;
use Timbrs\DatabaseDumps\Contract\LoggerInterface;
use Timbrs\DatabaseDumps\Service\Analysis\AnalysisRepairLoop;
use Timbrs\DatabaseDumps\Service\Analysis\CriteriaSqlTester;
use Timbrs\DatabaseDumps\Service\Analysis\OpencodeRunner;

class AnalysisRepairLoopTest extends TestCase
{
    private const PROJECT = '/proj';
    private const DATA_DIR = 'database';
    private const INVENTORY_ABS = '/proj/database/analysis/schema_inventory.users.json';
    private const OUT_ABS = '/proj/database/analysis/out/users.json';

    private function inventoryJson(): string
    {
        return (string) json_encode([
            'schemas' => [
                'users' => [
                    'tables' => [
                        'users' => [
                            'columns' => [
                                ['name' => 'active_flg'],
                                ['name' => 'date_from'],
                                ['name' => 'date_to'],
                                ['name' => 'work_status'],
                            ],
                        ],
                    ],
                ],
            ],
        ]);
    }

    private function outJson(string $where): string
    {
        return (string) json_encode([
            'criteria' => [
                ['table' => 'users.users', 'name' => 'active', 'sql_where' => $where],
            ],
        ]);
    }

    /**
     * @param array<int, string> $outReadsSequence последовательные ответы read() для OUT-файла
     */
    private function fsMock(array $outReadsSequence, bool $outExists = true): FileSystemInterface
    {
        $fs = $this->createMock(FileSystemInterface::class);
        $fs->method('exists')->willReturnCallback(function ($path) use ($outExists) {
            if ($path === self::OUT_ABS) {
                return $outExists;
            }
            return true;
        });
        $i = 0;
        $fs->method('read')->willReturnCallback(function ($path) use (&$i, $outReadsSequence) {
            if ($path === self::INVENTORY_ABS) {
                return $this->inventoryJson();
            }
            if ($path === self::OUT_ABS) {
                $val = $outReadsSequence[$i] ?? end($outReadsSequence);
                $i++;
                return $val;
            }
            return '';
        });
        return $fs;
    }

    /**
     * Мок SQL-тестера: симулирует БД — алиас t1., bind-параметр :name или несуществующая
     * колонка activeFlg → ошибка; иначе null (criterion исполнился).
     */
    private function sqlTester(): CriteriaSqlTester
    {
        $tester = $this->createMock(CriteriaSqlTester::class);
        $tester->method('test')->willReturnCallback(function ($schema, $table, $where) {
            if (strpos((string) $where, 't1.') !== false
                || (bool) preg_match('/(?<![:\w]):[A-Za-z_]/', (string) $where)
                || strpos((string) $where, 'activeFlg') !== false) {
                return 'ERROR: 42P01 bad criterion';
            }
            return null;
        });
        return $tester;
    }

    private function loop(FileSystemInterface $fs, OpencodeRunner $runner, ?LoggerInterface $logger = null, ?CriteriaSqlTester $tester = null): AnalysisRepairLoop
    {
        return new AnalysisRepairLoop(
            $runner,
            $fs,
            $tester ?? $this->sqlTester(),
            $logger ?? $this->createMock(LoggerInterface::class),
            self::PROJECT
        );
    }

    /**
     * @return array<string, string>
     */
    private function schemaFiles(): array
    {
        return ['users' => self::INVENTORY_ABS];
    }

    public function testRepairsInvalidCriteriaThenStops(): void
    {
        // 1-е чтение out — с алиасом/параметром (битое), после перепрогона — валидное.
        $fs = $this->fsMock([
            $this->outJson('t1.activeFlg = :flag'),
            $this->outJson('active_flg = 1'),
        ]);
        $runner = $this->createMock(OpencodeRunner::class);
        $runner->expects($this->once())->method('runAgent')->willReturn(0);

        $this->loop($fs, $runner)->run(self::DATA_DIR, $this->schemaFiles(), 2);
    }

    public function testStopsAfterMaxAttemptsWhenStillInvalid(): void
    {
        // Всегда битое — исчерпываем попытки, ровно maxAttempts перепрогонов.
        $fs = $this->fsMock([$this->outJson('t1.activeFlg = :flag')]);
        $runner = $this->createMock(OpencodeRunner::class);
        $runner->expects($this->exactly(2))->method('runAgent')->willReturn(0);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->atLeastOnce())->method('warning');

        $this->loop($fs, $runner, $logger)->run(self::DATA_DIR, $this->schemaFiles(), 2);
    }

    public function testDoesNothingWhenAllValid(): void
    {
        $fs = $this->fsMock([$this->outJson('active_flg = 1')]);
        $runner = $this->createMock(OpencodeRunner::class);
        $runner->expects($this->never())->method('runAgent');

        $this->loop($fs, $runner)->run(self::DATA_DIR, $this->schemaFiles(), 2);
    }

    public function testDisabledWhenZeroAttempts(): void
    {
        $fs = $this->fsMock([$this->outJson('t1.activeFlg = :flag')]);
        $runner = $this->createMock(OpencodeRunner::class);
        $runner->expects($this->never())->method('runAgent');

        $this->loop($fs, $runner)->run(self::DATA_DIR, $this->schemaFiles(), 0);
    }

    public function testSkipsWhenOutFileMissing(): void
    {
        $fs = $this->fsMock([$this->outJson('active_flg = 1')], false);
        $runner = $this->createMock(OpencodeRunner::class);
        $runner->expects($this->never())->method('runAgent');

        $this->loop($fs, $runner)->run(self::DATA_DIR, $this->schemaFiles(), 2);
    }

    public function testColumnMismatchTriggersRepair(): void
    {
        // Без алиасов/параметров, но camelCase-колонки нет в инвентаре → тоже требует перепрогона.
        $fs = $this->fsMock([
            $this->outJson('activeFlg = 1'),
            $this->outJson('active_flg = 1'),
        ]);
        $runner = $this->createMock(OpencodeRunner::class);
        $runner->expects($this->once())->method('runAgent')->willReturn(0);

        $this->loop($fs, $runner)->run(self::DATA_DIR, $this->schemaFiles(), 2);
    }
}
