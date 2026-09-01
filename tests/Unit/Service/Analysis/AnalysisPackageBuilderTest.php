<?php

namespace Timbrs\DatabaseDumps\Tests\Unit\Service\Analysis;

use PHPUnit\Framework\TestCase;
use Timbrs\DatabaseDumps\Contract\ConnectionRegistryInterface;
use Timbrs\DatabaseDumps\Contract\DatabaseConnectionInterface;
use Timbrs\DatabaseDumps\Contract\FileSystemInterface;
use Timbrs\DatabaseDumps\Contract\LoggerInterface;
use Timbrs\DatabaseDumps\Service\Analysis\AnalysisPackageBuilder;
use Timbrs\DatabaseDumps\Service\Analysis\CodeHintScanner;
use Timbrs\DatabaseDumps\Service\ConfigGenerator\ColumnProfile;
use Timbrs\DatabaseDumps\Service\ConfigGenerator\ColumnStatisticsInspector;
use Timbrs\DatabaseDumps\Service\ConfigGenerator\ServiceTableFilter;
use Timbrs\DatabaseDumps\Service\ConfigGenerator\TableInspector;
use Timbrs\DatabaseDumps\Service\Graph\TableDependencyResolver;

class AnalysisPackageBuilderTest extends TestCase
{
    /** @var array<string, string> */
    private $written = [];

