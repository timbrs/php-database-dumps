<?php

namespace Timbrs\DatabaseDumps\Tests\Unit\Config;

use PHPUnit\Framework\TestCase;
use Timbrs\DatabaseDumps\Config\DumpConfig;
use Timbrs\DatabaseDumps\Config\TableConfig;

class DumpConfigMultiConnectionTest extends TestCase
{
    /** @var DumpConfig */
    private $config;

    protected function setUp(): void
    {
        $mysqlConfig = new DumpConfig(
            ['app_db' => ['events', 'metrics']],
            ['app_db' => ['logs' => [TableConfig::KEY_LIMIT => 1000, TableConfig::KEY_ORDER_BY => 'created_at DESC']]]
        );

        $this->config = new DumpConfig(
            ['public' => ['users', 'roles']],
            ['public' => ['clients' => [TableConfig::KEY_LIMIT => 100]]],
            ['mysql' => $mysqlConfig]
        );
    }

    public function testIsMultiConnection(): void
    {
        $this->assertTrue($this->config->isMultiConnection());
    }

    public function testIsNotMultiConnectionWhenEmpty(): void
    {
        $simple = new DumpConfig(['public' => ['users']], []);
        $this->assertFalse($simple->isMultiConnection());
    }

    public function testGetConnectionConfigs(): void
    {
        $configs = $this->config->getConnectionConfigs();
        $this->assertCount(1, $configs);
        $this->assertArrayHasKey('mysql', $configs);
    }

    public function testGetConnectionConfig(): void
    {
        $mysqlConfig = $this->config->getConnectionConfig('mysql');
        $this->assertNotNull($mysqlConfig);
        $this->assertCount(2, $mysqlConfig->getFullExportTables('app_db'));
        $this->assertContains('events', $mysqlConfig->getFullExportTables('app_db'));
    }

    public function testGetConnectionConfigReturnsNullForUnknown(): void
    {
        $this->assertNull($this->config->getConnectionConfig('nonexistent'));
    }

    public function testDefaultConfigUnchanged(): void
    {
        $tables = $this->config->getFullExportTables('public');
        $this->assertCount(2, $tables);
        $this->assertContains('users', $tables);
        $this->assertContains('roles', $tables);
    }

    public function testBackwardsCompatibility(): void
    {
        // DumpConfig без connections
        $simple = new DumpConfig(
            ['public' => ['users']],
            ['public' => ['clients' => [TableConfig::KEY_LIMIT => 50]]]
        );

        $this->assertFalse($simple->isMultiConnection());
        $this->assertEmpty($simple->getConnectionConfigs());
        $this->assertNull($simple->getConnectionConfig('anything'));
        $this->assertEquals(['users'], $simple->getFullExportTables('public'));
    }
}
