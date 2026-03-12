<?php

namespace Timbrs\DatabaseDumps\Tests\Unit\Service\Importer;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Timbrs\DatabaseDumps\Contract\ConnectionRegistryInterface;
use Timbrs\DatabaseDumps\Contract\DatabaseConnectionInterface;
use Timbrs\DatabaseDumps\Service\Importer\TransactionManager;

class TransactionManagerTest extends TestCase
{
    /** @var MockObject&DatabaseConnectionInterface */
    private $connection;
    /** @var TransactionManager */
    private $manager;

    protected function setUp(): void
    {
        $this->connection = $this->createMock(DatabaseConnectionInterface::class);

        $registry = $this->createMock(ConnectionRegistryInterface::class);
        $registry->method('getConnection')->willReturn($this->connection);

        $this->manager = new TransactionManager($registry);
    }

    public function testBeginStartsTransaction(): void
    {
        $this->connection
            ->expects($this->once())
            ->method('isTransactionActive')
            ->willReturn(false);

        $this->connection
            ->expects($this->once())
            ->method('beginTransaction');

        $this->manager->begin();
    }

    public function testBeginDoesNotStartIfAlreadyActive(): void
    {
        $this->connection
            ->expects($this->once())
            ->method('isTransactionActive')
            ->willReturn(true);

        $this->connection
            ->expects($this->never())
            ->method('beginTransaction');

        $this->manager->begin();
    }

    public function testCommitCommitsTransaction(): void
    {
        $this->connection
            ->expects($this->once())
            ->method('isTransactionActive')
            ->willReturn(true);

        $this->connection
            ->expects($this->once())
            ->method('commit');

        $this->manager->commit();
    }

    public function testRollBackRollsBackTransaction(): void
    {
        $this->connection
            ->expects($this->once())
            ->method('isTransactionActive')
            ->willReturn(true);

        $this->connection
            ->expects($this->once())
            ->method('rollBack');

        $this->manager->rollBack();
    }

    public function testTransactionCommitsOnSuccess(): void
    {
        $this->connection->method('isTransactionActive')->willReturn(false, true);

        $this->connection
            ->expects($this->once())
            ->method('beginTransaction');

        $this->connection
            ->expects($this->once())
            ->method('commit');

        $result = $this->manager->transaction(function () {
            return 'success';
        });

        $this->assertEquals('success', $result);
    }

    public function testTransactionRollsBackOnException(): void
    {
        $this->connection->method('isTransactionActive')->willReturn(false, true);

        $this->connection
            ->expects($this->once())
            ->method('beginTransaction');

        $this->connection
            ->expects($this->once())
            ->method('rollBack');

        $this->connection
            ->expects($this->never())
            ->method('commit');

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Test error');

        $this->manager->transaction(function () {
            throw new \Exception('Test error');
        });
    }
}
