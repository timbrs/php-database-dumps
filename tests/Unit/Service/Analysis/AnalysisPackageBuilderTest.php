<?php

namespace Timbrs\DatabaseDumps\Tests\Unit\Service\Analysis;

use PHPUnit\Framework\TestCase;
use Timbrs\DatabaseDumps\Contract\ConnectionRegistryInterface;
use Timbrs\DatabaseDumps\Contract\DatabaseConnectionInterface;
use Timbrs\DatabaseDumps\Contract\FileSystemInterface;
use Timbrs\DatabaseDumps\Contract\LoggerInterface;
use Timbrs\DatabaseDumps\Service\Analysis\AnalysisPackageBuilder;
use Timbrs\DatabaseDumps\Service\ConfigGenerator\ColumnProfile;
use Timbrs\DatabaseDumps\Service\ConfigGenerator\ColumnStatisticsInspector;
use Timbrs\DatabaseDumps\Service\ConfigGenerator\ServiceTableFilter;
use Timbrs\DatabaseDumps\Service\ConfigGenerator\TableInspector;
use Timbrs\DatabaseDumps\Service\Graph\TableDependencyResolver;

class AnalysisPackageBuilderTest extends TestCase
{
    /** @var array<string, string> */
    private $written = [];

    private function builder(): AnalysisPackageBuilder
    {
        $connection = $this->createMock(DatabaseConnectionInterface::class);
        $connection->method('getPlatformName')->willReturn('postgresql');

        $registry = $this->createMock(ConnectionRegistryInterface::class);
        $registry->method('getConnection')->willReturn($connection);

        $inspector = $this->createMock(TableInspector::class);
        $inspector->method('listTables')->willReturn([
            ['table_schema' => 'public', 'table_name' => 'clients'],
            ['table_schema' => 'public', 'table_name' => 'orders'],
        ]);
        $inspector->method('countRows')->willReturn(1000);

        $filter = $this->createMock(ServiceTableFilter::class);
        $filter->method('shouldIgnore')->willReturn(false);

        $resolver = $this->createMock(TableDependencyResolver::class);
        $resolver->method('getDependencyGraph')->willReturn([
            'public.orders' => [
                'public.clients' => ['source_column' => 'client_id', 'target_column' => 'id'],
            ],
        ]);

        $stats = $this->createMock(ColumnStatisticsInspector::class);
        $stats->method('profileTable')->willReturnCallback(function ($schema, $table) {
            return [
                new ColumnProfile(
                    'status',
                    'varchar',
                    false,
                    0.0,
                    3,
                    false,
                    [['value' => 'СЕКРЕТНОЕ_ПД_ЗНАЧЕНИЕ', 'count' => 5]],
                    true
                ),
            ];
        });

        $fs = $this->createMock(FileSystemInterface::class);
        $fs->method('exists')->willReturn(true);
        $fs->method('write')->willReturnCallback(function ($path, $content) {
            $this->written[$path] = $content;
        });

        return new AnalysisPackageBuilder(
            $fs,
            $registry,
            $inspector,
            $filter,
            $resolver,
            $stats,
            $this->createMock(LoggerInterface::class),
            '/proj'
        );
    }

    public function testInventoryExcludesPiiValues(): void
    {
        $inventory = $this->builder()->buildInventory();

        $json = (string) json_encode($inventory);
        // top_values (значения данных) НЕ должны попадать в инвентарь для OPENCODE.
        $this->assertStringNotContainsString('СЕКРЕТНОЕ_ПД_ЗНАЧЕНИЕ', $json);
        $this->assertStringNotContainsString('top_values', $json);

        $clients = $inventory['schemas']['public']['tables']['clients'];
        $this->assertSame(1000, $clients['row_count']);
        $this->assertSame('status', $clients['columns'][0]['name']);
        $this->assertTrue($clients['profiles'][0]['categorical']);
    }

    /**
     * Профиль построен по whitelist — содержит ТОЛЬКО безопасные метаданные.
     * Это ключевая гарантия: ни одно value-несущее поле не утекает в OPENCODE.
     */
    public function testSafeProfileContainsOnlyMetadataKeys(): void
    {
        $inventory = $this->builder()->buildInventory();
        $profile = $inventory['schemas']['public']['tables']['clients']['profiles'][0];

        $allowed = ['column', 'data_type', 'nullable', 'null_fraction', 'distinct_count', 'distinct_capped', 'categorical'];
        foreach (array_keys($profile) as $key) {
            $this->assertContains($key, $allowed, "Профиль содержит небезопасный ключ: {$key}");
        }
        // Значения данных отсутствуют.
        $this->assertArrayNotHasKey('top_values', $profile);
        $this->assertArrayNotHasKey('values', $profile);
    }

    /**
     * Гарантия PII должна держаться и на уровне записанного на диск файла инвентаря
     * (а не только в возвращаемом массиве buildInventory()).
     */
    public function testWrittenInventoryFileContainsNoDataValues(): void
    {
        $this->written = [];
        $this->builder()->build();

        $invPath = '/proj/database/analysis/schema_inventory.json';
        $this->assertArrayHasKey($invPath, $this->written);
        $content = $this->written[$invPath];
        $this->assertStringNotContainsString('СЕКРЕТНОЕ_ПД_ЗНАЧЕНИЕ', $content);
        $this->assertStringNotContainsString('top_values', $content);
        // Записанный JSON валиден.
        $this->assertIsArray(json_decode($content, true));
    }

    public function testInventoryIncludesForeignKeysFromGraph(): void
    {
        $inventory = $this->builder()->buildInventory();
        $orders = $inventory['schemas']['public']['tables']['orders'];
        $this->assertCount(1, $orders['foreign_keys']);
        $this->assertSame('client_id', $orders['foreign_keys'][0]['column']);
        $this->assertSame('public.clients', $orders['foreign_keys'][0]['references_table']);
    }

    public function testBuildProvisionsAgentAndInventoryFiles(): void
    {
        $this->written = [];
        $result = $this->builder()->build();

        $paths = implode("\n", array_keys($this->written));
        $this->assertStringContainsString('/proj/database/analysis/schema_inventory.json', $paths);
        $this->assertStringContainsString('/proj/.opencode/agents/dbdump-mapper.md', $paths);
        $this->assertStringContainsString('/proj/database/analysis/RUN.md', $paths);
        $this->assertStringContainsString('/proj/database/analysis/output_schema.json', $paths);
        $this->assertSame(2, $result['tables']);
    }

    public function testBuildWritesPerSchemaInventory(): void
    {
        $this->written = [];
        $result = $this->builder()->build();

        // Пер-схемный файл записан и содержит ТОЛЬКО свою схему.
        $perSchema = '/proj/database/analysis/schema_inventory.public.json';
        $this->assertArrayHasKey($perSchema, $this->written);
        $decoded = json_decode($this->written[$perSchema], true);
        $this->assertSame(['public'], array_keys($decoded['schemas']));

        // schema_files в возврате указывает на этот файл (для --run / подсказок).
        $this->assertArrayHasKey('public', $result['schema_files']);
        $this->assertSame($perSchema, $result['schema_files']['public']);

        // PII по-прежнему не утекает в пер-схемный инвентарь.
        $this->assertStringNotContainsString('СЕКРЕТНОЕ_ПД_ЗНАЧЕНИЕ', $this->written[$perSchema]);
    }
}
