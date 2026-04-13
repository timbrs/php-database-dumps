<?php

namespace Timbrs\DatabaseDumps\Tests\Unit\Service\Generator;

use PHPUnit\Framework\TestCase;
use Timbrs\DatabaseDumps\Contract\ConnectionRegistryInterface;
use Timbrs\DatabaseDumps\Contract\DatabaseConnectionInterface;
use Timbrs\DatabaseDumps\Platform\OraclePlatform;
use Timbrs\DatabaseDumps\Platform\PlatformFactory;
use Timbrs\DatabaseDumps\Platform\PostgresPlatform;
use Timbrs\DatabaseDumps\Service\Generator\InsertGenerator;

class InsertGeneratorTest extends TestCase
{
    /** @var DatabaseConnectionInterface&\PHPUnit\Framework\MockObject\MockObject */
    private $connection;

    /** @var InsertGenerator */
    private $generator;

    protected function setUp(): void
    {
        $this->connection = $this->createMock(DatabaseConnectionInterface::class);
        $this->connection->method('quote')->willReturnCallback(function ($value) {
            return "'{$value}'";
        });

        $platform = new PostgresPlatform();

        $registry = $this->createMock(ConnectionRegistryInterface::class);
        $registry->method('getConnection')->willReturn($this->connection);
        $registry->method('getPlatform')->willReturn($platform);

        $this->generator = new InsertGenerator($registry);
    }

    public function testGenerateWithEmptyRows(): void
    {
        $sql = $this->generator->generate('users', 'users', []);

        $this->assertStringContainsString('Таблица пуста', $sql);
        $this->assertStringNotContainsString('INSERT', $sql);
    }

    public function testGenerateWithSingleRow(): void
    {
        $rows = [
            ['id' => 1, 'name' => 'User 1', 'email' => 'user1@example.com']
        ];

        $sql = $this->generator->generate('users', 'users', $rows);

        $this->assertStringContainsString('INSERT INTO "users"."users"', $sql);
        $this->assertStringContainsString('"id", "name", "email"', $sql);
        $this->assertStringContainsString("'1'", $sql);
        $this->assertStringContainsString("'User 1'", $sql);
        $this->assertStringContainsString("'user1@example.com'", $sql);
    }

    public function testGenerateWithMultipleRows(): void
    {
        $rows = [
            ['id' => 1, 'name' => 'User 1'],
            ['id' => 2, 'name' => 'User 2'],
            ['id' => 3, 'name' => 'User 3']
        ];

        $sql = $this->generator->generate('users', 'users', $rows);

        $this->assertStringContainsString('Batch 1 (3 rows)', $sql);
        $this->assertStringContainsString("'User 1'", $sql);
        $this->assertStringContainsString("'User 2'", $sql);
        $this->assertStringContainsString("'User 3'", $sql);
    }

    public function testGenerateHandlesNullValues(): void
    {
        $rows = [
            ['id' => 1, 'name' => 'User 1', 'email' => null]
        ];

        $sql = $this->generator->generate('users', 'users', $rows);

        $this->assertStringContainsString('NULL', $sql);
    }

    public function testGenerateBatchesLargeDataset(): void
    {
        // Create 2500 rows to test batching (should create 3 batches: 1000, 1000, 500)
        $rows = [];
        for ($i = 1; $i <= 2500; $i++) {
            $rows[] = ['id' => $i, 'name' => "User {$i}"];
        }

        $sql = $this->generator->generate('users', 'users', $rows);

        $this->assertStringContainsString('Batch 1 (1000 rows)', $sql);
        $this->assertStringContainsString('Batch 2 (1000 rows)', $sql);
        $this->assertStringContainsString('Batch 3 (500 rows)', $sql);
    }

    public function testGenerateQuotesIdentifiers(): void
    {
        $rows = [['id' => 1, 'name' => 'Test']];

        $sql = $this->generator->generate('test_schema', 'test_table', $rows);

        $this->assertStringContainsString('"test_schema"."test_table"', $sql);
        $this->assertStringContainsString('"id"', $sql);
        $this->assertStringContainsString('"name"', $sql);
    }

