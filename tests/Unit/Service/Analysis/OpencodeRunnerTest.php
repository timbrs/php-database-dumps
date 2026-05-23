<?php

namespace Timbrs\DatabaseDumps\Tests\Unit\Service\Analysis;

use PHPUnit\Framework\TestCase;
use Timbrs\DatabaseDumps\Contract\LoggerInterface;
use Timbrs\DatabaseDumps\Service\Analysis\OpencodeRunner;

class OpencodeRunnerTest extends TestCase
{
    public function testManualCommandHintShapesOpencodeRun(): void
    {
        $runner = new OpencodeRunner($this->createMock(LoggerInterface::class));
        $hint = $runner->manualCommandHint('database/analysis/schema_inventory.public.json');

        $this->assertStringContainsString('opencode run --agent dbdump-mapper', $hint);
        $this->assertStringContainsString('-f database/analysis/schema_inventory.public.json', $hint);
    }

    public function testIsAvailableReflectsLocate(): void
    {
        $found = new class ($this->createMock(LoggerInterface::class)) extends OpencodeRunner {
            protected function locate()
            {
                return '/usr/bin/opencode';
            }
        };
        $this->assertTrue($found->isAvailable());

        $missing = new class ($this->createMock(LoggerInterface::class)) extends OpencodeRunner {
            protected function locate()
            {
                return null;
            }
        };
        $this->assertFalse($missing->isAvailable());
    }

    public function testRunAgentThrowsWhenOpencodeMissing(): void
    {
        $runner = new class ($this->createMock(LoggerInterface::class)) extends OpencodeRunner {
            protected function locate()
            {
                return null;
            }
        };

        $this->expectException(\RuntimeException::class);
        $runner->runAgent('/proj', 'database/analysis/schema_inventory.public.json', 'do it');
    }

    public function testRunAgentBuildsCommandAndExecutes(): void
    {
        /** @var array<int, array{cmd: string, cwd: string}> $calls */
        $calls = [];
        $runner = new class ($this->createMock(LoggerInterface::class), $calls) extends OpencodeRunner {
            /** @var array<int, array{cmd: string, cwd: string}> */
            public $captured;

            /**
             * @param array<int, array{cmd: string, cwd: string}> $calls
             */
            public function __construct(LoggerInterface $logger, array &$calls)
            {
                parent::__construct($logger);
                $this->captured = &$calls;
            }

            protected function locate()
            {
                return 'opencode';
            }

            protected function execProcess(string $command, string $cwd): int
            {
                $this->captured[] = ['cmd' => $command, 'cwd' => $cwd];
                return 0;
            }
        };

        $code = $runner->runAgent('/proj', 'database/analysis/schema_inventory.public.json', 'Обработай схему public');
        $this->assertSame(0, $code);
        $this->assertCount(1, $runner->captured);

        $cmd = $runner->captured[0]['cmd'];
        $this->assertStringContainsString('run --agent', $cmd);
        $this->assertStringContainsString('--dangerously-skip-permissions', $cmd);
        $this->assertStringContainsString('schema_inventory.public.json', $cmd);
        $this->assertSame('/proj', $runner->captured[0]['cwd']);
    }
}
