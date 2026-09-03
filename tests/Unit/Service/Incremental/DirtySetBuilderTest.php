<?php

namespace Timbrs\DatabaseDumps\Tests\Unit\Service\Incremental;

use PHPUnit\Framework\TestCase;
use Timbrs\DatabaseDumps\Config\DumpConfig;
use Timbrs\DatabaseDumps\Contract\FileSystemInterface;
use Timbrs\DatabaseDumps\Service\Analysis\Dossier\MigrationScanner;
use Timbrs\DatabaseDumps\Service\Incremental\Checkpoint;
use Timbrs\DatabaseDumps\Service\Incremental\DirtySetBuilder;
use Timbrs\DatabaseDumps\Service\Incremental\MigrationDiffParser;
use Timbrs\DatabaseDumps\Service\Validation\InventoryReader;

class DirtySetBuilderTest extends TestCase
{
    private const INVENTORY_PATH = '/proj/docker/database/analysis/schema_inventory.json';

    /**
     * Отметки нет — это первый прогон, и звать надо полный цикл, а не «ничего не изменилось».
     */
    public function testWithoutCheckpointEverythingIsDirty(): void
    {
        $dirty = $this->builder([])->build(null, $this->config(), $this->inventory());

        $this->assertTrue($dirty['full']);
        $this->assertSame(['public.clients', 'public.orders'], $dirty['tables']);
        $this->assertNull($dirty['checkpoint']);
    }

    public function testUnchangedStateGivesEmptyDirtySet(): void
    {
        $builder = $this->builder([]);
        $dirty = $builder->build($this->checkpoint($builder), $this->config(), $this->inventory());

        $this->assertFalse($dirty['full']);
        $this->assertSame([], $dirty['tables']);
        $this->assertSame(0, $dirty['summary']['dirty']);
        $this->assertSame(2, $dirty['summary']['configured']);
    }

    /**
     * Колонка в этом проекте появляется миграцией — главный сенсор.
     */
    public function testMigrationNewerThanCheckpointMarksItsTables(): void
    {
        $migrations = [
            'Version20240101000000' => "<?php\n\$this->addSql('ALTER TABLE public.orders ADD COLUMN x int');\n",
            'Version20250601000000' => "<?php\n"
                . "public function getDescription(): string { return 'Новый признак у клиента'; }\n"
                . "\$this->addSql('ALTER TABLE public.clients ADD COLUMN colour_id int');\n",
        ];
        $builder = $this->builder($migrations);
        $checkpoint = new Checkpoint([
            'created_at' => '2025-01-01T00:00:00Z',
            'newest_migration' => 'Version20240101000000',
            'tables' => $builder->snapshotHashes($this->config(), $this->inventory()),
        ]);

        $dirty = $builder->build($checkpoint, $this->config(), $this->inventory());

        $this->assertSame(['public.clients'], $dirty['tables']);
        $reasons = $dirty['details']['public.clients']['reasons'];
        $this->assertSame(DirtySetBuilder::SENSOR_MIGRATION, $reasons[0]['sensor']);
        $this->assertStringContainsString('Version20250601000000', $reasons[0]['why']);
        $this->assertSame(1, $dirty['sensors']['migration']['new_versions']);
        $this->assertSame('Version20250601000000', $dirty['sensors']['migration']['newest']);
    }

    /**
     * Миграция завела таблицу, а в конфиг она не попала: перепроверять нечего, но и
     * молчать нельзя — это повод дозапустить prepare-config.
     */
    public function testTableTouchedByMigrationButAbsentFromConfigIsReported(): void
    {
        $builder = $this->builder([
            'Version20250601000000' => "<?php\n\$this->addSql('CREATE TABLE public.newbie (id int)');\n",
        ]);
        $dirty = $builder->build(
            new Checkpoint(['tables' => $builder->snapshotHashes($this->config(), $this->inventory())]),
            $this->config(),
            $this->inventory()
        );

        $this->assertSame([], $dirty['tables']);
        $this->assertSame(['public.newbie'], $dirty['sensors']['migration']['not_in_config']);
    }