    public function testGenerateOracleSingleRowInserts(): void
    {
        $connection = $this->createMock(DatabaseConnectionInterface::class);
        $connection->method('quote')->willReturnCallback(function ($value) {
            return "'{$value}'";
        });
        $connection->method('getPlatformName')->willReturn(PlatformFactory::ORACLE);

        $platform = new OraclePlatform();

        $registry = $this->createMock(ConnectionRegistryInterface::class);
        $registry->method('getConnection')->willReturn($connection);
        $registry->method('getPlatform')->willReturn($platform);

        $generator = new InsertGenerator($registry);

        $rows = [
            ['id' => 1, 'name' => 'User 1'],
            ['id' => 2, 'name' => 'User 2'],
            ['id' => 3, 'name' => 'User 3'],
        ];

        $sql = $generator->generate('users', 'users', $rows);

        // Каждая строка — отдельный INSERT
        $this->assertEquals(3, substr_count($sql, 'INSERT INTO'));
        $this->assertStringContainsString('"USERS"."USERS"', $sql);
        $this->assertStringContainsString('"ID", "NAME"', $sql);
        $this->assertStringNotContainsString('Batch', $sql);

        // Нет multi-row VALUES (запятой между строками)
        $this->assertStringNotContainsString("),\n(", $sql);
    }

    public function testGenerateHandlesBooleanValues(): void
    {
        $rows = [
            ['id' => 1, 'name' => 'User 1', 'is_active' => true, 'is_deleted' => false]
        ];

        $sql = $this->generator->generate('users', 'users', $rows);

        $this->assertStringContainsString('TRUE', $sql);
        $this->assertStringContainsString('FALSE', $sql);
        // Boolean не должны быть в кавычках
        $this->assertStringNotContainsString("'TRUE'", $sql);
        $this->assertStringNotContainsString("'FALSE'", $sql);
        $this->assertStringNotContainsString("''", $sql);
    }

    public function testGenerateOracleHandlesBooleanValues(): void
    {
        $connection = $this->createMock(DatabaseConnectionInterface::class);
        $connection->method('quote')->willReturnCallback(function ($value) {
            return "'{$value}'";
        });
        $connection->method('getPlatformName')->willReturn(PlatformFactory::ORACLE);

        $platform = new OraclePlatform();

        $registry = $this->createMock(ConnectionRegistryInterface::class);
        $registry->method('getConnection')->willReturn($connection);
        $registry->method('getPlatform')->willReturn($platform);

        $generator = new InsertGenerator($registry);

        $rows = [
            ['id' => 1, 'is_active' => true, 'is_deleted' => false],
        ];

        $sql = $generator->generate('users', 'users', $rows);

        $this->assertStringContainsString('TRUE', $sql);
        $this->assertStringContainsString('FALSE', $sql);
        $this->assertStringNotContainsString("'TRUE'", $sql);
        $this->assertStringNotContainsString("'FALSE'", $sql);
        $this->assertStringNotContainsString("''", $sql);
    }

    public function testGenerateOracleHandlesNullValues(): void
    {
        $connection = $this->createMock(DatabaseConnectionInterface::class);
        $connection->method('quote')->willReturnCallback(function ($value) {
            return "'{$value}'";
        });
        $connection->method('getPlatformName')->willReturn(PlatformFactory::ORACLE);

        $platform = new OraclePlatform();

        $registry = $this->createMock(ConnectionRegistryInterface::class);
        $registry->method('getConnection')->willReturn($connection);
        $registry->method('getPlatform')->willReturn($platform);

        $generator = new InsertGenerator($registry);

        $rows = [
            ['id' => 1, 'name' => 'User 1', 'email' => null],
        ];

        $sql = $generator->generate('users', 'users', $rows);

        $this->assertStringContainsString('NULL', $sql);
        $this->assertEquals(1, substr_count($sql, 'INSERT INTO'));
    }

    public function testGenerateChunksYieldsEmptyMessage(): void
    {
        $chunks = iterator_to_array($this->generator->generateChunks('users', 'users', []));

        $this->assertCount(1, $chunks);
        $this->assertStringContainsString('Таблица пуста', $chunks[0]);
    }

