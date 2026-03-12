<?php

namespace Timbrs\DatabaseDumps\Tests\Unit\Service\Security;

use PHPUnit\Framework\TestCase;
use Timbrs\DatabaseDumps\Config\EnvironmentConfig;
use Timbrs\DatabaseDumps\Service\Security\EnvironmentChecker;

class EnvironmentCheckerTest extends TestCase
{
    public function testIsProductionReturnsTrueForProd(): void
    {
        $config = new EnvironmentConfig('prod');
        $checker = new EnvironmentChecker($config);

        $this->assertTrue($checker->isProduction());
        $this->assertFalse($checker->isSafeForImport());
    }

    public function testIsProductionReturnsTrueForPredprod(): void
    {
        $config = new EnvironmentConfig('predprod');
        $checker = new EnvironmentChecker($config);

        $this->assertTrue($checker->isProduction());
        $this->assertFalse($checker->isSafeForImport());
    }

    public function testIsProductionReturnsFalseForDev(): void
    {
        $config = new EnvironmentConfig('dev');
        $checker = new EnvironmentChecker($config);

        $this->assertFalse($checker->isProduction());
        $this->assertTrue($checker->isSafeForImport());
    }

    public function testIsProductionReturnsFalseForTest(): void
    {
        $config = new EnvironmentConfig('test');
        $checker = new EnvironmentChecker($config);

        $this->assertFalse($checker->isProduction());
        $this->assertTrue($checker->isSafeForImport());
    }

    public function testGetCurrentEnvironment(): void
    {
        $config = new EnvironmentConfig('dev');
        $checker = new EnvironmentChecker($config);

        $this->assertEquals('dev', $checker->getCurrentEnvironment());
    }
}
