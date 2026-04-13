<?php

namespace Timbrs\DatabaseDumps\Tests\Unit\Config;

use PHPUnit\Framework\TestCase;
use Timbrs\DatabaseDumps\Config\DumpConfig;
use Timbrs\DatabaseDumps\Config\FakerConfig;
use Timbrs\DatabaseDumps\Config\TableConfig;

class DumpConfigTest extends TestCase
{
    /** @var DumpConfig */
    private $config;

    protected function setUp(): void
    {
        $this->config = new DumpConfig(
            [
                'users' => ['users', 'roles'],
                'system' => ['settings']
            ],
            [
                'clients' => [
                    'clients' => [TableConfig::KEY_LIMIT => 100, TableConfig::KEY_ORDER_BY => 'created_at DESC'],
                    'clients_attr' => [TableConfig::KEY_LIMIT => 500]
                ]
            ]
        );
    }

    public function testGetFullExportTables(): void
    {
        $tables = $this->config->getFullExportTables('users');

        $this->assertCount(2, $tables);
        $this->assertContains('users', $tables);
        $this->assertContains('roles', $tables);
    }

    public function testGetFullExportTablesForNonExistentSchema(): void
    {
        $tables = $this->config->getFullExportTables('nonexistent');

        $this->assertEmpty($tables);
    }

    public function testGetPartialExportTables(): void
    {
        $tables = $this->config->getPartialExportTables('clients');

        $this->assertCount(2, $tables);
        $this->assertArrayHasKey('clients', $tables);
        $this->assertArrayHasKey('clients_attr', $tables);
        $this->assertEquals(100, $tables['clients'][TableConfig::KEY_LIMIT]);
    }

    public function testGetAllFullExportSchemas(): void
    {
        $schemas = $this->config->getAllFullExportSchemas();

        $this->assertCount(2, $schemas);
        $this->assertContains('users', $schemas);
        $this->assertContains('system', $schemas);
    }

    public function testGetAllPartialExportSchemas(): void
    {
        $schemas = $this->config->getAllPartialExportSchemas();

        $this->assertCount(1, $schemas);
        $this->assertContains('clients', $schemas);
    }

    public function testGetTableConfigFromPartialExport(): void
    {
        $config = $this->config->getTableConfig('clients', 'clients');

        $this->assertNotNull($config);
        $this->assertEquals(100, $config[TableConfig::KEY_LIMIT]);
        $this->assertEquals('created_at DESC', $config[TableConfig::KEY_ORDER_BY]);
    }

    public function testGetTableConfigFromFullExport(): void
    {
        $config = $this->config->getTableConfig('users', 'users');

        $this->assertNotNull($config);
        $this->assertEmpty($config);
    }

    public function testGetTableConfigForNonExistentTable(): void
    {
        $config = $this->config->getTableConfig('nonexistent', 'table');

        $this->assertNull($config);
    }

    public function testFakerConfigDefaultIsEmpty(): void
    {
        $this->assertTrue($this->config->getFakerConfig()->isEmpty());
    }

    public function testFakerConfigFromConstructor(): void
    {
        $fakerConfig = new FakerConfig([
            'public' => [
                'users' => ['email' => 'email', 'full_name' => 'fio'],
            ],
        ]);

        $config = new DumpConfig(
            ['public' => ['users']],
            [],
            [],
            $fakerConfig
        );

        $this->assertFalse($config->getFakerConfig()->isEmpty());
        $this->assertEquals(
            ['email' => 'email', 'full_name' => 'fio'],
            $config->getFakerConfig()->getTableFaker('public', 'users')
        );
    }

    public function testKeyConstants(): void
    {
        $this->assertEquals('includes', DumpConfig::KEY_INCLUDES);
        $this->assertEquals('faker', DumpConfig::KEY_FAKER);
        $this->assertEquals('settings', DumpConfig::KEY_SETTINGS);
    }

    public function testSettingsDefaults(): void
    {
        $this->assertEquals(1000, $this->config->getBatchSize());
        $this->assertEquals(200, $this->config->getSampleSize());
        $this->assertEquals(10, $this->config->getMaxCascadeDepth());
    }

    public function testSettingsFromConstructor(): void
    {
        $config = new DumpConfig([], [], [], null, [
            'batch_size' => 500,
            'sample_size' => 100,
            'max_cascade_depth' => 5,
        ]);

        $this->assertEquals(500, $config->getBatchSize());
        $this->assertEquals(100, $config->getSampleSize());
        $this->assertEquals(5, $config->getMaxCascadeDepth());
    }

    public function testSettingsPartialOverride(): void
    {
        $config = new DumpConfig([], [], [], null, [
            'batch_size' => 2000,
        ]);

        $this->assertEquals(2000, $config->getBatchSize());
        $this->assertEquals(200, $config->getSampleSize()); // default
        $this->assertEquals(10, $config->getMaxCascadeDepth()); // default
    }

    public function testGetSettings(): void
    {
        $settings = ['batch_size' => 500, 'sample_size' => 100];
        $config = new DumpConfig([], [], [], null, $settings);
        $this->assertEquals($settings, $config->getSettings());
    }
}
