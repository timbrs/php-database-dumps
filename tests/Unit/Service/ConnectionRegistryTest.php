<?php

namespace Timbrs\DatabaseDumps\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use Timbrs\DatabaseDumps\Contract\DatabaseConnectionInterface;
use Timbrs\DatabaseDumps\Service\ConnectionRegistry;

class ConnectionRegistryTest extends TestCase
{
    public function testRegisterAndGetConnection(): void
    {
        $connection = $this->createMock(DatabaseConnectionInterface::class);
        $connection->method('getPlatformName')->willReturn('postgresql');

        $registry = new ConnectionRegistry('default');
        $registry->register('default', $connection);

        $this->assertSame($connection, $registry->getConnection());
        $this->assertSame($connection, $registry->getConnection('default'));
    }

    public function testGetDefaultName(): void
    {
        $registry = new ConnectionRegistry('main');
        $this->assertEquals('main', $registry->getDefaultName());
    }

    public function testGetNames(): void
    {
        $pgConnection = $this->createMock(DatabaseConnectionInterface::class);
        $pgConnection->method('getPlatformName')->willReturn('postgresql');

        $mysqlConnection = $this->createMock(DatabaseConnectionInterface::class);
        $mysqlConnection->method('getPlatformName')->willReturn('mysql');

        $registry = new ConnectionRegistry('default');
        $registry->register('default', $pgConnection);
        $registry->register('mysql', $mysqlConnection);

        $names = $registry->getNames();
        $this->assertCount(2, $names);
        $this->assertContains('default', $names);
        $this->assertContains('mysql', $names);
    }

    public function testHas(): void
    {
        $connection = $this->createMock(DatabaseConnectionInterface::class);
        $connection->method('getPlatformName')->willReturn('postgresql');

        $registry = new ConnectionRegistry('default');
        $registry->register('default', $connection);

        $this->assertTrue($registry->has('default'));
        $this->assertFalse($registry->has('nonexistent'));
    }

    public function testGetPlatformAutoDetected(): void
    {
        $pgConnection = $this->createMock(DatabaseConnectionInterface::class);
        $pgConnection->method('getPlatformName')->willReturn('postgresql');

        $mysqlConnection = $this->createMock(DatabaseConnectionInterface::class);
        $mysqlConnection->method('getPlatformName')->willReturn('mysql');

        $registry = new ConnectionRegistry('default');
        $registry->register('default', $pgConnection);
        $registry->register('mysql', $mysqlConnection);

        $pgPlatform = $registry->getPlatform('default');
        $mysqlPlatform = $registry->getPlatform('mysql');

        $this->assertInstanceOf(\Timbrs\DatabaseDumps\Platform\PostgresPlatform::class, $pgPlatform);
        $this->assertInstanceOf(\Timbrs\DatabaseDumps\Platform\MySqlPlatform::class, $mysqlPlatform);
    }

    public function testGetConnectionNullReturnsDefault(): void
    {
        $connection = $this->createMock(DatabaseConnectionInterface::class);
        $connection->method('getPlatformName')->willReturn('postgresql');

        $registry = new ConnectionRegistry('default');
        $registry->register('default', $connection);

        $this->assertSame($connection, $registry->getConnection(null));
    }

    public function testGetConnectionThrowsOnMissing(): void
    {
        $registry = new ConnectionRegistry('default');

        $this->expectException(\InvalidArgumentException::class);
        $registry->getConnection('nonexistent');
    }

    public function testGetPlatformThrowsOnMissing(): void
    {
        $registry = new ConnectionRegistry('default');

        $this->expectException(\InvalidArgumentException::class);
        $registry->getPlatform('nonexistent');
    }
}