    public function testConfigChangeIsDetected(): void
    {
        $builder = $this->builder([]);
        $checkpoint = $this->checkpoint($builder);

        $changed = new DumpConfig([], ['public' => [
            'orders' => ['limit' => 999],
            'clients' => ['limit' => 500],
        ]]);
        $dirty = $builder->build($checkpoint, $changed, $this->inventory());

        $this->assertSame(['public.orders'], $dirty['tables']);
        $this->assertSame(DirtySetBuilder::SENSOR_CONFIG, $dirty['details']['public.orders']['reasons'][0]['sensor']);
        $this->assertSame(1, $dirty['sensors']['config']['changed']);
    }

    /**
     * Переставленные ключи YAML — не изменение: иначе каждый прогон объявлял бы всё грязным.
     */
    public function testReorderedConfigKeysAreNotAChange(): void
    {
        $builder = $this->builder([]);
        $checkpoint = $this->checkpoint($builder);

        $reordered = new DumpConfig([], ['public' => [
            'orders' => ['where' => 'active', 'limit' => 500],
            'clients' => ['limit' => 500],
        ]]);
        $dirty = $builder->build($checkpoint, $reordered, $this->inventory());

        $this->assertSame([], $dirty['tables']);
    }

    public function testNewTableInConfigIsDirty(): void
    {
        $builder = $this->builder([]);
        $checkpoint = new Checkpoint([
            'tables' => ['public.orders' => $builder->snapshotHashes($this->config(), $this->inventory())['public.orders']],
        ]);

        $dirty = $builder->build($checkpoint, $this->config(), $this->inventory());

        $this->assertSame(['public.clients'], $dirty['tables']);
        $this->assertSame(1, $dirty['sensors']['config']['added']);
    }

    /**
     * Новое значение status_id приходит без миграции — его видит только diff слепков.
     */
    public function testNewCodeValueInInventoryIsDetected(): void
    {
        $builder = $this->builder([]);
        $checkpoint = $this->checkpoint($builder);

        $dirty = $builder->build(
            $checkpoint,
            $this->config(),
            $this->inventory(['1', '2', '3'])
        );

        $this->assertSame(['public.orders'], $dirty['tables']);
        $this->assertSame(DirtySetBuilder::SENSOR_INVENTORY, $dirty['details']['public.orders']['reasons'][0]['sensor']);
        $this->assertSame(1, $dirty['sensors']['inventory']['codes_changed']);
    }

    public function testInventorySensorIsSkippedWithoutSnapshot(): void
    {
        $builder = $this->builder([]);
        $missing = new InventoryReader($this->fileSystemWith([]), '/proj/nope.json');

        $dirty = $builder->build($this->checkpoint($builder), $this->config(), $missing);

        $this->assertFalse($dirty['sensors']['inventory']['enabled']);
        $this->assertStringContainsString('слепка нет', $dirty['sensors']['inventory']['why_skipped']);
    }

    /**
     * История хост-проекта схлопнута: сенсор обязан отключиться с объяснением,
     * а не объявить всё грязным и не свалиться.
     */
    public function testGitSensorReportsDisabledOnSquashedHistory(): void
    {
        $builder = new DirtySetBuilder(
            new MigrationDiffParser($this->scanner([])),
            function (string $commit) {
                return null; // истории нет
            }
        );
        $checkpoint = new Checkpoint([
            'head_commit' => 'abcdef1234567890abcdef1234567890abcdef12',
            'tables' => $builder->snapshotHashes($this->config(), $this->inventory()),
        ]);

        $dirty = $builder->build($checkpoint, $this->config(), $this->inventory());

        $this->assertFalse($dirty['sensors']['git']['enabled']);
        $this->assertStringContainsString('история недоступна', $dirty['sensors']['git']['why_skipped']);
        $this->assertSame([], $dirty['tables']);
    }

    public function testGitSensorMapsChangedMigrationFilesToTables(): void
    {
        $migrations = [
            'Version20250601000000' => "<?php\n\$this->addSql('ALTER TABLE public.clients ADD COLUMN c int');\n",
        ];
        $builder = new DirtySetBuilder(
            new MigrationDiffParser($this->scanner($migrations)),
            function (string $commit) {
                return ['migrations/Version20250601000000.php', 'README.md'];
            }
        );
        // Отметка уже знает про эту миграцию — грязь придёт именно от git.
        $checkpoint = new Checkpoint([
            'newest_migration' => 'Version20250601000000',
            'head_commit' => 'abcdef1234567890abcdef1234567890abcdef12',
            'tables' => $builder->snapshotHashes($this->config(), $this->inventory()),
        ]);

        $dirty = $builder->build($checkpoint, $this->config(), $this->inventory());

        $this->assertSame(['public.clients'], $dirty['tables']);
        $this->assertSame(DirtySetBuilder::SENSOR_GIT, $dirty['details']['public.clients']['reasons'][0]['sensor']);
        $this->assertSame(2, $dirty['sensors']['git']['files_changed']);
        $this->assertSame(1, $dirty['sensors']['git']['migrations_changed']);
    }

