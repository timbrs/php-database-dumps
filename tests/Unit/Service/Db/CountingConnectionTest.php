<?php

namespace Timbrs\DatabaseDumps\Tests\Unit\Service\Db;

use PHPUnit\Framework\TestCase;
use Timbrs\DatabaseDumps\Contract\DatabaseConnectionInterface;
use Timbrs\DatabaseDumps\Exception\QueryBudgetExceededException;
use Timbrs\DatabaseDumps\Service\Db\CountingConnection;
use Timbrs\DatabaseDumps\Service\Db\SafeQueryPolicy;

class CountingConnectionTest extends TestCase
{
    /** @var DatabaseConnectionInterface&\PHPUnit\Framework\MockObject\MockObject */
    private $inner;

    protected function setUp(): void
    {
        $this->inner = $this->createMock(DatabaseConnectionInterface::class);
        $this->inner->method('fetchAllAssociative')->willReturn([['x' => 1]]);
        $this->inner->method('fetchFirstColumn')->willReturn([1]);
        $this->inner->method('iterateAssociative')->willReturnCallback(function () {
            yield ['x' => 1];
        });
        $this->inner->method('quote')->willReturn("'q'");
        $this->inner->method('getPlatformName')->willReturn('postgresql');
    }

    public function testCountsOnlyCallsThatReachTheDatabase(): void
    {
        $connection = new CountingConnection($this->inner, SafeQueryPolicy::defaults());

        $connection->fetchAllAssociative('SELECT 1');
        $connection->executeStatement('SET x = 1');
        $connection->fetchFirstColumn('SELECT 1');
        $generator = $connection->iterateAssociative('SELECT 1');
        $connection->quote('a');
        $connection->beginTransaction();
        $connection->rollBack();
        $connection->getPlatformName();

        self::assertSame(4, $connection->getQueryCount());
        self::assertSame([['x' => 1]], iterator_to_array($generator, false));
        self::assertSame($this->inner, $connection->getInner());
    }

    public function testExceedingTheBudgetInAnalyzeProfileThrows(): void
    {
        $connection = new CountingConnection($this->inner, new SafeQueryPolicy(['query_budget' => 2]), 'main');

        $connection->fetchAllAssociative('SELECT 1');
        $connection->fetchAllAssociative('SELECT 2');

        $this->expectException(QueryBudgetExceededException::class);
        $this->expectExceptionMessage('"main"');
        $connection->fetchAllAssociative('SELECT 3');
    }

    public function testExportProfileIsNotBudgeted(): void
    {
        $policy = new SafeQueryPolicy(['query_budget' => 1]);
        $policy->setProfile(SafeQueryPolicy::PROFILE_EXPORT);
        $connection = new CountingConnection($this->inner, $policy);

        $connection->fetchAllAssociative('SELECT 1');
        $connection->fetchAllAssociative('SELECT 2');

        self::assertSame(2, $connection->getQueryCount());
    }

    public function testResetStartsCountingAgain(): void
    {
        $connection = new CountingConnection($this->inner, SafeQueryPolicy::defaults());
        $connection->fetchAllAssociative('SELECT 1');
        $connection->resetQueryCount();

        self::assertSame(0, $connection->getQueryCount());
    }
}
