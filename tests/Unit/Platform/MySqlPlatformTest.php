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
        $this->assertEquals('`users`', $this->platform->quoteIdentifier('users'));
        $this->assertEquals('`my_table`', $this->platform->quoteIdentifier('my_table'));
    }

    public function testGetFullTableName(): void
    {
        $this->assertEquals('`users`.`users`', $this->platform->getFullTableName('users', 'users'));
        $this->assertEquals('`mydb`.`orders`', $this->platform->getFullTableName('mydb', 'orders'));
    }

    public function testGetTruncateStatementUsesDeleteWithForeignKeyChecks(): void
    {
        $sql = $this->platform->getTruncateStatement('users', 'users');

        $this->assertStringContainsString('SET FOREIGN_KEY_CHECKS=0;', $sql);
        $this->assertStringContainsString('DELETE FROM `users`.`users`;', $sql);
        $this->assertStringNotContainsString('TRUNCATE', $sql);
        $this->assertStringNotContainsString('CASCADE', $sql);
    }

    public function testGetTruncateStatementDisablesForeignKeyChecksBeforeDelete(): void
    {
        $sql = $this->platform->getTruncateStatement('users', 'users');

        $fkPos = strpos($sql, 'SET FOREIGN_KEY_CHECKS=0;');
        $deletePos = strpos($sql, 'DELETE FROM');
        $this->assertLessThan($deletePos, $fkPos);
    }

    public function testGetSequenceResetSqlUsesAutoIncrement(): void
    {
        $connection = $this->createMock(DatabaseConnectionInterface::class);

        $sql = $this->platform->getSequenceResetSql('users', 'users', $connection);

        $this->assertStringContainsString('AUTO_INCREMENT', $sql);
        $this->assertStringContainsString('`users`.`users`', $sql);
    }

    public function testGetSequenceResetSqlDoesNotContainSetval(): void
    {
        $connection = $this->createMock(DatabaseConnectionInterface::class);

        $sql = $this->platform->getSequenceResetSql('users', 'users', $connection);

        $this->assertStringNotContainsString('setval', $sql);
        $this->assertStringNotContainsString('pg_get_serial_sequence', $sql);
    }

    public function testGetSequenceResetSqlReenablesForeignKeyChecks(): void
    {
        $connection = $this->createMock(DatabaseConnectionInterface::class);

        $sql = $this->platform->getSequenceResetSql('users', 'users', $connection);

        $this->assertStringContainsString('SET FOREIGN_KEY_CHECKS=1;', $sql);
    }

    public function testGetRandomFunctionSql(): void
    {
        $this->assertEquals('RAND()', $this->platform->getRandomFunctionSql());
    }

    public function testGetLimitSql(): void
    {
        $this->assertEquals('LIMIT 100', $this->platform->getLimitSql(100));
        $this->assertEquals('LIMIT 1', $this->platform->getLimitSql(1));
    }
}
