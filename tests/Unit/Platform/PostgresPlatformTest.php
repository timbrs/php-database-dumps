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
        $this->assertSame('"users"', $this->platform->quoteIdentifier('users'));
    }

    public function testQuoteIdentifierEscapesInnerQuotes(): void
    {
        // Защита от инъекций
        $this->assertSame('"weird""name"', $this->platform->quoteIdentifier('weird"name'));
    }

    public function testGetFullTableName(): void
    {
        $this->assertSame('"users"."users"', $this->platform->getFullTableName('users', 'users'));
    }

    public function testGetTruncateStatement(): void
    {
        $sql = $this->platform->getTruncateStatement('users', 'users');
        $this->assertSame('TRUNCATE TABLE "users"."users" CASCADE;', $sql);
    }

    public function testGetSequenceResetSqlWithSequences(): void
    {
        $connection = $this->createMock(DatabaseConnectionInterface::class);
        $connection->method('fetchAllAssociative')->willReturn([
            ['column_name' => 'id', 'sequence_name' => 'users.users_id_seq'],
        ]);

        $sql = $this->platform->getSequenceResetSql('users', 'users', $connection);

        $this->assertStringContainsString('Сброс sequences', $sql);
        $this->assertStringContainsString("setval('users.users_id_seq'", $sql);
        // Имя колонки квотируется через quoteIdentifier (не хардкод 'id')
        $this->assertStringContainsString('MAX("id")', $sql);
    }

    public function testGetSequenceResetSqlWithNoSequences(): void
    {
        $connection = $this->createMock(DatabaseConnectionInterface::class);
        $connection->method('fetchAllAssociative')->willReturn([]);

        $sql = $this->platform->getSequenceResetSql('users', 'users', $connection);

        $this->assertStringContainsString('Сброс sequences', $sql);
        $this->assertStringNotContainsString('setval', $sql);
    }

    public function testGetSequenceResetSqlHandlesException(): void
    {
        $connection = $this->createMock(DatabaseConnectionInterface::class);
        $connection->method('fetchAllAssociative')->willThrowException(new \Exception('DB error'));

        $sql = $this->platform->getSequenceResetSql('users', 'users', $connection);

        // Сообщение НЕ должно содержать детали ошибки (защита от утечки в дамп)
        $this->assertStringContainsString('Не удалось получить список sequences', $sql);
        $this->assertStringNotContainsString('DB error', $sql);
    }

    public function testGetSequenceResetSqlUsesProvidedColumnNameNotHardcoded(): void
    {
        // PK не "id" — должен использоваться возвращённый column_name
        $connection = $this->createMock(DatabaseConnectionInterface::class);
        $connection->method('fetchAllAssociative')->willReturn([
            ['column_name' => 'user_uuid', 'sequence_name' => 'public.user_seq'],
        ]);

        $sql = $this->platform->getSequenceResetSql('public', 'users', $connection);
        $this->assertStringContainsString('MAX("user_uuid")', $sql);
        $this->assertStringNotContainsString('MAX(id)', $sql);
    }

    public function testQuoteBoolean(): void
    {
        $this->assertSame('TRUE', $this->platform->quoteBoolean(true));
        $this->assertSame('FALSE', $this->platform->quoteBoolean(false));
    }

    public function testGetRandomFunctionSql(): void
    {
        $this->assertSame('RANDOM()', $this->platform->getRandomFunctionSql());
    }

    public function testGetLimitSql(): void
    {
        $this->assertSame('LIMIT 100', $this->platform->getLimitSql(100));
    }
}
