<?php

namespace Timbrs\DatabaseDumps\Tests\Unit\Service\Analysis;

use PHPUnit\Framework\TestCase;
use Timbrs\DatabaseDumps\Contract\LoggerInterface;
use Timbrs\DatabaseDumps\Service\Ai\DbdumpConfigStore;
use Timbrs\DatabaseDumps\Service\Analysis\OpencodeRunner;

class OpencodeRunnerTest extends TestCase
{
    /** @var string|false */
    private $savedModelEnv;

    protected function setUp(): void
    {
        // Детерминизм: ручной override модели не должен просачиваться из окружения CI.
        $this->savedModelEnv = getenv(OpencodeRunner::ENV_MODEL);
        putenv(OpencodeRunner::ENV_MODEL);
        unset($_SERVER[OpencodeRunner::ENV_MODEL], $_ENV[OpencodeRunner::ENV_MODEL]);
    }

    protected function tearDown(): void
    {
        if ($this->savedModelEnv === false) {
            putenv(OpencodeRunner::ENV_MODEL);
        } else {
            putenv(OpencodeRunner::ENV_MODEL . '=' . $this->savedModelEnv);
        }
    }

    /**
     * Мок хранилища настроек, отдающий заданное имя бинаря opencode.
     */
    private function storeReturning(string $bin): DbdumpConfigStore
    {
        $store = $this->createMock(DbdumpConfigStore::class);
        $store->method('getOpencodeBin')->willReturn($bin);

        return $store;
    }

    private function runner(string $bin = 'opencode'): OpencodeRunner
    {
        return new OpencodeRunner($this->createMock(LoggerInterface::class), $this->storeReturning($bin), '/proj');
    }

    /**
     * Runner с подменённым чтением opencode.json (readConfigFile → заданный JSON/false).
     */
    private function runnerWithConfig(string $bin, ?string $configJson): OpencodeRunner
    {
        return new class ($this->createMock(LoggerInterface::class), $this->storeReturning($bin), $configJson) extends OpencodeRunner {
            /** @var string|null */
            private $cfg;

            public function __construct(LoggerInterface $logger, DbdumpConfigStore $store, ?string $cfg)
            {
                // Присваиваем ДО parent::__construct — конструктор родителя читает конфиг при резолве модели.
                $this->cfg = $cfg;
                parent::__construct($logger, $store, '/proj');
            }

            protected function readConfigFile(string $path)
            {
                return $this->cfg === null ? false : $this->cfg;
            }
        };
    }

    public function testManualCommandHintShapesOpencodeRun(): void
    {
        $hint = $this->runner()->manualCommandHint('database/analysis/schema_inventory.public.json');

        $this->assertStringContainsString('opencode run --agent dbdump-mapper', $hint);
        // Путь к файлу вписан в текст промпта (не через вариадический -f, который съедал бы промпт).
        $this->assertStringContainsString('database/analysis/schema_inventory.public.json', $hint);
        $this->assertStringNotContainsString('-f ', $hint);
        // У sst/opencode нет флага авто-аппрува прав (--auto/--dangerously-skip-permissions).
        $this->assertStringNotContainsString('--auto', $hint);
        $this->assertStringNotContainsString('--dangerously', $hint);
    }

    public function testManualCommandHintUsesConfiguredBinary(): void
    {
        $hint = $this->runner('opencode-cli')->manualCommandHint('database/analysis/schema_inventory.public.json');

        $this->assertStringStartsWith('opencode-cli run --agent dbdump-mapper', $hint);
    }

    public function testEmptyBinaryFallsBackToDefault(): void
    {
        $hint = $this->runner('   ')->manualCommandHint('x.json');

        $this->assertStringStartsWith('opencode run', $hint);
    }

    public function testModelFromOpencodeConfigAddedWholeToCommand(): void
    {
        // Модель берётся ЦЕЛИКОМ (с провайдером и внутренним "/"), не урезается.
        $cfg = '{"model": "uralsib/openai/gpt-oss-120b", "provider": {}}';
        $hint = $this->runnerWithConfig('opencode-cli', $cfg)->manualCommandHint('x.json');

        $this->assertStringContainsString('-m uralsib/openai/gpt-oss-120b', $hint);
    }

    public function testNoModelFlagWhenConfigAbsent(): void
    {
        $hint = $this->runnerWithConfig('opencode', null)->manualCommandHint('x.json');

        $this->assertStringNotContainsString('-m ', $hint);
    }

    public function testEnvOverridesOpencodeConfigModel(): void
    {
        putenv(OpencodeRunner::ENV_MODEL . '=uralsib/Qwen/Qwen3-Coder-Next-FP8');
        // Даже при наличии конфига env-override имеет приоритет.
        $cfg = '{"model": "uralsib/openai/gpt-oss-120b"}';
        $hint = $this->runnerWithConfig('opencode', $cfg)->manualCommandHint('x.json');

        $this->assertStringContainsString('-m uralsib/Qwen/Qwen3-Coder-Next-FP8', $hint);
    }

    public function testIsAvailableReflectsLocate(): void
    {
        $found = new class ($this->createMock(LoggerInterface::class), $this->storeReturning('opencode')) extends OpencodeRunner {
            public function __construct(LoggerInterface $logger, DbdumpConfigStore $store)
            {
                parent::__construct($logger, $store, '/proj');
            }

            protected function locate()
            {
                return '/usr/bin/opencode';
            }
        };
        $this->assertTrue($found->isAvailable());

        $missing = new class ($this->createMock(LoggerInterface::class), $this->storeReturning('opencode')) extends OpencodeRunner {
            public function __construct(LoggerInterface $logger, DbdumpConfigStore $store)
            {
                parent::__construct($logger, $store, '/proj');
            }

            protected function locate()
            {
                return null;
            }
        };
        $this->assertFalse($missing->isAvailable());
    }

    public function testRunAgentThrowsWhenOpencodeMissing(): void
    {
        $runner = new class ($this->createMock(LoggerInterface::class), $this->storeReturning('opencode')) extends OpencodeRunner {
            public function __construct(LoggerInterface $logger, DbdumpConfigStore $store)
            {
                parent::__construct($logger, $store, '/proj');
            }

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
        $runner = new class ($this->createMock(LoggerInterface::class), $this->storeReturning('opencode-cli'), $calls) extends OpencodeRunner {
            /** @var array<int, array{cmd: string, cwd: string}> */
            public $captured;

            /**
             * @param array<int, array{cmd: string, cwd: string}> $calls
             */
            public function __construct(LoggerInterface $logger, DbdumpConfigStore $store, array &$calls)
            {
                parent::__construct($logger, $store, '/proj');
                $this->captured = &$calls;
            }

            protected function locate()
            {
                return 'opencode-cli';
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
        // Файл инвентаря вписан в сообщение (агент читает сам), НЕ через -f; флага прав нет.
        $this->assertStringContainsString('schema_inventory.public.json', $cmd);
        $this->assertStringNotContainsString('-f ', $cmd);
        $this->assertStringNotContainsString('--auto', $cmd);
        $this->assertStringContainsString('Обработай схему public', $cmd);
        // Имя бинаря взято из хранилища настроек (opencode-cli).
        $this->assertStringContainsString('opencode-cli', $cmd);
        $this->assertSame('/proj', $runner->captured[0]['cwd']);
    }
}
