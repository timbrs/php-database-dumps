<?php

namespace Timbrs\DatabaseDumps\Tests\Unit\Service\Analysis;

use PHPUnit\Framework\TestCase;
use Timbrs\DatabaseDumps\Contract\ConnectionRegistryInterface;
use Timbrs\DatabaseDumps\Contract\DatabaseConnectionInterface;
use Timbrs\DatabaseDumps\Platform\PostgresPlatform;
use Timbrs\DatabaseDumps\Service\Analysis\CriteriaSqlTester;

class CriteriaSqlTesterTest extends TestCase
{
    /** @var DatabaseConnectionInterface&\PHPUnit\Framework\MockObject\MockObject */
    private $connection;

    /** @var ConnectionRegistryInterface&\PHPUnit\Framework\MockObject\MockObject */
    private $registry;

    protected function setUp(): void
    {
        $this->connection = $this->createMock(DatabaseConnectionInterface::class);
        $this->registry = $this->createMock(ConnectionRegistryInterface::class);
        $this->registry->method('getConnection')->willReturn($this->connection);
        $this->registry->method('getPlatform')->willReturn(new PostgresPlatform());
    }

    private function tester(): CriteriaSqlTester
    {
        return new CriteriaSqlTester($this->registry);
    }

    public function testValidWhereReturnsNullAndBuildsLimitedSelect(): void
    {
        $captured = null;
        $this->connection->method('fetchFirstColumn')->willReturnCallback(function ($sql) use (&$captured) {
            $captured = $sql;
            return [];
        });

        $result = $this->tester()->test('users', 'users', "active_flg = 1");

        $this->assertNull($result);
        // Прогон как у дампера: SELECT 1 FROM schema.table WHERE (<where>) LIMIT 1.
        $this->assertStringContainsString('SELECT 1 FROM "users"."users" WHERE (active_flg = 1) LIMIT 1', (string) $captured);
    }

    public function testFailingWhereReturnsShortError(): void
    {
        $this->connection->method('fetchFirstColumn')->willReturnCallback(function () {
            throw new \RuntimeException("SQLSTATE[42P01]: Undefined table: 7 ERROR:  missing FROM-clause entry for table \"t1\"\nLINE 1: ...");
        });

        $result = $this->tester()->test('users', 'users', 't1.active_flg = 1');

        $this->assertNotNull($result);
        // Первая строка ошибки, без переводов строк.
        $this->assertStringContainsString('missing FROM-clause entry for table', (string) $result);
        $this->assertStringNotContainsString("\n", (string) $result);
    }

    public function testEmptyWhereReturnsError(): void
    {
        $this->assertSame('пустой WHERE', $this->tester()->test('users', 'users', '   '));
    }
}
