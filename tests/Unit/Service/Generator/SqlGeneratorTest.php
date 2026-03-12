<?php

namespace Timbrs\DatabaseDumps\Tests\Unit\Service\Generator;

use PHPUnit\Framework\TestCase;
use Timbrs\DatabaseDumps\Config\TableConfig;
use Timbrs\DatabaseDumps\Contract\ConnectionRegistryInterface;
use Timbrs\DatabaseDumps\Contract\DatabaseConnectionInterface;
use Timbrs\DatabaseDumps\Platform\PostgresPlatform;
use Timbrs\DatabaseDumps\Service\Generator\InsertGenerator;
use Timbrs\DatabaseDumps\Service\Generator\SequenceGenerator;
use Timbrs\DatabaseDumps\Service\Generator\SqlGenerator;
use Timbrs\DatabaseDumps\Service\Generator\TruncateGenerator;

class SqlGeneratorTest extends TestCase
{
    /** @var SqlGenerator */
    private $generator;

    protected function setUp(): void
    {
        $connection = $this->createMock(DatabaseConnectionInterface::class);
        $connection->method('quote')->willReturnCallback(function ($value) {
            return "'{$value}'";
        });
        $connection->method('fetchFirstColumn')->willReturn([]);

        $platform = new PostgresPlatform();

        $registry = $this->createMock(ConnectionRegistryInterface::class);
        $registry->method('getConnection')->willReturn($connection);
        $registry->method('getPlatform')->willReturn($platform);

        $truncateGenerator = new TruncateGenerator($registry);
        $insertGenerator = new InsertGenerator($registry);
        $sequenceGenerator = new SequenceGenerator($registry);

        $this->generator = new SqlGenerator(
            $truncateGenerator,
            $insertGenerator,
            $sequenceGenerator
        );
    }

    public function testGenerateFullExport(): void
    {
        $config = new TableConfig('users', 'users');
        $rows = [
            ['id' => 1, 'name' => 'User 1'],
            ['id' => 2, 'name' => 'User 2']
        ];

        $sql = $this->generator->generate($config, $rows);

        $this->assertStringContainsString('Дамп таблицы: users.users', $sql);
        $this->assertStringContainsString('Количество записей: 2', $sql);
        $this->assertStringContainsString('Режим: full', $sql);
        $this->assertStringContainsString('TRUNCATE TABLE', $sql);
        $this->assertStringContainsString('INSERT INTO', $sql);
        $this->assertStringContainsString('Сброс sequences', $sql);
    }

    public function testGeneratePartialExport(): void
    {
        $config = new TableConfig('clients', 'clients', 100);
        $rows = [
            ['id' => 1, 'name' => 'Client 1']
        ];

        $sql = $this->generator->generate($config, $rows);

        $this->assertStringContainsString('Дамп таблицы: clients.clients', $sql);
        $this->assertStringContainsString('Режим: partial (limit 100)', $sql);
    }

    public function testGenerateIncludesTimestamp(): void
    {
        $config = new TableConfig('users', 'users');
        $rows = [];

        $sql = $this->generator->generate($config, $rows);

        $this->assertStringContainsString('Дата экспорта:', $sql);
        $this->assertMatchesRegularExpression('/\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}/', $sql);
    }

    public function testGenerateChunksYieldsHeaderThenInsertsThenFooter(): void
    {
        $config = new TableConfig('users', 'users');
        $rows = [
            ['id' => 1, 'name' => 'User 1'],
            ['id' => 2, 'name' => 'User 2']
        ];

        $chunks = iterator_to_array($this->generator->generateChunks($config, $rows));

        // Первый чанк: заголовок + TRUNCATE
        $this->assertStringContainsString('Дамп таблицы: users.users', $chunks[0]);
        $this->assertStringContainsString('TRUNCATE TABLE', $chunks[0]);
        $this->assertStringContainsString('Режим: full', $chunks[0]);

        // Второй чанк: INSERT батч
        $this->assertStringContainsString('INSERT INTO', $chunks[1]);
        $this->assertStringContainsString('Batch 1', $chunks[1]);

        // Последний чанк: sequence reset
        $lastChunk = $chunks[count($chunks) - 1];
        $this->assertStringContainsString('Сброс sequences', $lastChunk);
    }

    public function testGenerateChunksPartialExport(): void
    {
        $config = new TableConfig('clients', 'clients', 100);
        $rows = [
            ['id' => 1, 'name' => 'Client 1']
        ];

        $chunks = iterator_to_array($this->generator->generateChunks($config, $rows));

        $this->assertStringContainsString('Режим: partial (limit 100)', $chunks[0]);
    }

    public function testGenerateIncludesFetchQuery(): void
    {
        $config = new TableConfig('users', 'users');
        $rows = [['id' => 1, 'name' => 'User 1']];
        $query = 'SELECT * FROM "users"."users"';

        $sql = $this->generator->generate($config, $rows, $query);

        $this->assertStringContainsString('-- Запрос: SELECT * FROM "users"."users"', $sql);
    }

    public function testGenerateChunksIncludesFetchQuery(): void
    {
        $config = new TableConfig('users', 'users');
        $rows = [['id' => 1, 'name' => 'User 1']];
        $query = 'SELECT * FROM "users"."users" WHERE is_active = true ORDER BY id LIMIT 500';

        $chunks = iterator_to_array($this->generator->generateChunks($config, $rows, $query));

        $this->assertStringContainsString('-- Запрос: ' . $query, $chunks[0]);
    }

    public function testGenerateWithoutFetchQuery(): void
    {
        $config = new TableConfig('users', 'users');
        $rows = [['id' => 1, 'name' => 'User 1']];

        $sql = $this->generator->generate($config, $rows);

        $this->assertStringNotContainsString('-- Запрос:', $sql);
    }

    public function testGenerateChunksEmptyRows(): void
    {
        $config = new TableConfig('users', 'users');

        $chunks = iterator_to_array($this->generator->generateChunks($config, []));

        // Заголовок + TRUNCATE
        $this->assertStringContainsString('Дамп таблицы: users.users', $chunks[0]);
        $this->assertStringContainsString('TRUNCATE TABLE', $chunks[0]);

        // Пустая таблица — сообщение
        $this->assertStringContainsString('Таблица пуста', $chunks[1]);
    }
}
