<?php

namespace Timbrs\DatabaseDumps\Tests\Unit\Service\Ai;

use PHPUnit\Framework\TestCase;
use Timbrs\DatabaseDumps\Config\AiConfig;
use Timbrs\DatabaseDumps\Config\EnvironmentConfig;
use Timbrs\DatabaseDumps\Service\Ai\DbdumpConfigStore;
use Timbrs\DatabaseDumps\Util\FileSystemHelper;

/**
 * Использует реальные временные файлы: load() читает config/database-dumps.php
 * через include, поэтому мок FileSystem не подходит.
 */
class DbdumpConfigStoreTest extends TestCase
{
    /** @var array<string, string|false> */
    private $savedEnv = [];

    private const KEYS = [
        AiConfig::ENV_URL,
        AiConfig::ENV_MODEL,
        AiConfig::ENV_TOKEN,
        AiConfig::ENV_TIMEOUT,
        AiConfig::ENV_ENABLED,
        DbdumpConfigStore::ENV_DATA_DIR,
        DbdumpConfigStore::ENV_OPENCODE_BIN,
    ];

    /** @var string */
    private $projectDir;

    protected function setUp(): void
    {
        foreach (self::KEYS as $key) {
            $this->savedEnv[$key] = getenv($key);
            putenv($key);
            unset($_SERVER[$key], $_ENV[$key]);
        }

        $this->projectDir = sys_get_temp_dir() . '/dbdump_store_' . bin2hex(random_bytes(6));
        mkdir($this->projectDir, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (self::KEYS as $key) {
            $saved = $this->savedEnv[$key] ?? false;
            if ($saved === false) {
                putenv($key);
                unset($_SERVER[$key], $_ENV[$key]);
            } else {
                putenv($key . '=' . $saved);
            }
        }

        $this->removeDir($this->projectDir);
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

    private function store(?EnvironmentConfig $env = null): DbdumpConfigStore
    {
        return new DbdumpConfigStore(
            new FileSystemHelper(),
            $env ?? new EnvironmentConfig('dev')
        );
    }

    public function testPathIsUnderProjectConfig(): void
    {
        $store = $this->store();
        $this->assertSame(
            $this->projectDir . '/config/database-dumps.php',
            $store->path($this->projectDir)
        );
    }

    public function testSaveWritesPhpArrayWithoutToken(): void
    {
        $store = $this->store();
        $store->save($this->projectDir, AiConfig::fromArray([
            'url' => 'https://gpt.example.com/v1',
            'model' => 'm',
            'token' => 'secret',
            'enabled' => true,
        ]));

        $content = file_get_contents($store->path($this->projectDir));
        $this->assertIsString($content);
        $this->assertStringContainsString('<?php', $content);
        $this->assertStringNotContainsString('secret', $content);
        $this->assertStringNotContainsString('token', $content);

        $loaded = $store->load($this->projectDir);
        $this->assertIsArray($loaded);
        $this->assertSame('https://gpt.example.com/v1', $loaded['llm']['url']);
        $this->assertSame('database', $loaded['data_dir']);
    }

    public function testSavePreservesUnknownKeys(): void
    {
        $path = $this->projectDir . '/config/database-dumps.php';
        mkdir(dirname($path), 0777, true);
        file_put_contents($path, "<?php\nreturn ['config_path' => '/proj/dump.yaml', 'llm' => []];\n");

        $store = $this->store();
        $store->save($this->projectDir, AiConfig::fromArray(['url' => 'https://x/v1', 'enabled' => true]));

        $loaded = $store->load($this->projectDir);
        $this->assertIsArray($loaded);
        $this->assertSame('/proj/dump.yaml', $loaded['config_path']);
    }

    public function testLoadReturnsNullWhenMissing(): void
    {
        $this->assertNull($this->store()->load($this->projectDir));
    }

    public function testResolveUsesFileWhenNoEnv(): void
    {
        $store = $this->store();
        $store->save($this->projectDir, AiConfig::fromArray(['url' => 'https://file.example.com/v1', 'enabled' => true]));

        $config = $store->resolve($this->projectDir);
        $this->assertSame('https://file.example.com/v1', $config->getUrl());
        $this->assertTrue($config->isEnabled());
    }

    public function testResolveEnvUrlOverridesFile(): void
    {
        $store = $this->store();
        $store->save($this->projectDir, AiConfig::fromArray(['url' => 'https://file.example.com/v1', 'enabled' => true]));

        putenv(AiConfig::ENV_URL . '=https://env.example.com/v1');

        $config = $store->resolve($this->projectDir);
        $this->assertSame('https://env.example.com/v1', $config->getUrl());
    }

    public function testResolveMergesEnvTokenOverFileUrl(): void
    {
        $store = $this->store();
        $store->save($this->projectDir, AiConfig::fromArray(['url' => 'https://file.example.com/v1', 'enabled' => true]));

        putenv(AiConfig::ENV_TOKEN . '=env-secret');

        $config = $store->resolve($this->projectDir);
        $this->assertSame('https://file.example.com/v1', $config->getUrl());
        $this->assertSame('env-secret', $config->getToken());
    }

    public function testResolveDisabledInProduction(): void
    {
        $store = $this->store(new EnvironmentConfig('prod'));
        $store->save($this->projectDir, AiConfig::fromArray(['url' => 'https://file.example.com/v1', 'enabled' => true]));

        $config = $store->resolve($this->projectDir);
        $this->assertFalse($config->isEnabled());
    }

    public function testGetDataDirEnvOverridesFile(): void
    {
        $store = $this->store();
        $store->save($this->projectDir, AiConfig::fromArray(['url' => '', 'enabled' => false]), 'var/database');
        $this->assertSame('var/database', $store->getDataDir($this->projectDir));

        putenv(DbdumpConfigStore::ENV_DATA_DIR . '=custom/dir');
        $this->assertSame('custom/dir', $store->getDataDir($this->projectDir));
    }

    public function testGetDataDirDefaultsWhenAbsent(): void
    {
        $this->assertSame('database', $this->store()->getDataDir($this->projectDir));
    }

    public function testGetDataDirDefaultsInProduction(): void
    {
        $store = $this->store(new EnvironmentConfig('prod'));
        $store->save($this->projectDir, AiConfig::fromArray(['url' => '', 'enabled' => false]), 'var/database');

        $this->assertSame('database', $store->getDataDir($this->projectDir));
    }

    public function testGetOpencodeBinDefaultsWhenAbsent(): void
    {
        $this->assertSame('opencode', $this->store()->getOpencodeBin($this->projectDir));
    }

    public function testSaveWritesAndReadsOpencodeBin(): void
    {
        $store = $this->store();
        $store->save($this->projectDir, AiConfig::fromArray(['url' => '', 'enabled' => false]), null, 'opencode-cli');

        $this->assertSame('opencode-cli', $store->getOpencodeBin($this->projectDir));

        $loaded = $store->load($this->projectDir);
        $this->assertIsArray($loaded);
        $this->assertSame('opencode-cli', $loaded['opencode']['bin']);
    }

    public function testSaveNullOpencodeBinPreservesExisting(): void
    {
        $store = $this->store();
        $store->save($this->projectDir, AiConfig::fromArray(['url' => '', 'enabled' => false]), null, 'opencode-cli');
        // Повторное сохранение без явного bin (null) не должно затирать ранее записанный.
        $store->save($this->projectDir, AiConfig::fromArray(['url' => 'https://x/v1', 'enabled' => true]));

        $this->assertSame('opencode-cli', $store->getOpencodeBin($this->projectDir));
    }

    public function testGetOpencodeBinEnvOverridesFile(): void
    {
        $store = $this->store();
        $store->save($this->projectDir, AiConfig::fromArray(['url' => '', 'enabled' => false]), null, 'opencode-cli');

        putenv(DbdumpConfigStore::ENV_OPENCODE_BIN . '=my-opencode');
        $this->assertSame('my-opencode', $store->getOpencodeBin($this->projectDir));
    }
}
