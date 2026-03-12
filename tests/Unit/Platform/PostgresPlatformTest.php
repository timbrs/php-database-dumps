<?php

namespace Timbrs\DatabaseDumps\Tests\Unit\Platform;

use PHPUnit\Framework\TestCase;
use Timbrs\DatabaseDumps\Contract\DatabaseConnectionInterface;
use Timbrs\DatabaseDumps\Platform\PostgresPlatform;

class PostgresPlatformTest extends TestCase
{
    /** @var PostgresPlatform */
    private $platform;

    protected function setUp(): void
    {
        $this->platform = new PostgresPlatform();
    }

    public function testQuoteIdentifier(): void
    {
        $this->assertEquals('"users"', $this->platform->quoteIdentifier('users'));
        $this->assertEquals('"my_table"', $this->platform->quoteIdentifier('my_table'));
    }

    public function testGetFullTableName(): void
    {
        $this->assertEquals('"users"."users"', $this->platform->getFullTableName('users', 'users'));
        $this->assertEquals('"public"."orders"', $this->platform->getFullTableName('public', 'orders'));
    }

    public function testGetTruncateStatement(): void
    {
        $sql = $this->platform->getTruncateStatement('users', 'users');

        $this->assertEquals('TRUNCATE TABLE "users"."users" CASCADE;', $sql);
    }

    public function testGetTruncateStatementIncludesCascade(): void
    {
        $sql = $this->platform->getTruncateStatement('public', 'orders');

        $this->assertStringContainsString('CASCADE', $sql);
    }

    public function testGetSequenceResetSqlWithSequences(): void
    {
        $connection = $this->createMock(DatabaseConnectionInterface::class);
        $connection
            ->expects($this->once())
            ->method('fetchFirstColumn')
            ->willReturn(['users.users_id_seq']);

        $sql = $this->platform->getSequenceResetSql('users', 'users', $connection);

        $this->assertStringContainsString('Сброс sequences', $sql);
        $this->assertStringContainsString("setval('users.users_id_seq'", $sql);
        $this->assertStringContainsString('MAX(id)', $sql);
    }

    public function testGetSequenceResetSqlWithNoSequences(): void
    {
        $connection = $this->createMock(DatabaseConnectionInterface::class);
        $connection
            ->expects($this->once())
            ->method('fetchFirstColumn')
            ->willReturn([]);

        $sql = $this->platform->getSequenceResetSql('users', 'users', $connection);

        $this->assertStringContainsString('Сброс sequences', $sql);
        $this->assertStringNotContainsString('setval', $sql);
    }

    public function testGetSequenceResetSqlHandlesException(): void
    {
        $connection = $this->createMock(DatabaseConnectionInterface::class);
        $connection
            ->expects($this->once())
            ->method('fetchFirstColumn')
            ->willThrowException(new \Exception('Database error'));

        $sql = $this->platform->getSequenceResetSql('users', 'users', $connection);

        $this->assertStringContainsString('Ошибка получения sequences', $sql);
    }

    public function testGetRandomFunctionSql(): void
    {
        $this->assertEquals('RANDOM()', $this->platform->getRandomFunctionSql());
    }

    public function testGetLimitSql(): void
    {
        $this->assertEquals('LIMIT 100', $this->platform->getLimitSql(100));
        $this->assertEquals('LIMIT 1', $this->platform->getLimitSql(1));
    }
}