    public function testSchemaFilterNarrowsConfiguredSet(): void
    {
        $config = new DumpConfig([], [
            'public' => ['orders' => ['limit' => 500]],
            'other' => ['stuff' => ['limit' => 1]],
        ]);
        $dirty = $this->builder([])->build(null, $config, $this->inventory(), ['other']);

        $this->assertSame(['other.stuff'], $dirty['tables']);
    }

    public function testTableListReadsDirtyJson(): void
    {
        $this->assertSame(['a.b'], DirtySetBuilder::tableList(['tables' => ['a.b']]));
        $this->assertSame([], DirtySetBuilder::tableList([]));
    }

    /**
     * @param array<string, string> $migrations версия => содержимое файла
     */
    private function builder(array $migrations): DirtySetBuilder
    {
        return new DirtySetBuilder(new MigrationDiffParser($this->scanner($migrations)));
    }

    private function checkpoint(DirtySetBuilder $builder): Checkpoint
    {
        return new Checkpoint([
            'created_at' => '2025-01-01T00:00:00Z',
            'newest_migration' => null,
            'tables' => $builder->snapshotHashes($this->config(), $this->inventory()),
        ]);
    }

    private function config(): DumpConfig
    {
        return new DumpConfig([], ['public' => [
            'orders' => ['limit' => 500, 'where' => 'active'],
            'clients' => ['limit' => 500],
        ]]);
    }

    /**
     * @param array<int, string> $codes
     */
    private function inventory(array $codes = ['1', '2']): InventoryReader
    {
        $snapshot = [
            'generated_at' => '2025-01-01T00:00:00Z',
            'schemas' => [
                'public' => [
                    'tables' => [
                        'orders' => [
                            'columns' => [
                                ['name' => 'id', 'type' => 'bigint'],
                                ['name' => 'status_id', 'type' => 'integer'],
                            ],
                            'row_count' => 100,
                            'profiles' => [
                                [
                                    'column' => 'status_id',
                                    'distinct_count' => count($codes),
                                    'codes' => $codes,
                                ],
                            ],
                            'foreign_keys' => [],
                        ],
                        'clients' => [
                            'columns' => [['name' => 'id', 'type' => 'bigint']],
                            'row_count' => 10,
                            'profiles' => [],
                            'foreign_keys' => [],
                        ],
                    ],
                ],
            ],
        ];

        return new InventoryReader(
            $this->fileSystemWith([self::INVENTORY_PATH => (string) json_encode($snapshot)]),
            self::INVENTORY_PATH
        );
    }

    /**
     * @param array<string, string> $files
     */
    private function fileSystemWith(array $files): FileSystemInterface
    {
        $fs = $this->createMock(FileSystemInterface::class);
        $fs->method('exists')->willReturnCallback(function ($path) use ($files) {
            return isset($files[$path]);
        });
        $fs->method('read')->willReturnCallback(function ($path) use ($files) {
            return isset($files[$path]) ? $files[$path] : '';
        });

        return $fs;
    }

    /**
     * @param array<string, string> $migrations
     */
    private function scanner(array $migrations): MigrationScanner
    {
        return new class('/proj', $migrations) extends MigrationScanner {
            /** @var array<string, string> */
            private $migrations;

            /**
             * @param array<string, string> $migrations
             */
            public function __construct(string $dir, array $migrations)
            {
                parent::__construct($dir);
                $this->migrations = $migrations;
            }

            protected function files(): array
            {
                $files = [];
                foreach (array_keys($this->migrations) as $version) {
                    $files[] = ['path' => '/proj/migrations/' . $version . '.php', 'name' => $version];
                }

                return $files;
            }

            protected function read(string $path): ?string
            {
                $version = basename($path, '.php');

                return isset($this->migrations[$version]) ? $this->migrations[$version] : null;
            }
        };
    }
}
