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
        $store->method('getDataDir')->willReturn('database');

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

    /**
     * Runner, перехватывающий команду и содержимое файла промпта в $captured (по ссылке).
     * Читает файл промпта ДО того, как runAgent удалит его в finally.
     *
     * @param array{cmd: string, cwd: string, prompt: string|null} $captured
     */
    private function capturingRunner(string $bin, array &$captured): OpencodeRunner
    {
        return new class ($this->createMock(LoggerInterface::class), $this->storeReturning($bin), $bin, $captured) extends OpencodeRunner {
            /** @var string */
            private $binName;
            /** @var array{cmd: string, cwd: string, prompt: string|null} by-ref псевдоним внешнего $captured */
            public $cap;

            /**
             * @param array{cmd: string, cwd: string, prompt: string|null} $captured
             */
            public function __construct(LoggerInterface $logger, DbdumpConfigStore $store, string $bin, array &$captured)
            {
                $this->binName = $bin;
                $this->cap = &$captured;
                parent::__construct($logger, $store, '/proj');
            }

            protected function locate()
            {
                return $this->binName;
            }

            protected function execProcess(string $command, string $cwd): int
            {
                $this->cap['cmd'] = $command;
                $this->cap['cwd'] = $cwd;
                $f = $cwd . '/database/analysis/.opencode-prompt.md';
                $this->cap['prompt'] = is_file($f) ? (string) file_get_contents($f) : null;
                return 0;
            }
        };
    }

    private function tempProject(): string
    {
        $dir = sys_get_temp_dir() . '/dbdump_runner_' . bin2hex(random_bytes(6));
        mkdir($dir . '/database/analysis', 0777, true);
        return $dir;
    }

    private function cleanupProject(string $dir): void
    {
        @unlink($dir . '/database/analysis/.opencode-prompt.md');
        @rmdir($dir . '/database/analysis');
        @rmdir($dir . '/database');
        @rmdir($dir);
    }

    public function testRunAgentPassesPromptViaFileReference(): void
    {
        $tmp = $this->tempProject();
        $captured = ['cmd' => '', 'cwd' => '', 'prompt' => null];
        $runner = $this->capturingRunner('opencode-cli', $captured);

        $code = $runner->runAgent($tmp, 'database/analysis/schema_inventory.public.json', 'Обработай схему public');
        $this->assertSame(0, $code);

        // Команда КОРОТКАЯ: @-ссылка на файл промпта, без инлайн-текста и без -f.
        $cmd = $captured['cmd'];
        $this->assertStringContainsString('run --agent', $cmd);
        $this->assertStringContainsString('@database/analysis/.opencode-prompt.md', $cmd);
        $this->assertStringContainsString('opencode-cli', $cmd);
        $this->assertStringNotContainsString('-f ', $cmd);
        // Сам промпт в командную строку не попал — он в файле.
        $this->assertStringNotContainsString('Обработай схему public', $cmd);
        $this->assertSame($tmp, $captured['cwd']);

        // Полный текст промпта — в файле (инвентарь + задача + директива записи).
        $this->assertNotNull($captured['prompt']);
        $this->assertStringContainsString('schema_inventory.public.json', (string) $captured['prompt']);
        $this->assertStringContainsString('Обработай схему public', (string) $captured['prompt']);
        $this->assertStringContainsString('ОБЯЗАТЕЛЬНО', (string) $captured['prompt']);

        // После запуска файл промпта убран.
        $this->assertFileDoesNotExist($tmp . '/database/analysis/.opencode-prompt.md');

        $this->cleanupProject($tmp);
    }

    public function testLongPromptDoesNotExceedShellArgLimit(): void
    {
        $tmp = $this->tempProject();
        $captured = ['cmd' => '', 'cwd' => '', 'prompt' => null];
        $runner = $this->capturingRunner('opencode-cli', $captured);

        // Промпт > 8192 байт (как корректирующий промпт repair-цикла на «жирной» схеме):
        // раньше падал escapeshellarg на Windows, теперь идёт через файл.
        $long = str_repeat('очень длинный корректирующий промпт с перечнем битых criteria; ', 400);
        $this->assertGreaterThan(8192, strlen($long));

        $code = $runner->runAgent($tmp, 'database/analysis/schema_inventory.products.json', $long);
        $this->assertSame(0, $code);

        // Аргумент командной строки остаётся коротким (не упирается в лимит 8192).
        $this->assertLessThan(8192, strlen($captured['cmd']));
        // Длинный текст ушёл в файл.
        $this->assertStringContainsString('длинный корректирующий', (string) $captured['prompt']);

        $this->cleanupProject($tmp);
    }
}
