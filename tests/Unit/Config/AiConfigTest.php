<?php

namespace Timbrs\DatabaseDumps\Tests\Unit\Config;

use PHPUnit\Framework\TestCase;
use Timbrs\DatabaseDumps\Config\AiConfig;

class AiConfigTest extends TestCase
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

    public function testDefaultsWhenNoEnv(): void
    {
        $config = AiConfig::fromEnv();
        $this->assertSame('', $config->getUrl());
        $this->assertSame(AiConfig::DEFAULT_MODEL, $config->getModel());
        $this->assertNull($config->getToken());
        $this->assertSame(AiConfig::DEFAULT_TIMEOUT, $config->getTimeout());
        $this->assertFalse($config->isEnabled());
    }

    public function testAutoEnabledWhenUrlSet(): void
    {
        putenv(AiConfig::ENV_URL . '=https://gpt.example.com/v1');
        $config = AiConfig::fromEnv();
        $this->assertSame('https://gpt.example.com/v1', $config->getUrl());
        $this->assertTrue($config->isEnabled());
    }

    public function testTrailingSlashStripped(): void
    {
        $config = new AiConfig('https://gpt.example.com/v1/');
        $this->assertSame('https://gpt.example.com/v1', $config->getUrl());
    }

    public function testExplicitDisableOverridesUrl(): void
    {
        putenv(AiConfig::ENV_URL . '=https://gpt.example.com/v1');
        putenv(AiConfig::ENV_ENABLED . '=false');
        $config = AiConfig::fromEnv();
        $this->assertFalse($config->isEnabled());
    }

    public function testExplicitEnableWithoutUrlStaysDisabled(): void
    {
        putenv(AiConfig::ENV_ENABLED . '=true');
        $config = AiConfig::fromEnv();
        $this->assertFalse($config->isEnabled());
    }

    public function testCustomModelAndTokenAndTimeout(): void
    {
        putenv(AiConfig::ENV_URL . '=https://gpt.example.com/v1');
        putenv(AiConfig::ENV_MODEL . '=custom/model');
        putenv(AiConfig::ENV_TOKEN . '=secret-token');
        putenv(AiConfig::ENV_TIMEOUT . '=42');
        $config = AiConfig::fromEnv();
        $this->assertSame('custom/model', $config->getModel());
        $this->assertSame('secret-token', $config->getToken());
        $this->assertSame(42, $config->getTimeout());
    }

    public function testInvalidTimeoutFallsBackToDefault(): void
    {
        putenv(AiConfig::ENV_URL . '=https://gpt.example.com/v1');
        putenv(AiConfig::ENV_TIMEOUT . '=abc');
        $config = AiConfig::fromEnv();
        $this->assertSame(AiConfig::DEFAULT_TIMEOUT, $config->getTimeout());
    }

    public function testReadsFromServerSuperglobal(): void
    {
        $_SERVER[AiConfig::ENV_URL] = 'https://srv.example.com/v1';
        $config = AiConfig::fromEnv();
        $this->assertSame('https://srv.example.com/v1', $config->getUrl());
        $this->assertTrue($config->isEnabled());
    }

    public function testReadsFromEnvSuperglobal(): void
    {
        $_ENV[AiConfig::ENV_URL] = 'https://env.example.com/v1';
        $config = AiConfig::fromEnv();
        $this->assertSame('https://env.example.com/v1', $config->getUrl());
        $this->assertTrue($config->isEnabled());
    }

    public function testGetenvTakesPrecedenceOverSuperglobals(): void
    {
        putenv(AiConfig::ENV_URL . '=https://from-getenv.example.com/v1');
        $_SERVER[AiConfig::ENV_URL] = 'https://from-server.example.com/v1';
        $_ENV[AiConfig::ENV_URL] = 'https://from-env.example.com/v1';
        $config = AiConfig::fromEnv();
        $this->assertSame('https://from-getenv.example.com/v1', $config->getUrl());
    }

    public function testWhitespaceUrlTrimmedAndDisabled(): void
    {
        // Пробельный URL после trim → пустой → фича выключена.
        $config = new AiConfig('   ');
        $this->assertSame('', $config->getUrl());
        $this->assertFalse($config->isEnabled());
    }

    public function testUrlWhitespaceAndTrailingSlashTrimmed(): void
    {
        $config = new AiConfig('  https://gpt.example.com/v1/  ');
        $this->assertSame('https://gpt.example.com/v1', $config->getUrl());
    }

    public function testZeroAndNegativeTimeoutFallBackToDefault(): void
    {
        $zero = new AiConfig('https://gpt.example.com/v1', 'm', null, 0);
        $this->assertSame(AiConfig::DEFAULT_TIMEOUT, $zero->getTimeout());

        $negative = new AiConfig('https://gpt.example.com/v1', 'm', null, -5);
        $this->assertSame(AiConfig::DEFAULT_TIMEOUT, $negative->getTimeout());
    }

    public function testEmptyModelFallsBackToDefault(): void
    {
        $config = new AiConfig('https://gpt.example.com/v1', '');
        $this->assertSame(AiConfig::DEFAULT_MODEL, $config->getModel());
    }

    public function testEmptyTokenNormalizedToNull(): void
    {
        $config = new AiConfig('https://gpt.example.com/v1', 'm', '');
        $this->assertNull($config->getToken());
    }

    public function testEnabledVariantsParsed(): void
    {
        foreach (['1', 'true', 'YES', 'On'] as $truthy) {
            putenv(AiConfig::ENV_URL . '=https://gpt.example.com/v1');
            putenv(AiConfig::ENV_ENABLED . '=' . $truthy);
            $this->assertTrue(AiConfig::fromEnv()->isEnabled(), "значение '{$truthy}' должно включать");
        }
        foreach (['0', 'false', 'no', 'off', 'garbage'] as $falsy) {
            putenv(AiConfig::ENV_URL . '=https://gpt.example.com/v1');
            putenv(AiConfig::ENV_ENABLED . '=' . $falsy);
            $this->assertFalse(AiConfig::fromEnv()->isEnabled(), "значение '{$falsy}' должно выключать");
        }
    }

    public function testFromArrayAndToArrayRoundTrip(): void
    {
        $config = AiConfig::fromArray([
            'url' => 'https://gpt.example.com/v1/',
            'model' => 'custom/model',
            'token' => 'tok',
            'timeout' => 42,
            'enabled' => true,
        ]);

        $this->assertSame('https://gpt.example.com/v1', $config->getUrl()); // trailing slash убран
        $this->assertSame('custom/model', $config->getModel());
        $this->assertSame('tok', $config->getToken());
        $this->assertSame(42, $config->getTimeout());
        $this->assertTrue($config->isEnabled());

        $arr = $config->toArray();
        $this->assertSame('custom/model', $arr['model']);
        $this->assertSame('tok', $arr['token']);
        $this->assertTrue($arr['enabled']);
    }

    public function testFromArrayDefaultsAndEmptyToken(): void
    {
        $config = AiConfig::fromArray(['url' => 'https://gpt.example.com/v1', 'token' => '']);
        $this->assertSame(AiConfig::DEFAULT_MODEL, $config->getModel());
        $this->assertNull($config->getToken());
        $this->assertSame(AiConfig::DEFAULT_TIMEOUT, $config->getTimeout());
        $this->assertTrue($config->isEnabled());
    }

    public function testFromArrayDisabled(): void
    {
        $config = AiConfig::fromArray(['url' => '', 'enabled' => false]);
        $this->assertFalse($config->isEnabled());
    }
}
