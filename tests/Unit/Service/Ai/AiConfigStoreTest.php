<?php

namespace Timbrs\DatabaseDumps\Tests\Unit\Service\Ai;

use PHPUnit\Framework\TestCase;
use Timbrs\DatabaseDumps\Config\AiConfig;
use Timbrs\DatabaseDumps\Contract\FileSystemInterface;
use Timbrs\DatabaseDumps\Service\Ai\AiConfigStore;

class AiConfigStoreTest extends TestCase
{
    /** @var array<string, string|false> */
    private $savedEnv = [];

    private const KEYS = [
        AiConfig::ENV_URL,
        AiConfig::ENV_MODEL,
        AiConfig::ENV_TOKEN,
        AiConfig::ENV_TIMEOUT,
        AiConfig::ENV_ENABLED,
    ];

    /** @var array<string, string> path => content */
    private $files = [];

    protected function setUp(): void
    {
        foreach (self::KEYS as $key) {
            $this->savedEnv[$key] = getenv($key);
            putenv($key);
            unset($_SERVER[$key], $_ENV[$key]);
        }
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
    }

    private function fs(): FileSystemInterface
    {
        $fs = $this->createMock(FileSystemInterface::class);
        $fs->method('exists')->willReturnCallback(function ($path) {
            return isset($this->files[$path]);
        });
        $fs->method('read')->willReturnCallback(function ($path) {
            return $this->files[$path] ?? '';
        });
        $fs->method('write')->willReturnCallback(function ($path, $content): void {
            $this->files[$path] = $content;
        });
        $fs->method('createDirectory');
        return $fs;
    }

    public function testPathIsUnderProjectDatabase(): void
    {
        $store = new AiConfigStore($this->fs());
        $this->assertSame('/proj/database/dbdump_llm.json', $store->path('/proj'));
    }

    public function testSaveThenLoadRoundTrip(): void
    {
        $store = new AiConfigStore($this->fs());
        $config = AiConfig::fromArray([
            'url' => 'https://gpt.example.com/v1',
            'model' => 'm',
            'token' => 'secret',
            'enabled' => true,
        ]);
        $store->save('/proj', $config);

        $loaded = $store->load('/proj');
        $this->assertIsArray($loaded);
        $this->assertSame('https://gpt.example.com/v1', $loaded['url']);
        $this->assertSame('secret', $loaded['token']);
        $this->assertTrue($loaded['enabled']);
    }

    public function testLoadReturnsNullWhenMissing(): void
    {
        $store = new AiConfigStore($this->fs());
        $this->assertNull($store->load('/proj'));
    }

    public function testResolveUsesFileWhenNoEnv(): void
    {
        $store = new AiConfigStore($this->fs());
        $store->save('/proj', AiConfig::fromArray(['url' => 'https://file.example.com/v1', 'enabled' => true]));

        $config = $store->resolve('/proj');
        $this->assertSame('https://file.example.com/v1', $config->getUrl());
        $this->assertTrue($config->isEnabled());
    }

    public function testResolveEnvOverridesFile(): void
    {
        $store = new AiConfigStore($this->fs());
        $store->save('/proj', AiConfig::fromArray(['url' => 'https://file.example.com/v1', 'enabled' => true]));

        putenv(AiConfig::ENV_URL . '=https://env.example.com/v1');

        $config = $store->resolve('/proj');
        $this->assertSame('https://env.example.com/v1', $config->getUrl());
    }

    public function testResolveDisabledWhenNoEnvNoFile(): void
    {
        $store = new AiConfigStore($this->fs());
        $config = $store->resolve('/proj');
        $this->assertFalse($config->isEnabled());
        $this->assertSame('', $config->getUrl());
    }

    public function testSavedDisabledConfigResolvesDisabled(): void
    {
        $store = new AiConfigStore($this->fs());
        $store->save('/proj', AiConfig::fromArray(['url' => '', 'enabled' => false]));

        $config = $store->resolve('/proj');
        $this->assertFalse($config->isEnabled());
    }
}
