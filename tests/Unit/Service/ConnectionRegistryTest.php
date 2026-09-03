<?php

namespace Timbrs\DatabaseDumps\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use Timbrs\DatabaseDumps\Contract\DatabaseConnectionInterface;
use Timbrs\DatabaseDumps\Contract\LoggerInterface;
use Timbrs\DatabaseDumps\Service\ConnectionRegistry;
use Timbrs\DatabaseDumps\Service\Db\CountingConnection;
use Timbrs\DatabaseDumps\Service\Db\SafeQueryPolicy;

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

    public function testPolicyAppliesSessionStatementsLazilyAndOnce(): void
    {
        $connection = $this->createMock(DatabaseConnectionInterface::class);
        $connection->method('getPlatformName')->willReturn('postgresql');
        $applied = [];
        $connection->method('executeStatement')->willReturnCallback(function ($sql) use (&$applied) {
            $applied[] = $sql;
        });

        $registry = new ConnectionRegistry('default', SafeQueryPolicy::defaults());
        $registry->register('default', $connection);
        $this->assertSame([], $applied, 'регистрация не открывает БД: `list` не должен ходить в базу');

        $wrapped = $registry->getConnection();
        $registry->getConnection();

        $this->assertSame(
            [
                'SET statement_timeout = 15000',
                'SET lock_timeout = 2000',
                'SET idle_in_transaction_session_timeout = 60000',
            ],
            $applied
        );
        $this->assertInstanceOf(CountingConnection::class, $wrapped);
        $this->assertSame($connection, $wrapped->getInner());
    }

    public function testProfileChangeReappliesSessionStatements(): void
    {
        $connection = $this->createMock(DatabaseConnectionInterface::class);
        $connection->method('getPlatformName')->willReturn('postgresql');
        $applied = [];
        $connection->method('executeStatement')->willReturnCallback(function ($sql) use (&$applied) {
            $applied[] = $sql;
        });
        $policy = SafeQueryPolicy::defaults();
        $registry = new ConnectionRegistry('default', $policy);
        $registry->register('default', $connection);
        $registry->getConnection();

        $policy->setProfile(SafeQueryPolicy::PROFILE_EXPORT);
        $registry->getConnection();

        $this->assertCount(6, $applied);
        $this->assertSame('SET statement_timeout = 1800000', $applied[3]);
    }

    public function testFailedSessionStatementIsLoggedNotThrown(): void
    {
        $connection = $this->createMock(DatabaseConnectionInterface::class);
        $connection->method('getPlatformName')->willReturn('postgresql');
        $connection->method('executeStatement')->willThrowException(new \RuntimeException('permission denied'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->exactly(3))->method('warning');

        $registry = new ConnectionRegistry('default', SafeQueryPolicy::defaults(), $logger);
        $registry->register('default', $connection);

        $this->assertInstanceOf(DatabaseConnectionInterface::class, $registry->getConnection());
        // Повторный getConnection не пытается снова: профиль отмечен применённым.
        $registry->getConnection();
    }

    public function testSessionStatementsBypassTheQueryBudget(): void
    {
        $connection = $this->createMock(DatabaseConnectionInterface::class);
        $connection->method('getPlatformName')->willReturn('postgresql');
        $connection->method('fetchAllAssociative')->willReturn([]);

        $registry = new ConnectionRegistry('default', SafeQueryPolicy::defaults());
        $registry->register('default', $connection);
        $registry->getConnection()->fetchAllAssociative('SELECT 1');

        $this->assertSame(['default' => 1], $registry->getQueryCounts());
    }

    public function testWithoutPolicyThereAreNoCountsAndNoStatements(): void
    {
        $connection = $this->createMock(DatabaseConnectionInterface::class);
        $connection->method('getPlatformName')->willReturn('postgresql');
        $connection->expects($this->never())->method('executeStatement');

        $registry = new ConnectionRegistry('default');
        $registry->register('default', $connection);
        $registry->getConnection();

        $this->assertSame([], $registry->getQueryCounts());
        $this->assertNull($registry->getPolicy());
    }
}
