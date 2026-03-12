<?php

namespace Timbrs\DatabaseDumps\Tests\Unit\Platform;

use PHPUnit\Framework\TestCase;
use Timbrs\DatabaseDumps\Contract\DatabaseConnectionInterface;
use Timbrs\DatabaseDumps\Platform\OraclePlatform;

class OraclePlatformTest extends TestCase
{
    /** @var OraclePlatform */
    private $platform;

    protected function setUp(): void
    {
        $this->platform = new OraclePlatform();
    }

    public function testQuoteIdentifierUppercases(): void
    {
        $this->assertEquals('"ID"', $this->platform->quoteIdentifier('id'));
        $this->assertEquals('"MY_TABLE"', $this->platform->quoteIdentifier('my_table'));
        $this->assertEquals('"USERS"', $this->platform->quoteIdentifier('Users'));
    }

    public function testGetFullTableName(): void
    {
        $this->assertEquals('"SCHEMA"."TABLE"', $this->platform->getFullTableName('schema', 'table'));
        $this->assertEquals('"PUBLIC"."ORDERS"', $this->platform->getFullTableName('public', 'orders'));
    }

    public function testGetTruncateStatementUsesDelete(): void
    {
        $sql = $this->platform->getTruncateStatement('users', 'users');

        $this->assertStringContainsString('DELETE FROM', $sql);
        $this->assertStringContainsString('"USERS"."USERS"', $sql);
        $this->assertStringNotContainsString('TRUNCATE', $sql);
    }

    public function testGetTruncateStatementNoCascade(): void
    {
        $sql = $this->platform->getTruncateStatement('public', 'orders');

        $this->assertStringNotContainsString('CASCADE', $sql);
    }

    public function testGetSequenceResetSql(): void
    {
        $connection = $this->createMock(DatabaseConnectionInterface::class);

        $sql = $this->platform->getSequenceResetSql('public', 'users', $connection);

        $this->assertStringContainsString('не поддерживается автоматически', $sql);
        $this->assertStringContainsString('after_exec/', $sql);
    }

    public function testGetRandomFunctionSql(): void
    {
        $this->assertEquals('DBMS_RANDOM.VALUE', $this->platform->getRandomFunctionSql());
    }

    public function testGetLimitSql(): void
    {
        $this->assertEquals('FETCH FIRST 100 ROWS ONLY', $this->platform->getLimitSql(100));
        $this->assertEquals('FETCH FIRST 1 ROWS ONLY', $this->platform->getLimitSql(1));
    }
}