    public function testGenerateChunksYieldsSingleBatch(): void
    {
        $rows = [
            ['id' => 1, 'name' => 'User 1'],
            ['id' => 2, 'name' => 'User 2']
        ];

        $chunks = iterator_to_array($this->generator->generateChunks('users', 'users', $rows));

        $this->assertCount(1, $chunks);
        $this->assertStringContainsString('Batch 1 (2 rows)', $chunks[0]);
        $this->assertStringContainsString('INSERT INTO', $chunks[0]);
    }

    public function testGenerateChunksYieldsMultipleBatches(): void
    {
        $rows = [];
        for ($i = 1; $i <= 2500; $i++) {
            $rows[] = ['id' => $i, 'name' => "User {$i}"];
        }

        $chunks = iterator_to_array($this->generator->generateChunks('users', 'users', $rows));

        $this->assertCount(3, $chunks);
        $this->assertStringContainsString('Batch 1 (1000 rows)', $chunks[0]);
        $this->assertStringContainsString('Batch 2 (1000 rows)', $chunks[1]);
        $this->assertStringContainsString('Batch 3 (500 rows)', $chunks[2]);

        // Каждый чанк содержит свой INSERT
        foreach ($chunks as $chunk) {
            $this->assertStringContainsString('INSERT INTO', $chunk);
        }
    }

    public function testGenerateWithDeferredColumnsReplacesWithNull(): void
    {
        $this->generator->setDeferredColumns([
            ['column' => 'parent_id', 'reference_table' => 'public.categories', 'reference_column' => 'id'],
        ]);

        $rows = [
            ['id' => 1, 'name' => 'Root', 'parent_id' => null],
            ['id' => 2, 'name' => 'Child', 'parent_id' => 1],
            ['id' => 3, 'name' => 'Grandchild', 'parent_id' => 2],
        ];

        $sql = $this->generator->generate('public', 'categories', $rows);

        // parent_id должен быть NULL для всех строк
        $this->assertStringContainsString('INSERT INTO', $sql);
        // Все 3 значения parent_id заменены на NULL
        // Строка 1: parent_id=null (уже NULL), строка 2: parent_id=1→NULL, строка 3: parent_id=2→NULL
        // Проверяем что в SQL нет значений '1' для parent_id и '2' для parent_id
        // (но '1' присутствует как id, поэтому проверяем deferred values)

        $deferred = $this->generator->getCollectedDeferredValues();
        // Строка 1: parent_id=null → пропущена, строка 2: parent_id=1, строка 3: parent_id=2
        $this->assertCount(2, $deferred);
        $this->assertEquals('id', $deferred[0]['pk_column']);
        $this->assertEquals(2, $deferred[0]['pk_value']);
        $this->assertEquals('parent_id', $deferred[0]['column']);
        $this->assertEquals(1, $deferred[0]['value']);
        $this->assertEquals(3, $deferred[1]['pk_value']);
        $this->assertEquals(2, $deferred[1]['value']);

        // Cleanup
        $this->generator->setDeferredColumns(null);
    }

    public function testGenerateWithoutDeferredColumnsDoesNotCollect(): void
    {
        $rows = [
            ['id' => 1, 'name' => 'User 1', 'parent_id' => 2],
        ];

        $this->generator->setDeferredColumns(null);
        $this->generator->generate('public', 'users', $rows);

        $this->assertEmpty($this->generator->getCollectedDeferredValues());
    }

    public function testGenerateChunksOracleYieldsChunks(): void
    {
        $connection = $this->createMock(DatabaseConnectionInterface::class);
        $connection->method('quote')->willReturnCallback(function ($value) {
            return "'{$value}'";
        });
        $connection->method('getPlatformName')->willReturn(PlatformFactory::ORACLE);

        $platform = new OraclePlatform();

        $registry = $this->createMock(ConnectionRegistryInterface::class);
        $registry->method('getConnection')->willReturn($connection);
        $registry->method('getPlatform')->willReturn($platform);

        $generator = new InsertGenerator($registry);

        $rows = [
            ['id' => 1, 'name' => 'User 1'],
            ['id' => 2, 'name' => 'User 2'],
            ['id' => 3, 'name' => 'User 3']
        ];

        $chunks = iterator_to_array($generator->generateChunks('users', 'users', $rows));

        // 3 строки < BATCH_SIZE → один чанк
        $this->assertCount(1, $chunks);
        $this->assertEquals(3, substr_count($chunks[0], 'INSERT INTO'));
    }
}
