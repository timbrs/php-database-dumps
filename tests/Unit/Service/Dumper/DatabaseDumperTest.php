<?php

namespace Timbrs\DatabaseDumps\Tests\Unit\Service\Dumper;

use PHPUnit\Framework\TestCase;
use Timbrs\DatabaseDumps\Config\DumpConfig;
use Timbrs\DatabaseDumps\Config\FakerConfig;
use Timbrs\DatabaseDumps\Config\TableConfig;
use Timbrs\DatabaseDumps\Contract\FakerInterface;
use Timbrs\DatabaseDumps\Contract\FileSystemInterface;
use Timbrs\DatabaseDumps\Contract\LoggerInterface;
use Timbrs\DatabaseDumps\Service\Dumper\DatabaseDumper;
use Timbrs\DatabaseDumps\Service\Dumper\DataFetcher;
use Timbrs\DatabaseDumps\Service\Generator\SqlGenerator;
use Timbrs\DatabaseDumps\Service\Graph\TableDependencyResolver;

class DatabaseDumperTest extends TestCase
{
    /** @var DataFetcher&\PHPUnit\Framework\MockObject\MockObject */
    private $dataFetcher;

    /** @var SqlGenerator&\PHPUnit\Framework\MockObject\MockObject */
    private $sqlGenerator;

    /** @var FileSystemInterface&\PHPUnit\Framework\MockObject\MockObject */
    private $fileSystem;

    /** @var LoggerInterface&\PHPUnit\Framework\MockObject\MockObject */
    private $logger;

    /** @var TableDependencyResolver&\PHPUnit\Framework\MockObject\MockObject */
    private $dependencyResolver;

    /** @var FakerInterface&\PHPUnit\Framework\MockObject\MockObject */
    private $faker;

    /** @var string */
    private $projectDir;

