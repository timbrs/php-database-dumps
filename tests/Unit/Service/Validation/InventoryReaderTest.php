<?php

namespace Timbrs\DatabaseDumps\Tests\Unit\Service\Validation;

use Timbrs\DatabaseDumps\Service\Validation\InventoryReader;
use Timbrs\DatabaseDumps\Tests\Support\InMemoryFileSystem;
use Timbrs\DatabaseDumps\Tests\Support\ValidationTestCase;

class InventoryReaderTest extends ValidationTestCase
{
    private const DIR = '/proj/database/analysis';

    /**
     * @param array<string, string> $files
     */
    private function reader(array $files): InventoryReader
    {
        return new InventoryReader(new InMemoryFileSystem($files), self::INVENTORY_PATH);
    }

    /**
     * @return array<string, array<string, array<string, mixed>>>
     */
    private function twoSchemas(): array
    {
        return [
            'pub' => [
                'orders' => [
                    'row_count' => 42,
                    'columns' => ['id' => 'bigint', 'name' => 'character varying'],
                    'profiles' => ['name' => ['distinct_count' => 7, 'categorical' => true]],
                    'foreign_keys' => [[
                        'column' => 'client_id',
                        'references_table' => 'pub.clients',
                        'references_column' => 'id',
                    ]],
                ],
            ],
            'tasks' => ['jobs' => ['row_count' => 5, 'columns' => ['job_id' => 'bigint']]],
        ];
    }

    public function testReadsMonolith(): void
    {
        $reader = $this->reader([self::INVENTORY_PATH => $this->inventoryJson($this->twoSchemas())]);

        $this->assertTrue($reader->exists());
        $this->assertSame(self::GENERATED_AT, $reader->generatedAt());
        $this->assertSame(['pub', 'tasks'], $reader->schemas());
        $this->assertSame(['orders'], $reader->tables('pub'));
        $this->assertSame(['id', 'name'], $reader->columns('pub', 'orders'));
        $this->assertSame('character varying', $reader->columnType('pub', 'orders', 'name'));
        $this->assertSame(42, $reader->rowCount('pub', 'orders'));
        $this->assertSame(2, $reader->countTables());
        $this->assertTrue($reader->hasColumn('pub', 'orders', 'id'));
        $this->assertFalse($reader->hasColumn('pub', 'orders', 'ghost'));
    }

    public function testProfileAndForeignKeys(): void
    {
        $reader = $this->reader([self::INVENTORY_PATH => $this->inventoryJson($this->twoSchemas())]);

        $profile = $reader->profile('pub', 'orders', 'name');
        $this->assertNotNull($profile);
        $this->assertSame(7, $profile['distinct_count']);
        $this->assertTrue($profile['categorical']);

        $fks = $reader->foreignKeys('pub', 'orders');
        $this->assertCount(1, $fks);
        $this->assertSame('pub.clients', $fks[0]['references_table']);
        $this->assertSame([], $reader->foreignKeys('tasks', 'jobs'));
    }

    /**
     * Пер-схемные файлы того же прогона — предпочтительный источник: так в память
     * поднимается одна схема, а не весь слепок.
     */
    public function testPrefersPerSchemaFilesOfTheSameRun(): void
    {
        $files = [
            self::INVENTORY_PATH => $this->inventoryJson(['pub' => []]),
            self::DIR . '/schema_inventory.pub.json' => $this->inventoryJson([
                'pub' => ['orders' => ['row_count' => 1, 'columns' => ['id' => 'bigint']]],
            ]),
            self::DIR . '/schema_inventory.tasks.json' => $this->inventoryJson([
                'tasks' => ['jobs' => ['row_count' => 2, 'columns' => ['job_id' => 'bigint']]],
            ]),
        ];

        $reader = $this->reader($files);

        $this->assertSame(['pub', 'tasks'], $reader->schemas());
        // Монолит для pub пуст — значит данные взяты именно из пер-схемного файла.
        $this->assertSame(['orders'], $reader->tables('pub'));
        $this->assertSame(2, $reader->rowCount('tasks', 'jobs'));
    }

    /**
     * Пер-схемные файлы от другого прогона (другой generated_at) — остатки прошлого
     * сбора; читаем монолит, чтобы не смешивать два состояния схемы.
     */
    public function testFallsBackToMonolithWhenPerSchemaFilesAreStale(): void
    {
        $files = [
            self::INVENTORY_PATH => $this->inventoryJson([
                'pub' => ['orders' => ['row_count' => 99, 'columns' => ['id' => 'bigint']]],
            ]),
            self::DIR . '/schema_inventory.pub.json' => $this->inventoryJson(
                ['pub' => ['orders' => ['row_count' => 1, 'columns' => ['id' => 'bigint']]]],
                '2025-01-01T00:00:00Z'
            ),
        ];

        $this->assertSame(99, $this->reader($files)->rowCount('pub', 'orders'));
    }

    public function testWorksWithOnlyPerSchemaFiles(): void
    {
        $reader = $this->reader([
            self::DIR . '/schema_inventory.pub.json' => $this->inventoryJson([
                'pub' => ['orders' => ['row_count' => 3, 'columns' => ['id' => 'bigint']]],
            ]),
        ]);

        $this->assertTrue($reader->exists());
        $this->assertSame(self::GENERATED_AT, $reader->generatedAt());
        $this->assertSame(['pub'], $reader->schemas());
        $this->assertSame(3, $reader->rowCount('pub', 'orders'));
    }

    public function testMissingInventoryIsNotAnError(): void
    {
        $reader = $this->reader([]);

        $this->assertFalse($reader->exists());
        $this->assertNull($reader->generatedAt());
        $this->assertSame([], $reader->schemas());
        $this->assertSame([], $reader->columns('pub', 'orders'));
        $this->assertNull($reader->rowCount('pub', 'orders'));
        $this->assertFalse($reader->hasSchema('pub'));
    }

    public function testBrokenJsonYieldsEmptyInventory(): void
    {
        $reader = $this->reader([self::INVENTORY_PATH => '{not json']);

        $this->assertSame([], $reader->schemas());
    }
}