    private function builder(LoggerInterface $logger = null): AnalysisPackageBuilder
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
            $logger ?? $this->createMock(LoggerInterface::class),
            '/proj',
            $this->stubScanner()
        );
    }

    /**
     * Сканер кода с подменёнными enumerateFiles()/readFile() над in-memory картой (как
     * CodeHintScannerTest). Дефолтные файлы упоминают `clients` (entity + usage), чтобы scan()
     * дал хинты по таблице public.clients инвентаря.
     *
     * @param array<string, string>|null $files абсолютный путь => содержимое
     */
    private function stubScanner(array $files = null): CodeHintScanner
    {
        if ($files === null) {
            $files = [
                '/proj/src/Entity/Client.php' => "<?php\nnamespace App\\Entity;\nuse Doctrine\\ORM\\Mapping as ORM;\n"
                    . "#[ORM\\Table(name: 'clients')]\nclass Client\n{\n}\n",
                '/proj/src/Service/ClientService.php' => "<?php\nnamespace App\\Service;\nuse App\\Entity\\Client;\n"
                    . "class ClientService\n{\n    public function make(Client \$c) {}\n}\n",
            ];
        }

        return new class('/proj', $this->createMock(LoggerInterface::class), $files) extends CodeHintScanner {
            /** @var array<string, string> */
            private $files;

            /**
             * @param array<string, string> $files
             */
            public function __construct(string $dir, LoggerInterface $logger, array $files)
            {
                parent::__construct($dir, $logger);
                $this->files = $files;
            }

            protected function enumerateFiles(string $dataDir): array
            {
                return array_keys($this->files);
            }

            protected function readFile(string $path)
            {
                return isset($this->files[$path]) ? $this->files[$path] : false;
            }
        };
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

        $invPath = '/proj/docker/database/analysis/schema_inventory.json';
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
        $this->assertStringContainsString('/proj/docker/database/analysis/schema_inventory.json', $paths);
        $this->assertStringContainsString('/proj/.opencode/agents/dbdump-mapper.md', $paths);
        $this->assertStringContainsString('/proj/docker/database/analysis/RUN.md', $paths);
        $this->assertStringContainsString('/proj/docker/database/analysis/output_schema.json', $paths);
        $this->assertSame(2, $result['tables']);
    }

    /**
     * Шаблоны для OPENCODE ссылаются на каталог анализа как {data_dir}/analysis. Агент получает
     * их как есть, поэтому плейсхолдер обязан раскрыться при записи — иначе агент пишет туда,
     * откуда apply-analysis ничего не читает.
     */
    public function testProvisionedResourcesResolveDataDirPlaceholder(): void
    {
        $this->written = [];
        $this->builder()->build();

        foreach (['/proj/docker/database/analysis/RUN.md',
                  '/proj/.opencode/agents/dbdump-mapper.md',
                  '/proj/.opencode/commands/dbdump-map.md',
                  '/proj/docker/database/analysis/output_schema.json'] as $path) {
            $this->assertArrayHasKey($path, $this->written);
            $this->assertStringNotContainsString('{data_dir}', $this->written[$path], $path);
        }

        $this->assertStringContainsString(
            'docker/database/analysis/out/',
            $this->written['/proj/.opencode/agents/dbdump-mapper.md']
        );
    }

    public function testBuildWritesPerSchemaInventory(): void
    {
        $this->written = [];
        $result = $this->builder()->build();

        // Пер-схемный файл записан и содержит ТОЛЬКО свою схему.
        $perSchema = '/proj/docker/database/analysis/schema_inventory.public.json';
        $this->assertArrayHasKey($perSchema, $this->written);
        $decoded = json_decode($this->written[$perSchema], true);
        $this->assertSame(['public'], array_keys($decoded['schemas']));

        // schema_files в возврате указывает на этот файл (для --run / подсказок).
        $this->assertArrayHasKey('public', $result['schema_files']);
        $this->assertSame($perSchema, $result['schema_files']['public']);

        // PII по-прежнему не утекает в пер-схемный инвентарь.
        $this->assertStringNotContainsString('СЕКРЕТНОЕ_ПД_ЗНАЧЕНИЕ', $this->written[$perSchema]);
    }

    public function testBuildInjectsCodeHintsIntoInventoryFiles(): void
    {
        $this->written = [];
        $this->builder()->build();

        // Монолитный инвентарь несёт code_hints по таблице clients (entity + entity usage).
        $invPath = '/proj/docker/database/analysis/schema_inventory.json';
        $this->assertArrayHasKey($invPath, $this->written);
        $inv = json_decode($this->written[$invPath], true);
        $hints = $inv['schemas']['public']['tables']['clients']['code_hints'];
        $this->assertSame(1, $hints['counts']['entity']);
        $this->assertArrayHasKey('entity usage', $hints['counts']);

        // Пер-схемный файл наследует code_hints.
        $perSchema = '/proj/docker/database/analysis/schema_inventory.public.json';
        $ps = json_decode($this->written[$perSchema], true);
        $this->assertArrayHasKey('code_hints', $ps['schemas']['public']['tables']['clients']);

        // Сниппеты — это код хоста, а НЕ значения данных БД: PII не утекает в файл с подсказками.
        $this->assertStringNotContainsString('СЕКРЕТНОЕ_ПД_ЗНАЧЕНИЕ', $this->written[$invPath]);
        $this->assertStringNotContainsString('top_values', $this->written[$invPath]);
    }

    public function testBuildLogsCodeHintScanStatusAndSummary(): void
    {
        $messages = [];
        $logger = $this->createMock(LoggerInterface::class);
        // Собирающая замыкание-callback (без arrow fn — PHP 7.2 CI).
        $logger->method('info')->willReturnCallback(function ($message) use (&$messages) {
            $messages[] = (string) $message;
        });

        $this->written = [];
        $this->builder($logger)->build();

        $joined = implode("\n", $messages);
        // Итог инвентаризации: сколько проанализировано + выводы (категориальные/FK).
        $this->assertStringContainsString('Инвентарь собран:', $joined);
        $this->assertStringContainsString('2 таблиц', $joined);
        $this->assertStringContainsString('категориальных', $joined);
        // Статус старта скана кода + итоговая сводка подсказок.
        $this->assertStringContainsString('Скан кода хоста', $joined);
        $this->assertStringContainsString('Подсказки по коду:', $joined);

        // Пер-табличная строка: ключ таблицы + категория.
        $perTable = null;
        foreach ($messages as $m) {
            if (strpos($m, 'public.clients') !== false && strpos($m, 'entity') !== false) {
                $perTable = $m;
                break;
            }
        }
        $this->assertNotNull($perTable, 'Ожидалась пер-табличная строка подсказок с public.clients и категорией');
    }
}
