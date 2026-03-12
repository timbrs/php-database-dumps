<?php

namespace Timbrs\DatabaseDumps\Tests\Unit\Config;

use PHPUnit\Framework\TestCase;
use Timbrs\DatabaseDumps\Config\EnvironmentConfig;

class EnvironmentConfigTest extends TestCase
{
    public function testDevelopmentEnvironment(): void
    {
        $config = new EnvironmentConfig('dev');

        $this->assertEquals('dev', $config->getCurrentEnv());
        $this->assertTrue($config->isDevelopment());
        $this->assertFalse($config->isProduction());
        $this->assertFalse($config->isTest());
    }

    public function testProductionEnvironment(): void
    {
        $config = new EnvironmentConfig('prod');

        $this->assertEquals('prod', $config->getCurrentEnv());
        $this->assertTrue($config->isProduction());
        $this->assertFalse($config->isDevelopment());
        $this->assertFalse($config->isTest());
    }

    public function testPredprodEnvironment(): void
    {
        $config = new EnvironmentConfig('predprod');

        $this->assertEquals('predprod', $config->getCurrentEnv());
        $this->assertTrue($config->isProduction());
        $this->assertFalse($config->isDevelopment());
        $this->assertFalse($config->isTest());
    }

    public function testTestEnvironment(): void
    {
        $config = new EnvironmentConfig('test');

        $this->assertEquals('test', $config->getCurrentEnv());
        $this->assertTrue($config->isTest());
        $this->assertFalse($config->isProduction());
        $this->assertFalse($config->isDevelopment());
    }

    public function testFromEnv(): void
    {
        $_ENV['APP_ENV'] = 'test';

        $config = EnvironmentConfig::fromEnv();

        $this->assertEquals('test', $config->getCurrentEnv());
        $this->assertTrue($config->isTest());
    }

    public function testFromEnvUsesExistingValue(): void
    {
        // В контексте PHPUnit тестов APP_ENV уже установлен в 'test' через phpunit.xml
        // Поэтому проверяем что fromEnv() корректно читает существующее значение
        $config = EnvironmentConfig::fromEnv();

        $this->assertNotEmpty($config->getCurrentEnv());
        $this->assertContains($config->getCurrentEnv(), ['dev', 'test', 'prod', 'predprod']);
    }
}
