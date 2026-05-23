<?php

namespace Timbrs\DatabaseDumps\Tests\Unit\Service\Dumper;

use PHPUnit\Framework\TestCase;
use Timbrs\DatabaseDumps\Config\DumpConfig;
use Timbrs\DatabaseDumps\Config\EnvironmentConfig;
use Timbrs\DatabaseDumps\Config\FakerConfig;
use Timbrs\DatabaseDumps\Config\TableConfig;
use Timbrs\DatabaseDumps\Contract\FakerInterface;
use Timbrs\DatabaseDumps\Contract\FileSystemInterface;
use Timbrs\DatabaseDumps\Contract\LoggerInterface;
use Timbrs\DatabaseDumps\Service\Dumper\DatabaseDumper;
use Timbrs\DatabaseDumps\Service\Dumper\DataFetcher;
use Timbrs\DatabaseDumps\Service\Generator\SqlGenerator;
use Timbrs\DatabaseDumps\Service\Graph\SortResult;
use Timbrs\DatabaseDumps\Service\Graph\TableDependencyResolver;
use Timbrs\DatabaseDumps\Service\Security\ProductionGuard;

/**
 * Тесты DatabaseDumper.
 *
 * Особенности новой реализации:
 *  - DataFetcher::iterate возвращает Generator (streaming), SqlGenerator::generateChunks
 *    принимает iterable.
 *  - Запись идёт в *.tmp файл, затем атомарный rename. В тестах путь проверяется
 *    через регекс (содержит ожидаемое имя таблицы + суффикс .tmp).
 */
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
        $this->projectDir = sys_get_temp_dir() . '/dbdumps_test';

        $this->fileSystem->method('exists')->willReturn(true);
        $this->fileSystem->method('getFileSize')->willReturn(1024);
    }

    /**
     * Реальный ProductionGuard в режиме dev — не блокирует экспорт.
     */
    private function devGuard(): ProductionGuard
    {
        return new ProductionGuard(new EnvironmentConfig('dev'));
    }

    private function createDumper(?DumpConfig $dumpConfig = null): DatabaseDumper
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
            $dumpConfig,
            $this->devGuard()
        );
    }

    /**
     * Helper: создаёт callback для $fileSystem->method('write'), который
     * запоминает путь и переименовывает .tmp в финальный путь.
     * Так как тест замокал FileSystem, делаем простой rename через реальный fs.
     */
    private function setupAtomicWrite(?array &$capturedPaths = null): void
    {
        $capturedPaths = [];
        $this->fileSystem->method('write')->willReturnCallback(
            function (string $path, string $content) use (&$capturedPaths) {
                $capturedPaths[] = $path;
            }
        );
        $this->fileSystem->method('append')->willReturnCallback(
            function (string $path, string $content) {
                // ОК — путь должен быть .tmp
            }
        );
    }

    public function testExportAllSortsTopologically(): void
    {
        $tableA = new TableConfig('public', 'users');
        $tableB = new TableConfig('public', 'orders');
        $tableC = new TableConfig('public', 'order_items');

        $tables = [$tableB, $tableC, $tableA];

        $this->dependencyResolver
            ->expects($this->once())
            ->method('sortForExportWithResult')
            ->willReturn(new SortResult(['public.users', 'public.orders', 'public.order_items']));

        $this->dataFetcher->method('iterate')->willReturnCallback(function () {
            yield from [];
        });
        $this->sqlGenerator->method('generateChunks')->willReturnCallback(function () {
            yield '-- SQL';
        });
        $this->setupAtomicWrite();

        $exportOrder = [];
        $this->logger->method('info')->willReturnCallback(function ($message) use (&$exportOrder) {
            if (preg_match('/\[\d+\/\d+\] ([\w.]+)/', $message, $matches)) {
                $exportOrder[] = $matches[1];
            }
        });

        $dumper = $this->createDumper();
        $dumper->exportAll($tables);

        $this->assertSame(['public.users', 'public.orders', 'public.order_items'], $exportOrder);
    }

    public function testExportTableAppliesFaker(): void
    {
        // Используем валидный pattern_type из ALLOWED_PATTERNS
        $fakerConfig = new FakerConfig([
            'public' => [
                'users' => [
                    'email' => 'email',
                    'name' => 'fio',
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

        $this->dataFetcher->method('iterate')->willReturnCallback(function () use ($originalRows) {
            foreach ($originalRows as $row) {
                yield $row;
            }
        });

        $this->faker
            ->expects($this->once())
            ->method('apply')
            ->with('public', 'users', ['email' => 'email', 'name' => 'fio'], $originalRows)
            ->willReturn($fakedRows);

        $this->sqlGenerator
            ->expects($this->once())
            ->method('generateChunks')
            ->willReturnCallback(function ($config, $rows) {
                // Поглощаем generator чтобы DatabaseDumper::applyFakerStreaming сработал
                foreach ($rows as $_) {
                    // no-op
                }
                yield '-- SQL with faked data';
            });

        $this->setupAtomicWrite();

        $dumper = $this->createDumper($dumpConfig);
        $dumper->exportTable($config);
    }

    public function testExportTableSkipsFakerWhenNotConfigured(): void
    {
        $dumpConfig = new DumpConfig([], []);
        $config = new TableConfig('public', 'users');

        $this->dataFetcher->method('iterate')->willReturnCallback(function () {
            yield ['id' => 1];
        });
        $this->faker->expects($this->never())->method('apply');
        $this->sqlGenerator->method('generateChunks')->willReturnCallback(function () {
            yield '-- SQL';
        });
        $this->setupAtomicWrite();

        $dumper = $this->createDumper($dumpConfig);
        $dumper->exportTable($config);
    }

    public function testExportAllHandlesCycleWithDeferredEdges(): void
    {
        $tables = [new TableConfig('public', 'users'), new TableConfig('public', 'orders')];

        $this->dependencyResolver
            ->expects($this->once())
            ->method('sortForExportWithResult')
            ->willReturn(new SortResult(
                ['public.users', 'public.orders'],
                [['source' => 'public.orders', 'target' => 'public.users',
                  'source_column' => 'user_id', 'target_column' => 'id']]
            ));

        $this->dataFetcher->method('iterate')->willReturnCallback(function () {
            yield from [];
        });
        $this->sqlGenerator->method('generateChunks')->willReturnCallback(function () {
            yield '-- SQL';
        });
        $this->setupAtomicWrite();

        $exportOrder = [];
        $this->logger->method('info')->willReturnCallback(function ($message) use (&$exportOrder) {
            if (preg_match('/\[\d+\/\d+\] ([\w.]+)/', $message, $matches)) {
                $exportOrder[] = $matches[1];
            }
        });

        $dumper = $this->createDumper();
        $dumper->exportAll($tables);
        $this->assertSame(['public.users', 'public.orders'], $exportOrder);
    }

    public function testExportAllWithEmptyTablesArray(): void
    {
        $this->dependencyResolver->expects($this->never())->method('sortForExportWithResult');
        $this->dataFetcher->expects($this->never())->method('iterate');

        $dumper = $this->createDumper();
        $dumper->exportAll([]);
    }

    public function testExportTableBuildsDumpPathContainingExpectedSegments(): void
    {
        $config = new TableConfig('public', 'users');

        $this->dataFetcher->method('iterate')->willReturnCallback(function () {
            yield from [];
        });
        $this->sqlGenerator->method('generateChunks')->willReturnCallback(function () {
            yield '-- SQL';
        });

        $capturedPaths = [];
        $this->setupAtomicWrite($capturedPaths);

        $dumper = $this->createDumper();
        $dumper->exportTable($config);

        $this->assertNotEmpty($capturedPaths);
        $writtenPath = $capturedPaths[0];
        // Путь содержит ожидаемые сегменты и .tmp. суффикс (атомарная запись)
        $this->assertStringContainsString('database/dumps/public/users.sql.tmp.', str_replace('\\', '/', $writtenPath));
    }

    public function testExportTableWithConnectionBuildsDumpPath(): void
    {
        $config = new TableConfig('public', 'users', null, null, null, 'secondary');

        $this->dataFetcher->method('iterate')->willReturnCallback(function () {
            yield from [];
        });
        $this->sqlGenerator->method('generateChunks')->willReturnCallback(function () {
            yield '-- SQL';
        });

        $capturedPaths = [];
        $this->setupAtomicWrite($capturedPaths);

        $dumper = $this->createDumper();
        $dumper->exportTable($config);

        $this->assertNotEmpty($capturedPaths);
        $writtenPath = str_replace('\\', '/', $capturedPaths[0]);
        $this->assertStringContainsString('database/dumps/secondary/public/users.sql.tmp.', $writtenPath);
    }

    public function testExportTableUsesStreamingWrite(): void
    {
        $config = new TableConfig('public', 'users');

        $this->dataFetcher->method('iterate')->willReturnCallback(function () {
            yield from [];
        });
        $this->sqlGenerator->method('generateChunks')->willReturnCallback(function () {
            yield '-- header';
            yield '-- batch 1';
            yield '-- batch 2';
        });

        // write() — для первого чанка
        $writeCalls = 0;
        $this->fileSystem->method('write')->willReturnCallback(function ($p, $c) use (&$writeCalls) {
            $writeCalls++;
        });

        $appendContents = [];
        $this->fileSystem->method('append')->willReturnCallback(function ($p, $c) use (&$appendContents) {
            $appendContents[] = $c;
        });

        $dumper = $this->createDumper();
        $dumper->exportTable($config);

        $this->assertSame(1, $writeCalls);
        $this->assertSame(['-- batch 1', '-- batch 2'], $appendContents);
    }

    public function testExportTableBlocksOnProd(): void
    {
        $config = new TableConfig('public', 'users');

        $prodGuard = new ProductionGuard(new EnvironmentConfig('prod'));

        $dumper = new DatabaseDumper(
            $this->dataFetcher,
            $this->sqlGenerator,
            $this->fileSystem,
            $this->logger,
            $this->projectDir,
            $this->dependencyResolver,
            $this->faker,
            new DumpConfig([], []),
            $prodGuard
        );

        $this->expectException(\Timbrs\DatabaseDumps\Exception\ProductionEnvironmentException::class);
        $dumper->exportTable($config);
    }

    public function testExportTableAllowsProdWithFlag(): void
    {
        $config = new TableConfig('public', 'users');

        $prodGuard = new ProductionGuard(new EnvironmentConfig('prod'));

        $this->dataFetcher->method('iterate')->willReturnCallback(function () {
            yield from [];
        });
        $this->sqlGenerator->method('generateChunks')->willReturnCallback(function () {
            yield '-- SQL';
        });
        $this->setupAtomicWrite();

        $dumper = new DatabaseDumper(
            $this->dataFetcher,
            $this->sqlGenerator,
            $this->fileSystem,
            $this->logger,
            $this->projectDir,
            $this->dependencyResolver,
            $this->faker,
            new DumpConfig([], []),
            $prodGuard
        );
        $dumper->setAllowProdExport(true);
        $dumper->exportTable($config);

        // Если дошли сюда — не упало
        $this->assertTrue(true);
    }
}
