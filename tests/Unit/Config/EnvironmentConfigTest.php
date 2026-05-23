<?php

namespace Timbrs\DatabaseDumps\Tests\Unit\Config;

use PHPUnit\Framework\TestCase;
use Timbrs\DatabaseDumps\Config\EnvironmentConfig;

class EnvironmentConfigTest extends TestCase
{
    public function testDevelopmentEnvironment(): void
    {
        $config = new EnvironmentConfig('dev');
        $this->assertSame('dev', $config->getCurrentEnv());
        $this->assertFalse($config->isProduction());
    }

    public function testProductionEnvironment(): void
    {
        $config = new EnvironmentConfig('prod');
        $this->assertSame('prod', $config->getCurrentEnv());
        $this->assertTrue($config->isProduction());
    }

    public function testProductionEnvironmentLaravelStyle(): void
    {
        $config = new EnvironmentConfig('production');
        $this->assertTrue($config->isProduction());
    }

    public function testPredprodEnvironment(): void
    {
        $config = new EnvironmentConfig('predprod');
        $this->assertTrue($config->isProduction());
    }

    public function testNormalizationCaseInsensitive(): void
    {
        $config = new EnvironmentConfig('PROD');
        $this->assertTrue($config->isProduction());
        $this->assertSame('prod', $config->getCurrentEnv());
    }

    public function testNormalizationTrimsWhitespace(): void
    {
        $config = new EnvironmentConfig(' prod ');
        $this->assertTrue($config->isProduction());
        $this->assertSame('prod', $config->getCurrentEnv());
    }

    public function testTestEnvironment(): void
    {
        $config = new EnvironmentConfig('test');
        $this->assertSame('test', $config->getCurrentEnv());
        $this->assertFalse($config->isProduction());
    }

    public function testCustomProductionEnvs(): void
    {
        $config = new EnvironmentConfig('myprod', ['myprod']);
        $this->assertTrue($config->isProduction());
    }

    public function testFromEnv(): void
    {
        $original = getenv('APP_ENV');

        putenv('APP_ENV=test');
        $_ENV['APP_ENV'] = 'test';
        $_SERVER['APP_ENV'] = 'test';

        $config = EnvironmentConfig::fromEnv();
        $this->assertSame('test', $config->getCurrentEnv());
        $this->assertFalse($config->isProduction());

        if ($original === false) {
            putenv('APP_ENV');
        } else {
            putenv('APP_ENV=' . $original);
        }
    }

    public function testFromEnvFailsClosedWhenMissing(): void
    {
        $original = getenv('APP_ENV');
        $origServer = $_SERVER['APP_ENV'] ?? null;
        $origEnv = $_ENV['APP_ENV'] ?? null;

        putenv('APP_ENV');
        unset($_ENV['APP_ENV']);
        unset($_SERVER['APP_ENV']);

        $config = EnvironmentConfig::fromEnv();
        // Fail-closed: при отсутствии APP_ENV считаем prod
        $this->assertTrue($config->isProduction());

        if ($original !== false) {
            putenv('APP_ENV=' . $original);
        }
        if ($origServer !== null) {
            $_SERVER['APP_ENV'] = $origServer;
        }
        if ($origEnv !== null) {
            $_ENV['APP_ENV'] = $origEnv;
        }
    }
}
