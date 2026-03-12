<?php

namespace Timbrs\DatabaseDumps\Tests\Unit\Service\ConfigGenerator;

use Timbrs\DatabaseDumps\Config\DumpConfig;
use Timbrs\DatabaseDumps\Config\TableConfig;
use Timbrs\DatabaseDumps\Contract\ConnectionRegistryInterface;
use Timbrs\DatabaseDumps\Contract\FileSystemInterface;
use Timbrs\DatabaseDumps\Contract\LoggerInterface;
use Timbrs\DatabaseDumps\Service\ConfigGenerator\ConfigGenerator;
use Timbrs\DatabaseDumps\Service\ConfigGenerator\ConfigSplitter;
use Timbrs\DatabaseDumps\Service\ConfigGenerator\ServiceTableFilter;
use Timbrs\DatabaseDumps\Service\ConfigGenerator\TableInspector;
use Timbrs\DatabaseDumps\Service\Faker\PatternDetector;
use Timbrs\DatabaseDumps\Service\Graph\TableDependencyResolver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

class ConfigGeneratorTest extends TestCase
{
    /** @var TableInspector&\PHPUnit\Framework\MockObject\MockObject */
    private $inspector;

    /** @var ServiceTableFilter&\PHPUnit\Framework\MockObject\MockObject */
    private $filter;

    /** @var FileSystemInterface&\PHPUnit\Framework\MockObject\MockObject */
    private $fileSystem;

    /** @var LoggerInterface&\PHPUnit\Framework\MockObject\MockObject */
    private $logger;

    /** @var ConnectionRegistryInterface&\PHPUnit\Framework\MockObject\MockObject */
    private $registry;

    /** @var TableDependencyResolver&\PHPUnit\Framework\MockObject\MockObject */
    private $dependencyResolver;

    /** @var ConfigSplitter&\PHPUnit\Framework\MockObject\MockObject */
    private $configSplitter;

    /** @var PatternDetector&\PHPUnit\Framework\MockObject\MockObject */
    private $patternDetector;

    /** @var ConfigGenerator */
    private $generator;