    protected function setUp(): void
    {
        $this->dataFetcher = $this->createMock(DataFetcher::class);
        $this->sqlGenerator = $this->createMock(SqlGenerator::class);
        $this->fileSystem = $this->createMock(FileSystemInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->dependencyResolver = $this->createMock(TableDependencyResolver::class);
        $this->faker = $this->createMock(FakerInterface::class);
        $this->projectDir = '/tmp/project';

        $this->fileSystem->method('exists')->willReturn(true);
        $this->fileSystem->method('getFileSize')->willReturn(1024);
    }

    /**
     * @param DumpConfig|null $dumpConfig
     * @return DatabaseDumper
     */
    private function createDumper(?DumpConfig $dumpConfig = null)
    {
        if ($dumpConfig === null) {
            $dumpConfig = new DumpConfig([], []);
        }

        return new DatabaseDumper(
            $this->dataFetcher,
            $this->sqlGenerator,
            $this->fileSystem,
            $this->logger,
            $this->projectDir,
            $this->dependencyResolver,
            $this->faker,
            $dumpConfig
        );
    }

    public function testExportAllSortsTopologically(): void
    {
        $tableA = new TableConfig('public', 'users');
        $tableB = new TableConfig('public', 'orders');
        $tableC = new TableConfig('public', 'order_items');

        // Original order: orders, order_items, users
        $tables = [$tableB, $tableC, $tableA];

        // Dependency resolver returns sorted order: users, orders, order_items
        $this->dependencyResolver
            ->expects($this->once())
            ->method('sortForExport')
            ->with(
                ['public.orders', 'public.order_items', 'public.users'],
                null
            )
            ->willReturn(['public.users', 'public.orders', 'public.order_items']);

        $this->dataFetcher->method('fetch')->willReturn([]);
        $this->sqlGenerator->method('generateChunks')->willReturnCallback(function () {
            yield '-- SQL';
        });

        // Track the order of table exports via logger info calls
        $exportOrder = [];
        $this->logger->method('info')->willReturnCallback(function ($message) use (&$exportOrder) {
            if (preg_match('/\[\d+\/\d+\] ([\w.]+)/', $message, $matches)) {
                $exportOrder[] = $matches[1];
            }
        });

        $dumper = $this->createDumper();
        $dumper->exportAll($tables);

        $this->assertEquals(['public.users', 'public.orders', 'public.order_items'], $exportOrder);
    }

    public function testExportTableAppliesFaker(): void
    {
        $fakerConfig = new FakerConfig([
            'public' => [
                'users' => [
                    'email' => 'email',
                    'name' => 'full_name',
                ],
            ],
        ]);
        $dumpConfig = new DumpConfig([], [], [], $fakerConfig);

        $config = new TableConfig('public', 'users');

        $originalRows = [
            ['id' => 1, 'email' => 'real@email.com', 'name' => 'Real Name'],
        ];
        $fakedRows = [
            ['id' => 1, 'email' => 'fake@email.com', 'name' => 'Fake Name'],
        ];

        $this->dataFetcher->method('fetch')->willReturn($originalRows);

        $this->faker
            ->expects($this->once())
            ->method('apply')
            ->with(
                'public',
                'users',
                ['email' => 'email', 'name' => 'full_name'],
                $originalRows
            )
            ->willReturn($fakedRows);

        // SqlGenerator should receive the faked rows, not originals
        $this->sqlGenerator
            ->expects($this->once())
            ->method('generateChunks')
            ->with($config, $fakedRows)
            ->willReturnCallback(function () {
                yield '-- SQL with faked data';
            });

        $dumper = $this->createDumper($dumpConfig);
        $dumper->exportTable($config);
    }

    public function testExportTableSkipsFakerWhenNotConfigured(): void
    {
        $dumpConfig = new DumpConfig([], []);
        $config = new TableConfig('public', 'users');

        $rows = [
            ['id' => 1, 'email' => 'real@email.com'],
        ];

        $this->dataFetcher->method('fetch')->willReturn($rows);

        // Faker should NOT be called
        $this->faker->expects($this->never())->method('apply');

        $this->sqlGenerator
            ->expects($this->once())
            ->method('generateChunks')
            ->with($config, $rows)
            ->willReturnCallback(function () {
                yield '-- SQL';
            });

        $dumper = $this->createDumper($dumpConfig);
        $dumper->exportTable($config);
    }

    public function testExportAllHandlesCycleGracefully(): void
    {
        $tableA = new TableConfig('public', 'users');
        $tableB = new TableConfig('public', 'orders');

        $tables = [$tableA, $tableB];

        // Dependency resolver throws RuntimeException (cycle detected)
        $this->dependencyResolver
            ->expects($this->once())
            ->method('sortForExport')
            ->willThrowException(new \RuntimeException('Circular dependency detected'));

        // Logger should receive a warning about the cycle
        $this->logger
            ->expects($this->once())
            ->method('warning')
            ->with($this->stringContains('Цикл FK зависимостей'));

        $this->dataFetcher->method('fetch')->willReturn([]);
        $this->sqlGenerator->method('generateChunks')->willReturnCallback(function () {
            yield '-- SQL';
        });

        // Track the order — should be original order
        $exportOrder = [];
        $this->logger->method('info')->willReturnCallback(function ($message) use (&$exportOrder) {
            if (preg_match('/\[\d+\/\d+\] ([\w.]+)/', $message, $matches)) {
                $exportOrder[] = $matches[1];
            }
        });

        $dumper = $this->createDumper();
        $dumper->exportAll($tables);

        // Original order preserved
        $this->assertEquals(['public.users', 'public.orders'], $exportOrder);
    }

    public function testExportAllWithEmptyTablesArray(): void
    {
        $this->dependencyResolver->expects($this->never())->method('sortForExport');
        $this->dataFetcher->expects($this->never())->method('fetch');

        $dumper = $this->createDumper();
        $dumper->exportAll([]);
    }

    public function testExportTableBuildsDumpPath(): void
    {
        $config = new TableConfig('public', 'users');

        $this->dataFetcher->method('fetch')->willReturn([]);
        $this->sqlGenerator->method('generateChunks')->willReturnCallback(function () {
            yield '-- SQL';
        });

        $this->fileSystem
            ->expects($this->once())
            ->method('write')
            ->with(
                '/tmp/project/database/dumps/public/users.sql',
                '-- SQL'
            );

        $dumper = $this->createDumper();
        $dumper->exportTable($config);
    }

    public function testExportTableWithConnectionBuildsDumpPath(): void
    {
        $config = new TableConfig('public', 'users', null, null, null, 'secondary');

        $this->dataFetcher->method('fetch')->willReturn([]);
        $this->sqlGenerator->method('generateChunks')->willReturnCallback(function () {
            yield '-- SQL';
        });

        $this->fileSystem
            ->expects($this->once())
            ->method('write')
            ->with(
                '/tmp/project/database/dumps/secondary/public/users.sql',
                '-- SQL'
            );

        $dumper = $this->createDumper();
        $dumper->exportTable($config);
    }

    public function testExportTableUsesStreamingWrite(): void
    {
        $config = new TableConfig('public', 'users');

        $this->dataFetcher->method('fetch')->willReturn([]);
        $this->sqlGenerator->method('generateChunks')->willReturnCallback(function () {
            yield '-- header';
            yield '-- batch 1';
            yield '-- batch 2';
        });

        $expectedPath = '/tmp/project/database/dumps/public/users.sql';

        // write() вызывается один раз для первого чанка
        $this->fileSystem
            ->expects($this->once())
            ->method('write')
            ->with($expectedPath, '-- header');

        // append() вызывается для остальных чанков
        $appendCalls = [];
        $this->fileSystem
            ->expects($this->exactly(2))
            ->method('append')
            ->willReturnCallback(function ($path, $content) use (&$appendCalls) {
                $appendCalls[] = $content;
            });

        $dumper = $this->createDumper();
        $dumper->exportTable($config);

        $this->assertEquals(['-- batch 1', '-- batch 2'], $appendCalls);
    }
}
