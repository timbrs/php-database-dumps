<?php

namespace Timbrs\DatabaseDumps\Tests\Unit\Platform;

use PHPUnit\Framework\TestCase;
use Timbrs\DatabaseDumps\Contract\DatabaseConnectionInterface;
use Timbrs\DatabaseDumps\Platform\MySqlPlatform;

class MySqlPlatformTest extends TestCase
{
    /** @var MySqlPlatform */
    private $platform;

    protected function setUp(): void
    {
        $this->platform = new MySqlPlatform();
    }

    public function testQuoteIdentifierUsesBackticks(): void
    {
        $this->assertSame('`users`', $this->platform->quoteIdentifier('users'));
        $this->assertSame('`my_table`', $this->platform->quoteIdentifier('my_table'));
    }

    public function testQuoteIdentifierEscapesBackticks(): void
    {
        // Защита от инъекции: внутренний backtick должен удваиваться.
        $this->assertSame('`tab``le`', $this->platform->quoteIdentifier('tab`le'));
    }

    public function testGetFullTableName(): void
    {
        $this->assertSame('`users`.`users`', $this->platform->getFullTableName('users', 'users'));
        $this->assertSame('`mydb`.`orders`', $this->platform->getFullTableName('mydb', 'orders'));
    }

    public function testGetTruncateStatementUsesDelete(): void
    {
        $sql = $this->platform->getTruncateStatement('users', 'users');

        // FOREIGN_KEY_CHECKS теперь управляется DatabaseImporter (try/finally на всю сессию),
        // в SQL дампе их нет.
        $this->assertStringContainsString('DELETE FROM `users`.`users`;', $sql);
        $this->assertStringNotContainsString('TRUNCATE', $sql);
        $this->assertStringNotContainsString('CASCADE', $sql);
        $this->assertStringNotContainsString('FOREIGN_KEY_CHECKS', $sql);
    }

    public function testDisableEnableForeignKeysSql(): void
    {
        $this->assertSame('SET FOREIGN_KEY_CHECKS=0', $this->platform->disableForeignKeysSql());
        $this->assertSame('SET FOREIGN_KEY_CHECKS=1', $this->platform->enableForeignKeysSql());
    }

    public function testGetSequenceResetSqlNoAutoIncrementColumn(): void
    {
        $connection = $this->createMock(DatabaseConnectionInterface::class);
        $connection->method('fetchAllAssociative')->willReturn([]);

        $sql = $this->platform->getSequenceResetSql('users', 'users', $connection);

        $this->assertStringContainsString('AUTO_INCREMENT', $sql);
        $this->assertStringContainsString('не найдена', $sql);
    }

    public function testGetSequenceResetSqlWithAutoIncrementColumn(): void
    {
        $connection = $this->createMock(DatabaseConnectionInterface::class);
        $connection->method('fetchAllAssociative')->willReturnOnConsecutiveCalls(
            [['column_name' => 'id']],
            [['next_value' => 42]]
        );

        $sql = $this->platform->getSequenceResetSql('users', 'users', $connection);

        $this->assertStringContainsString('ALTER TABLE `users`.`users` AUTO_INCREMENT = 42', $sql);
    }

    public function testQuoteBoolean(): void
    {
        $this->assertSame('1', $this->platform->quoteBoolean(true));
        $this->assertSame('0', $this->platform->quoteBoolean(false));
    }

    public function testGetRandomFunctionSql(): void
    {
        $this->assertSame('RAND()', $this->platform->getRandomFunctionSql());
    }

    public function testGetLimitSql(): void
    {
        $this->assertSame('LIMIT 100', $this->platform->getLimitSql(100));
        $this->assertSame('LIMIT 1', $this->platform->getLimitSql(1));
    }
}
