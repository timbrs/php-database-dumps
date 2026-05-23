<?php

namespace Timbrs\DatabaseDumps\Tests\Unit\Service\Security;

use PHPUnit\Framework\TestCase;
use Timbrs\DatabaseDumps\Config\EnvironmentConfig;
use Timbrs\DatabaseDumps\Exception\ProductionEnvironmentException;
use Timbrs\DatabaseDumps\Service\Security\ProductionGuard;

class ProductionGuardTest extends TestCase
{
    public function testEnsureSafeForImportThrowsInProduction(): void
    {
        $guard = new ProductionGuard(new EnvironmentConfig('prod'));

        $this->expectException(ProductionEnvironmentException::class);
        $guard->ensureSafeForImport();
    }

    public function testEnsureSafeForImportThrowsInProductionUppercase(): void
    {
        // Нормализация: 'PROD' должно интерпретироваться как production
        $guard = new ProductionGuard(new EnvironmentConfig('PROD'));

        $this->expectException(ProductionEnvironmentException::class);
        $guard->ensureSafeForImport();
    }

    public function testEnsureSafeForImportThrowsForProductionLowercase(): void
    {
        $guard = new ProductionGuard(new EnvironmentConfig('production'));

        $this->expectException(ProductionEnvironmentException::class);
        $guard->ensureSafeForImport();
    }

    public function testEnsureSafeForImportThrowsForWhitespacedEnv(): void
    {
        $guard = new ProductionGuard(new EnvironmentConfig(' prod '));

        $this->expectException(ProductionEnvironmentException::class);
        $guard->ensureSafeForImport();
    }

    public function testEnsureSafeForImportDoesNotThrowInDev(): void
    {
        $guard = new ProductionGuard(new EnvironmentConfig('dev'));
        $guard->ensureSafeForImport();
        $this->assertFalse($guard->isProduction());
    }

    public function testEnsureSafeForImportDoesNotThrowInTest(): void
    {
        $guard = new ProductionGuard(new EnvironmentConfig('test'));
        $guard->ensureSafeForImport();
        $this->assertFalse($guard->isProduction());
    }

    public function testEnsureSafeForExportBlocksProdByDefault(): void
    {
        $guard = new ProductionGuard(new EnvironmentConfig('prod'));

        $this->expectException(ProductionEnvironmentException::class);
        $guard->ensureSafeForExport();
    }

    public function testEnsureSafeForExportAllowsProdWithFlag(): void
    {
        $guard = new ProductionGuard(new EnvironmentConfig('prod'));
        $guard->ensureSafeForExport(true);
        $this->assertTrue($guard->isProduction());
    }

    public function testGetCurrentEnvironmentReturnsNormalized(): void
    {
        $guard = new ProductionGuard(new EnvironmentConfig('PROD'));
        $this->assertSame('prod', $guard->getCurrentEnvironment());
    }
}