    protected function setUp(): void
    {
        $this->inspector = $this->createMock(TableInspector::class);
        $this->filter = $this->createMock(ServiceTableFilter::class);
        $this->fileSystem = $this->createMock(FileSystemInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->registry = $this->createMock(ConnectionRegistryInterface::class);
        $this->registry->method('getDefaultName')->willReturn('default');
        $this->registry->method('getNames')->willReturn(['default']);

        $this->dependencyResolver = $this->createMock(TableDependencyResolver::class);
        $this->dependencyResolver->method('getDependencyGraph')->willReturn([]);

        $this->configSplitter = $this->createMock(ConfigSplitter::class);

        $this->patternDetector = $this->createMock(PatternDetector::class);
        $this->patternDetector->method('detect')->willReturn([]);

        // По умолчанию: cascade=true, faker=true, splitBySchema=false (для обратной совместимости тестов)
        $this->generator = new ConfigGenerator(
            $this->inspector,
            $this->filter,
            $this->fileSystem,
            $this->logger,
            $this->registry,
            $this->dependencyResolver,
            $this->configSplitter,
            $this->patternDetector,
            true,
            true,
            false
        );
    }

    public function testGenerateFullExportTables(): void
    {
        $this->inspector->method('listTables')->willReturn([
            ['table_schema' => 'public', 'table_name' => 'roles'],
        ]);

        $this->filter->method('shouldIgnore')->willReturn(false);
        $this->inspector->method('countRows')->willReturn(50);

        /** @var string $writtenContent */
        $writtenContent = '';
        $this->fileSystem
            ->expects($this->once())
            ->method('write')
            ->willReturnCallback(function ($path, $content) use (&$writtenContent) {
                $writtenContent = $content;
            });

        $stats = $this->generator->generate('/tmp/dump_config.yaml', 500);

        $this->assertEquals(1, $stats['full']);
        $this->assertEquals(0, $stats['partial']);
        $this->assertEquals(0, $stats['skipped']);
        $this->assertEquals(0, $stats['empty']);

        $parsed = Yaml::parse($writtenContent);
        $this->assertArrayHasKey(DumpConfig::KEY_FULL_EXPORT, $parsed);
        $this->assertContains('roles', $parsed[DumpConfig::KEY_FULL_EXPORT]['public']);
    }

    public function testGeneratePartialExportTables(): void
    {
        $this->inspector->method('listTables')->willReturn([
            ['table_schema' => 'public', 'table_name' => 'orders'],
        ]);

        $this->filter->method('shouldIgnore')->willReturn(false);
        $this->inspector->method('countRows')->willReturn(10000);
        $this->inspector->method('detectOrderColumn')->willReturn('created_at DESC');

        /** @var string $writtenContent */
        $writtenContent = '';
        $this->fileSystem
            ->expects($this->once())
            ->method('write')
            ->willReturnCallback(function ($path, $content) use (&$writtenContent) {
                $writtenContent = $content;
            });

        $stats = $this->generator->generate('/tmp/dump_config.yaml', 500);

        $this->assertEquals(0, $stats['full']);
        $this->assertEquals(1, $stats['partial']);

        $parsed = Yaml::parse($writtenContent);
        $this->assertArrayHasKey(DumpConfig::KEY_PARTIAL_EXPORT, $parsed);
        $this->assertEquals(500, $parsed[DumpConfig::KEY_PARTIAL_EXPORT]['public']['orders'][TableConfig::KEY_LIMIT]);
        $this->assertEquals('created_at DESC', $parsed[DumpConfig::KEY_PARTIAL_EXPORT]['public']['orders'][TableConfig::KEY_ORDER_BY]);

        $this->assertEquals('1=1', $parsed[DumpConfig::KEY_PARTIAL_EXPORT]['public']['orders'][TableConfig::KEY_WHERE]);
    }

    public function testGenerateSkipsServiceTables(): void
    {
        $this->inspector->method('listTables')->willReturn([
            ['table_schema' => 'public', 'table_name' => 'migrations'],
        ]);

        $this->filter->method('shouldIgnore')->willReturn(true);

        $this->fileSystem->expects($this->once())->method('write');

        $stats = $this->generator->generate('/tmp/dump_config.yaml');

        $this->assertEquals(0, $stats['full']);
        $this->assertEquals(0, $stats['partial']);
        $this->assertEquals(1, $stats['skipped']);
    }

    public function testGenerateSkipsEmptyTables(): void
    {
        $this->inspector->method('listTables')->willReturn([
            ['table_schema' => 'public', 'table_name' => 'empty_table'],
        ]);

        $this->filter->method('shouldIgnore')->willReturn(false);
        $this->inspector->method('countRows')->willReturn(0);

        $this->fileSystem->expects($this->once())->method('write');

        $stats = $this->generator->generate('/tmp/dump_config.yaml');

        $this->assertEquals(0, $stats['full']);
        $this->assertEquals(0, $stats['partial']);
        $this->assertEquals(0, $stats['skipped']);
        $this->assertEquals(1, $stats['empty']);
    }

    public function testGenerateThresholdBoundary(): void
    {
        $this->inspector->method('listTables')->willReturn([
            ['table_schema' => 'public', 'table_name' => 'exact_threshold'],
            ['table_schema' => 'public', 'table_name' => 'over_threshold'],
        ]);

        $this->filter->method('shouldIgnore')->willReturn(false);
        $this->inspector->method('countRows')->willReturnMap([
            ['public', 'exact_threshold', null, 500],
            ['public', 'over_threshold', null, 501],
        ]);
        $this->inspector->method('detectOrderColumn')->willReturn('id DESC');

        $this->fileSystem->expects($this->once())->method('write');

        $stats = $this->generator->generate('/tmp/dump_config.yaml', 500);

        $this->assertEquals(1, $stats['full']);
        $this->assertEquals(1, $stats['partial']);
    }

    public function testGenerateMixedTables(): void
    {
        $this->inspector->method('listTables')->willReturn([
            ['table_schema' => 'public', 'table_name' => 'users'],
            ['table_schema' => 'public', 'table_name' => 'migrations'],
            ['table_schema' => 'public', 'table_name' => 'orders'],
            ['table_schema' => 'public', 'table_name' => 'empty_table'],
        ]);

        $this->filter->method('shouldIgnore')->willReturnMap([
            ['users', false],
            ['migrations', true],
            ['orders', false],
            ['empty_table', false],
        ]);

        $this->inspector->method('countRows')->willReturnMap([
            ['public', 'users', null, 100],
            ['public', 'orders', null, 5000],
            ['public', 'empty_table', null, 0],
        ]);

        $this->inspector->method('detectOrderColumn')->willReturn('updated_at DESC');

        /** @var string $writtenContent */
        $writtenContent = '';
        $this->fileSystem
            ->expects($this->once())
            ->method('write')
            ->willReturnCallback(function ($path, $content) use (&$writtenContent) {
                $writtenContent = $content;
            });

        $stats = $this->generator->generate('/tmp/dump_config.yaml', 500);

        $this->assertEquals(1, $stats['full']);
        $this->assertEquals(1, $stats['partial']);
        $this->assertEquals(1, $stats['skipped']);
        $this->assertEquals(1, $stats['empty']);

        $parsed = Yaml::parse($writtenContent);
        $this->assertContains('users', $parsed[DumpConfig::KEY_FULL_EXPORT]['public']);
        $this->assertArrayHasKey('orders', $parsed[DumpConfig::KEY_PARTIAL_EXPORT]['public']);
    }

    public function testGenerateMultipleSchemas(): void
    {
        $this->inspector->method('listTables')->willReturn([
            ['table_schema' => 'public', 'table_name' => 'users'],
            ['table_schema' => 'billing', 'table_name' => 'invoices'],
        ]);

        $this->filter->method('shouldIgnore')->willReturn(false);
        $this->inspector->method('countRows')->willReturn(100);

        /** @var string $writtenContent */
        $writtenContent = '';
        $this->fileSystem
            ->expects($this->once())
            ->method('write')
            ->willReturnCallback(function ($path, $content) use (&$writtenContent) {
                $writtenContent = $content;
            });

        $stats = $this->generator->generate('/tmp/dump_config.yaml', 500);

        $this->assertEquals(2, $stats['full']);

        $parsed = Yaml::parse($writtenContent);
        $this->assertContains('users', $parsed[DumpConfig::KEY_FULL_EXPORT]['public']);
        $this->assertContains('invoices', $parsed[DumpConfig::KEY_FULL_EXPORT]['billing']);
    }

    public function testGenerateWritesToCorrectPath(): void
    {
        $this->inspector->method('listTables')->willReturn([]);

        $this->fileSystem
            ->expects($this->once())
            ->method('write')
            ->with('/my/custom/path/dump_config.yaml', $this->anything());

        $this->generator->generate('/my/custom/path/dump_config.yaml');
    }

    public function testGenerateWithCascadeFromDetection(): void
    {
        // orders (partial) -> users (partial): orders.user_id -> users.id
        $this->inspector->method('listTables')->willReturn([
            ['table_schema' => 'public', 'table_name' => 'users'],
            ['table_schema' => 'public', 'table_name' => 'orders'],
        ]);

        $this->filter->method('shouldIgnore')->willReturn(false);
        $this->inspector->method('countRows')->willReturn(10000);
        $this->inspector->method('detectOrderColumn')->willReturn('id DESC');

        // FK граф: orders зависит от users
        $this->dependencyResolver = $this->createMock(TableDependencyResolver::class);
        $this->dependencyResolver->method('getDependencyGraph')->willReturn([
            'public.orders' => [
                'public.users' => [
                    'source_column' => 'user_id',
                    'target_column' => 'id',
                ],
            ],
        ]);

        $this->patternDetector = $this->createMock(PatternDetector::class);
        $this->patternDetector->method('detect')->willReturn([]);

        $generator = new ConfigGenerator(
            $this->inspector,
            $this->filter,
            $this->fileSystem,
            $this->logger,
            $this->registry,
            $this->dependencyResolver,
            $this->configSplitter,
            $this->patternDetector,
            true,  // cascadeEnabled
            false, // fakerEnabled
            false  // splitBySchema
        );

        /** @var string $writtenContent */
        $writtenContent = '';
        $this->fileSystem
            ->expects($this->once())
            ->method('write')
            ->willReturnCallback(function ($path, $content) use (&$writtenContent) {
                $writtenContent = $content;
            });

        $generator->generate('/tmp/dump_config.yaml', 500);

        $parsed = Yaml::parse($writtenContent);
        $this->assertArrayHasKey(DumpConfig::KEY_PARTIAL_EXPORT, $parsed);
        $ordersConfig = $parsed[DumpConfig::KEY_PARTIAL_EXPORT]['public']['orders'];
        $this->assertArrayHasKey(TableConfig::KEY_CASCADE_FROM, $ordersConfig);
        $this->assertCount(1, $ordersConfig[TableConfig::KEY_CASCADE_FROM]);
        $this->assertEquals('public.users', $ordersConfig[TableConfig::KEY_CASCADE_FROM][0]['parent']);
        $this->assertEquals('user_id', $ordersConfig[TableConfig::KEY_CASCADE_FROM][0]['fk_column']);
        $this->assertEquals('id', $ordersConfig[TableConfig::KEY_CASCADE_FROM][0]['parent_column']);
    }

    public function testGenerateWithCascadeDisabled(): void
    {
        $this->inspector->method('listTables')->willReturn([
            ['table_schema' => 'public', 'table_name' => 'users'],
            ['table_schema' => 'public', 'table_name' => 'orders'],
        ]);

        $this->filter->method('shouldIgnore')->willReturn(false);
        $this->inspector->method('countRows')->willReturn(10000);
        $this->inspector->method('detectOrderColumn')->willReturn('id DESC');

        // FK граф: orders зависит от users, но cascade отключён
        $this->dependencyResolver = $this->createMock(TableDependencyResolver::class);
        // getDependencyGraph не должен вызываться
        $this->dependencyResolver->expects($this->never())->method('getDependencyGraph');

        $this->patternDetector = $this->createMock(PatternDetector::class);
        $this->patternDetector->method('detect')->willReturn([]);

        $generator = new ConfigGenerator(
            $this->inspector,
            $this->filter,
            $this->fileSystem,
            $this->logger,
            $this->registry,
            $this->dependencyResolver,
            $this->configSplitter,
            $this->patternDetector,
            false, // cascadeEnabled
            false, // fakerEnabled
            false  // splitBySchema
        );

        /** @var string $writtenContent */
        $writtenContent = '';
        $this->fileSystem
            ->expects($this->once())
            ->method('write')
            ->willReturnCallback(function ($path, $content) use (&$writtenContent) {
                $writtenContent = $content;
            });

        $generator->generate('/tmp/dump_config.yaml', 500);

        $parsed = Yaml::parse($writtenContent);
        $this->assertArrayHasKey(DumpConfig::KEY_PARTIAL_EXPORT, $parsed);

        // Ни одна таблица не должна иметь cascade_from
        foreach ($parsed[DumpConfig::KEY_PARTIAL_EXPORT] as $schema => $tables) {
            foreach ($tables as $table => $config) {
                $this->assertArrayNotHasKey(TableConfig::KEY_CASCADE_FROM, $config);
            }
        }
    }

    public function testGenerateWithFakerDetection(): void
    {
        $this->inspector->method('listTables')->willReturn([
            ['table_schema' => 'public', 'table_name' => 'users'],
        ]);

        $this->filter->method('shouldIgnore')->willReturn(false);
        $this->inspector->method('countRows')->willReturn(100);

        $this->patternDetector = $this->createMock(PatternDetector::class);
        $this->patternDetector->method('detect')->willReturn([
            'email' => PatternDetector::PATTERN_EMAIL,
            'full_name' => PatternDetector::PATTERN_FIO,
        ]);

        $generator = new ConfigGenerator(
            $this->inspector,
            $this->filter,
            $this->fileSystem,
            $this->logger,
            $this->registry,
            $this->dependencyResolver,
            $this->configSplitter,
            $this->patternDetector,
            false, // cascadeEnabled
            true,  // fakerEnabled
            false  // splitBySchema
        );

        /** @var string $writtenContent */
        $writtenContent = '';
        $this->fileSystem
            ->expects($this->once())
            ->method('write')
            ->willReturnCallback(function ($path, $content) use (&$writtenContent) {
                $writtenContent = $content;
            });

        $generator->generate('/tmp/dump_config.yaml', 500);

        $parsed = Yaml::parse($writtenContent);
        $this->assertArrayHasKey(DumpConfig::KEY_FAKER, $parsed);
        $this->assertArrayHasKey('public', $parsed[DumpConfig::KEY_FAKER]);
        $this->assertArrayHasKey('users', $parsed[DumpConfig::KEY_FAKER]['public']);
        $this->assertEquals(PatternDetector::PATTERN_EMAIL, $parsed[DumpConfig::KEY_FAKER]['public']['users']['email']);
        $this->assertEquals(PatternDetector::PATTERN_FIO, $parsed[DumpConfig::KEY_FAKER]['public']['users']['full_name']);
    }

    public function testGenerateWithFakerDisabled(): void
    {
        $this->inspector->method('listTables')->willReturn([
            ['table_schema' => 'public', 'table_name' => 'users'],
        ]);

        $this->filter->method('shouldIgnore')->willReturn(false);
        $this->inspector->method('countRows')->willReturn(100);

        $this->patternDetector = $this->createMock(PatternDetector::class);
        // detect не должен вызываться
        $this->patternDetector->expects($this->never())->method('detect');

        $generator = new ConfigGenerator(
            $this->inspector,
            $this->filter,
            $this->fileSystem,
            $this->logger,
            $this->registry,
            $this->dependencyResolver,
            $this->configSplitter,
            $this->patternDetector,
            false, // cascadeEnabled
            false, // fakerEnabled
            false  // splitBySchema
        );

        /** @var string $writtenContent */
        $writtenContent = '';
        $this->fileSystem
            ->expects($this->once())
            ->method('write')
            ->willReturnCallback(function ($path, $content) use (&$writtenContent) {
                $writtenContent = $content;
            });

        $generator->generate('/tmp/dump_config.yaml', 500);

        $parsed = Yaml::parse($writtenContent);
        $this->assertArrayNotHasKey(DumpConfig::KEY_FAKER, $parsed);
    }

    public function testGenerateWithSplitBySchema(): void
    {
        $this->inspector->method('listTables')->willReturn([
            ['table_schema' => 'public', 'table_name' => 'users'],
        ]);

        $this->filter->method('shouldIgnore')->willReturn(false);
        $this->inspector->method('countRows')->willReturn(100);

        $generator = new ConfigGenerator(
            $this->inspector,
            $this->filter,
            $this->fileSystem,
            $this->logger,
            $this->registry,
            $this->dependencyResolver,
            $this->configSplitter,
            $this->patternDetector,
            false, // cascadeEnabled
            false, // fakerEnabled
            true   // splitBySchema
        );

        // ConfigSplitter::split должен быть вызван
        $this->configSplitter
            ->expects($this->once())
            ->method('split')
            ->with('/tmp/dump_config.yaml', $this->anything());

        // fileSystem::write НЕ должен быть вызван
        $this->fileSystem
            ->expects($this->never())
            ->method('write');

        $generator->generate('/tmp/dump_config.yaml', 500);
    }

    public function testGenerateWithoutSplit(): void
    {
        $this->inspector->method('listTables')->willReturn([
            ['table_schema' => 'public', 'table_name' => 'users'],
        ]);

        $this->filter->method('shouldIgnore')->willReturn(false);
        $this->inspector->method('countRows')->willReturn(100);

        $generator = new ConfigGenerator(
            $this->inspector,
            $this->filter,
            $this->fileSystem,
            $this->logger,
            $this->registry,
            $this->dependencyResolver,
            $this->configSplitter,
            $this->patternDetector,
            false, // cascadeEnabled
            false, // fakerEnabled
            false  // splitBySchema
        );

        // fileSystem::write должен быть вызван
        $this->fileSystem
            ->expects($this->once())
            ->method('write');

        // ConfigSplitter::split НЕ должен быть вызван
        $this->configSplitter
            ->expects($this->never())
            ->method('split');

        $generator->generate('/tmp/dump_config.yaml', 500);
    }

    public function testGenerateModeAllUnchanged(): void
    {
        $this->inspector->method('listTables')->willReturn([
            ['table_schema' => 'public', 'table_name' => 'users'],
        ]);

        $this->filter->method('shouldIgnore')->willReturn(false);
        $this->inspector->method('countRows')->willReturn(100);

        /** @var string $writtenContent */
        $writtenContent = '';
        $this->fileSystem
            ->expects($this->once())
            ->method('write')
            ->willReturnCallback(function ($path, $content) use (&$writtenContent) {
                $writtenContent = $content;
            });

        $this->generator->setMode(ConfigGenerator::MODE_ALL);
        $stats = $this->generator->generate('/tmp/dump_config.yaml', 500);

        $this->assertEquals(1, $stats['full']);
        $parsed = Yaml::parse($writtenContent);
        $this->assertContains('users', $parsed[DumpConfig::KEY_FULL_EXPORT]['public']);
    }

    public function testGenerateModeSchema(): void
    {
        $this->inspector->method('listTables')->willReturn([
            ['table_schema' => 'public', 'table_name' => 'users'],
            ['table_schema' => 'billing', 'table_name' => 'invoices'],
            ['table_schema' => 'billing', 'table_name' => 'payments'],
        ]);

        $this->filter->method('shouldIgnore')->willReturn(false);
        $this->inspector->method('countRows')->willReturn(100);

        // Файл конфига не существует
        $this->fileSystem->method('exists')->willReturn(false);

        /** @var string $writtenContent */
        $writtenContent = '';
        $this->fileSystem
            ->expects($this->once())
            ->method('write')
            ->willReturnCallback(function ($path, $content) use (&$writtenContent) {
                $writtenContent = $content;
            });

        $this->generator->setMode(ConfigGenerator::MODE_SCHEMA, 'billing');
        $stats = $this->generator->generate('/tmp/dump_config.yaml', 500);

        $this->assertEquals(2, $stats['full']);
        $parsed = Yaml::parse($writtenContent);
        $this->assertArrayHasKey('billing', $parsed[DumpConfig::KEY_FULL_EXPORT]);
        $this->assertContains('invoices', $parsed[DumpConfig::KEY_FULL_EXPORT]['billing']);
        $this->assertContains('payments', $parsed[DumpConfig::KEY_FULL_EXPORT]['billing']);
        $this->assertArrayNotHasKey('public', $parsed[DumpConfig::KEY_FULL_EXPORT]);
    }

    public function testGenerateModeTable(): void
    {
        $this->inspector->method('listTables')->willReturn([
            ['table_schema' => 'public', 'table_name' => 'users'],
            ['table_schema' => 'public', 'table_name' => 'orders'],
        ]);

        $this->filter->method('shouldIgnore')->willReturn(false);
        $this->inspector->method('countRows')->willReturn(100);

        // Файл конфига не существует
        $this->fileSystem->method('exists')->willReturn(false);

        /** @var string $writtenContent */
        $writtenContent = '';
        $this->fileSystem
            ->expects($this->once())
            ->method('write')
            ->willReturnCallback(function ($path, $content) use (&$writtenContent) {
                $writtenContent = $content;
            });

        $this->generator->setMode(ConfigGenerator::MODE_TABLE, 'public.users');
        $stats = $this->generator->generate('/tmp/dump_config.yaml', 500);

        $this->assertEquals(1, $stats['full']);
        $parsed = Yaml::parse($writtenContent);
        $this->assertContains('users', $parsed[DumpConfig::KEY_FULL_EXPORT]['public']);
        $this->assertCount(1, $parsed[DumpConfig::KEY_FULL_EXPORT]['public']);
    }

    public function testGenerateModeNew(): void
    {
        $this->inspector->method('listTables')->willReturn([
            ['table_schema' => 'public', 'table_name' => 'users'],
            ['table_schema' => 'public', 'table_name' => 'orders'],
            ['table_schema' => 'public', 'table_name' => 'new_table'],
        ]);

        $this->filter->method('shouldIgnore')->willReturn(false);
        $this->inspector->method('countRows')->willReturn(100);

        // Существующий конфиг с users и orders
        $existingYaml = Yaml::dump([
            DumpConfig::KEY_FULL_EXPORT => [
                'public' => ['users', 'orders'],
            ],
        ]);
        $this->fileSystem->method('exists')->willReturn(true);
        $this->fileSystem->method('read')->willReturn($existingYaml);

        /** @var string $writtenContent */
        $writtenContent = '';
        $this->fileSystem
            ->expects($this->once())
            ->method('write')
            ->willReturnCallback(function ($path, $content) use (&$writtenContent) {
                $writtenContent = $content;
            });

        $this->generator->setMode(ConfigGenerator::MODE_NEW);
        $stats = $this->generator->generate('/tmp/dump_config.yaml', 500);

        // Только new_table сгенерирована, users и orders пропущены
        $this->assertEquals(1, $stats['full']);
        $this->assertEquals(2, $stats['skipped']);

        $parsed = Yaml::parse($writtenContent);
        $this->assertContains('users', $parsed[DumpConfig::KEY_FULL_EXPORT]['public']);
        $this->assertContains('orders', $parsed[DumpConfig::KEY_FULL_EXPORT]['public']);
        $this->assertContains('new_table', $parsed[DumpConfig::KEY_FULL_EXPORT]['public']);
    }

    public function testGenerateModeSchemaMergesWithExisting(): void
    {
        $this->inspector->method('listTables')->willReturn([
            ['table_schema' => 'public', 'table_name' => 'users'],
            ['table_schema' => 'billing', 'table_name' => 'invoices'],
        ]);

        $this->filter->method('shouldIgnore')->willReturn(false);
        $this->inspector->method('countRows')->willReturn(100);

        // Существующий конфиг с public.roles и billing.old_table
        $existingYaml = Yaml::dump([
            DumpConfig::KEY_FULL_EXPORT => [
                'public' => ['roles'],
                'billing' => ['old_table'],
            ],
        ]);
        $this->fileSystem->method('exists')->willReturn(true);
        $this->fileSystem->method('read')->willReturn($existingYaml);

        /** @var string $writtenContent */
        $writtenContent = '';
        $this->fileSystem
            ->expects($this->once())
            ->method('write')
            ->willReturnCallback(function ($path, $content) use (&$writtenContent) {
                $writtenContent = $content;
            });

        // Перегенерируем только billing
        $this->generator->setMode(ConfigGenerator::MODE_SCHEMA, 'billing');
        $stats = $this->generator->generate('/tmp/dump_config.yaml', 500);

        $this->assertEquals(1, $stats['full']);

        $parsed = Yaml::parse($writtenContent);
        // public.roles сохранена из существующего конфига
        $this->assertContains('roles', $parsed[DumpConfig::KEY_FULL_EXPORT]['public']);
        // billing.old_table заменена на billing.invoices
        $this->assertContains('invoices', $parsed[DumpConfig::KEY_FULL_EXPORT]['billing']);
        $this->assertNotContains('old_table', $parsed[DumpConfig::KEY_FULL_EXPORT]['billing']);
    }

    public function testGenerateModeTableMergesWithExisting(): void
    {
        $this->inspector->method('listTables')->willReturn([
            ['table_schema' => 'public', 'table_name' => 'users'],
            ['table_schema' => 'public', 'table_name' => 'orders'],
        ]);

        $this->filter->method('shouldIgnore')->willReturn(false);
        $this->inspector->method('countRows')->willReturnMap([
            ['public', 'users', null, 100],
            ['public', 'orders', null, 10000],
        ]);
        $this->inspector->method('detectOrderColumn')->willReturn('id DESC');

        // Существующий конфиг
        $existingYaml = Yaml::dump([
            DumpConfig::KEY_FULL_EXPORT => [
                'public' => ['users', 'roles'],
            ],
            DumpConfig::KEY_PARTIAL_EXPORT => [
                'public' => [
                    'orders' => [
                        'limit' => 100,
                        'order_by' => 'created_at DESC',
                    ],
                ],
            ],
        ]);
        $this->fileSystem->method('exists')->willReturn(true);
        $this->fileSystem->method('read')->willReturn($existingYaml);

        /** @var string $writtenContent */
        $writtenContent = '';
        $this->fileSystem
            ->expects($this->once())
            ->method('write')
            ->willReturnCallback(function ($path, $content) use (&$writtenContent) {
                $writtenContent = $content;
            });

        // Перегенерируем только public.orders
        $this->generator->setMode(ConfigGenerator::MODE_TABLE, 'public.orders');
        $stats = $this->generator->generate('/tmp/dump_config.yaml', 500);

        $this->assertEquals(1, $stats['partial']);

        $parsed = Yaml::parse($writtenContent);
        // full_export: users и roles сохранены
        $this->assertContains('users', $parsed[DumpConfig::KEY_FULL_EXPORT]['public']);
        $this->assertContains('roles', $parsed[DumpConfig::KEY_FULL_EXPORT]['public']);
        // partial_export: orders обновлён с новыми параметрами
        $this->assertEquals(500, $parsed[DumpConfig::KEY_PARTIAL_EXPORT]['public']['orders']['limit']);
        $this->assertEquals('id DESC', $parsed[DumpConfig::KEY_PARTIAL_EXPORT]['public']['orders']['order_by']);
    }

    public function testGenerateModeNewAppendsOnly(): void
    {
        $this->inspector->method('listTables')->willReturn([
            ['table_schema' => 'public', 'table_name' => 'users'],
        ]);

        $this->filter->method('shouldIgnore')->willReturn(false);
        $this->inspector->method('countRows')->willReturn(100);

        // users уже в конфиге с ручными правками в partial_export
        $existingYaml = Yaml::dump([
            DumpConfig::KEY_PARTIAL_EXPORT => [
                'public' => [
                    'users' => [
                        'limit' => 50,
                        'where' => 'active = true',
                    ],
                ],
            ],
        ]);
        $this->fileSystem->method('exists')->willReturn(true);
        $this->fileSystem->method('read')->willReturn($existingYaml);

        /** @var string $writtenContent */
        $writtenContent = '';
        $this->fileSystem
            ->expects($this->once())
            ->method('write')
            ->willReturnCallback(function ($path, $content) use (&$writtenContent) {
                $writtenContent = $content;
            });

        $this->generator->setMode(ConfigGenerator::MODE_NEW);
        $stats = $this->generator->generate('/tmp/dump_config.yaml', 500);

        // users пропущена — уже в конфигурации
        $this->assertEquals(0, $stats['full']);
        $this->assertEquals(1, $stats['skipped']);

        $parsed = Yaml::parse($writtenContent);
        // Ручные правки сохранены
        $this->assertEquals(50, $parsed[DumpConfig::KEY_PARTIAL_EXPORT]['public']['users']['limit']);
        $this->assertEquals('active = true', $parsed[DumpConfig::KEY_PARTIAL_EXPORT]['public']['users']['where']);
    }

    public function testGenerateModeSchemaNoExistingConfig(): void
    {
        $this->inspector->method('listTables')->willReturn([
            ['table_schema' => 'public', 'table_name' => 'users'],
            ['table_schema' => 'billing', 'table_name' => 'invoices'],
        ]);

        $this->filter->method('shouldIgnore')->willReturn(false);
        $this->inspector->method('countRows')->willReturn(100);

        // Файл не существует
        $this->fileSystem->method('exists')->willReturn(false);

        /** @var string $writtenContent */
        $writtenContent = '';
        $this->fileSystem
            ->expects($this->once())
            ->method('write')
            ->willReturnCallback(function ($path, $content) use (&$writtenContent) {
                $writtenContent = $content;
            });

        $this->generator->setMode(ConfigGenerator::MODE_SCHEMA, 'billing');
        $stats = $this->generator->generate('/tmp/dump_config.yaml', 500);

        // Только billing сгенерирована (свежий файл)
        $this->assertEquals(1, $stats['full']);
        $parsed = Yaml::parse($writtenContent);
        $this->assertContains('invoices', $parsed[DumpConfig::KEY_FULL_EXPORT]['billing']);
        $this->assertArrayNotHasKey('public', $parsed[DumpConfig::KEY_FULL_EXPORT]);
    }

    public function testGenerateModeSchemaWithIncludes(): void
    {
        $this->inspector->method('listTables')->willReturn([
            ['table_schema' => 'billing', 'table_name' => 'invoices'],
        ]);

        $this->filter->method('shouldIgnore')->willReturn(false);
        $this->inspector->method('countRows')->willReturn(100);

        // Существующий конфиг с includes
        $mainYaml = Yaml::dump([
            DumpConfig::KEY_INCLUDES => [
                'public' => 'public.yaml',
            ],
        ]);
        $publicYaml = Yaml::dump([
            DumpConfig::KEY_FULL_EXPORT => ['roles', 'permissions'],
        ]);

        $this->fileSystem->method('exists')->willReturn(true);
        $this->fileSystem->method('read')->willReturnMap([
            ['/tmp/dump_config.yaml', $mainYaml],
            ['/tmp/public.yaml', $publicYaml],
        ]);

        /** @var string $writtenContent */
        $writtenContent = '';
        $this->fileSystem
            ->expects($this->once())
            ->method('write')
            ->willReturnCallback(function ($path, $content) use (&$writtenContent) {
                $writtenContent = $content;
            });

        $this->generator->setMode(ConfigGenerator::MODE_SCHEMA, 'billing');
        $stats = $this->generator->generate('/tmp/dump_config.yaml', 500);

        $this->assertEquals(1, $stats['full']);
        $parsed = Yaml::parse($writtenContent);
        // public из includes сохранена
        $this->assertContains('roles', $parsed[DumpConfig::KEY_FULL_EXPORT]['public']);
        $this->assertContains('permissions', $parsed[DumpConfig::KEY_FULL_EXPORT]['public']);
        // billing сгенерирована заново
        $this->assertContains('invoices', $parsed[DumpConfig::KEY_FULL_EXPORT]['billing']);
    }
}
