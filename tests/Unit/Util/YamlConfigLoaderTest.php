<?php

namespace Timbrs\DatabaseDumps\Tests\Unit\Util;

use PHPUnit\Framework\TestCase;
use Timbrs\DatabaseDumps\Contract\LoggerInterface;
use Timbrs\DatabaseDumps\Exception\ConfigNotFoundException;
use Timbrs\DatabaseDumps\Util\YamlConfigLoader;

class YamlConfigLoaderTest extends TestCase
{
    /** @var string */
    private $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/dbdump_yaml_' . bin2hex(random_bytes(6));
        mkdir($this->dir . '/dump-settings', 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->dir);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        foreach ($items === false ? [] : $items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->removeDir($path) : @unlink($path);
        }
        @rmdir($dir);
    }

    private function writeMainWithIncludes(): string
    {
        // Один include есть (public), второй отсутствует (gone).
        file_put_contents(
            $this->dir . '/dump-settings/public.yaml',
            "partial_export:\n  orders:\n    limit: 100\n"
        );
        $main = $this->dir . '/dump_config.yaml';
        file_put_contents(
            $main,
            "includes:\n  public: ./dump-settings/public.yaml\n  gone: ./dump-settings/gone.yaml\n"
        );
        return $main;
    }

    public function testMissingIncludeIsSkippedWithWarningNotThrow(): void
    {
        $main = $this->writeMainWithIncludes();

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('warning')
            ->with($this->stringContains('gone.yaml'));

        $config = (new YamlConfigLoader($logger))->load($main);

        // Присутствующий include подхватился, отсутствующий — пропущен без исключения.
        $schemas = $config->getAllPartialExportSchemas();
        $this->assertContains('public', $schemas);
        $this->assertNotContains('gone', $schemas);
    }

    public function testMissingIncludeWithoutLoggerTriggersWarning(): void
    {
        $main = $this->writeMainWithIncludes();

        $captured = null;
        set_error_handler(function ($errno, $errstr) use (&$captured) {
            $captured = $errstr;
            return true;
        }, E_USER_WARNING);
        try {
            $config = (new YamlConfigLoader())->load($main);
        } finally {
            restore_error_handler();
        }

        $this->assertNotNull($captured);
        $this->assertStringContainsString('gone.yaml', (string) $captured);
        $this->assertContains('public', $config->getAllPartialExportSchemas());
    }

    public function testMissingMainConfigStillThrows(): void
    {
        // Отсутствие ОСНОВНОГО конфига — по-прежнему жёсткая ошибка (не тихий пропуск).
        $this->expectException(ConfigNotFoundException::class);
        (new YamlConfigLoader())->load($this->dir . '/nope.yaml');
    }

    public function testPresentIncludeMergedNormally(): void
    {
        // Конфиг только с существующим include — без пропусков и без warning.
        file_put_contents(
            $this->dir . '/dump-settings/public.yaml',
            "partial_export:\n  orders:\n    limit: 100\n"
        );
        $main = $this->dir . '/dump_config.yaml';
        file_put_contents($main, "includes:\n  public: ./dump-settings/public.yaml\n");

        $config = (new YamlConfigLoader())->load($main);

        $tables = $config->getPartialExportTables('public');
        $this->assertArrayHasKey('orders', $tables);
    }
}
