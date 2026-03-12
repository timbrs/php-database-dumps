<?php

namespace Timbrs\DatabaseDumps\Tests\Unit\Service\Importer;

use Timbrs\DatabaseDumps\Config\DumpConfig;
use Timbrs\DatabaseDumps\Contract\ConnectionRegistryInterface;
use Timbrs\DatabaseDumps\Contract\DatabaseConnectionInterface;
use Timbrs\DatabaseDumps\Contract\FileSystemInterface;
use Timbrs\DatabaseDumps\Contract\LoggerInterface;
use Timbrs\DatabaseDumps\Service\Graph\TableDependencyResolver;
use Timbrs\DatabaseDumps\Service\Importer\DatabaseImporter;
use Timbrs\DatabaseDumps\Service\Importer\ScriptExecutor;
use Timbrs\DatabaseDumps\Service\Importer\TransactionManager;
use Timbrs\DatabaseDumps\Service\Parser\SqlParser;
use Timbrs\DatabaseDumps\Service\Security\ProductionGuard;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class DatabaseImporterTest extends TestCase
{
    /** @var MockObject&DatabaseConnectionInterface */
    private $connection;
    /** @var MockObject&ConnectionRegistryInterface */
    private $registry;
    /** @var MockObject&FileSystemInterface */
    private $fileSystem;
    /** @var MockObject&ProductionGuard */
    private $productionGuard;
    /** @var MockObject&TransactionManager */
    private $transactionManager;
    /** @var MockObject&ScriptExecutor */
    private $scriptExecutor;
    /** @var MockObject&SqlParser */
    private $parser;
    /** @var MockObject&LoggerInterface */
    private $logger;
    /** @var MockObject&TableDependencyResolver */
    private $dependencyResolver;

    protected function setUp(): void
    {
        $this->connection = $this->createMock(DatabaseConnectionInterface::class);
        $this->registry = $this->createMock(ConnectionRegistryInterface::class);
        $this->registry->method('getConnection')->willReturn($this->connection);

        $this->fileSystem = $this->createMock(FileSystemInterface::class);
        $this->productionGuard = $this->createMock(ProductionGuard::class);
        $this->transactionManager = $this->createMock(TransactionManager::class);
        $this->scriptExecutor = $this->createMock(ScriptExecutor::class);
        $this->parser = $this->createMock(SqlParser::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->dependencyResolver = $this->createMock(TableDependencyResolver::class);

        // TransactionManager просто выполняет callback
        $this->transactionManager->method('transaction')->willReturnCallback(
            function (callable $callback) {
                return $callback();
            }
        );
    }

    /**
     * @param string $platformName
     * @return DatabaseImporter
     */
    private function createImporter($platformName = 'postgresql')
    {
        $this->connection->method('getPlatformName')->willReturn($platformName);

        return new DatabaseImporter(
            $this->registry,
            new DumpConfig([], []),
            $this->fileSystem,
            $this->productionGuard,
            $this->transactionManager,
            $this->scriptExecutor,
            $this->parser,
            $this->logger,
            '/project',
            $this->dependencyResolver
        );
    }

    public function testImportDisablesForeignKeyChecksForMysql(): void
    {
        $importer = $this->createImporter('mysql');

        $this->fileSystem->method('isDirectory')->willReturn(true);
        $this->fileSystem->method('findFiles')->willReturn([
            '/project/database/dumps/mydb/users.sql',
        ]);
        $this->fileSystem->method('read')->willReturn('INSERT INTO users VALUES (1);');
        $this->parser->method('parseFile')->willReturn(['INSERT INTO users VALUES (1)']);
        $this->dependencyResolver->method('sortForImport')->willReturnArgument(0);

        $calls = [];
        $this->connection
            ->expects($this->exactly(3))
            ->method('executeStatement')
            ->willReturnCallback(function ($sql) use (&$calls) {
                $calls[] = $sql;
                return 0;
            });

        $importer->import(true, true);

        $this->assertEquals('SET FOREIGN_KEY_CHECKS=0', $calls[0]);
        $this->assertEquals('INSERT INTO users VALUES (1)', $calls[1]);
        $this->assertEquals('SET FOREIGN_KEY_CHECKS=1', $calls[2]);
    }

    public function testImportDisablesForeignKeyChecksForMariadb(): void
    {
        $importer = $this->createImporter('mariadb');

        $this->fileSystem->method('isDirectory')->willReturn(true);
        $this->fileSystem->method('findFiles')->willReturn([
            '/project/database/dumps/mydb/users.sql',
        ]);
        $this->fileSystem->method('read')->willReturn('INSERT INTO users VALUES (1);');
        $this->parser->method('parseFile')->willReturn(['INSERT INTO users VALUES (1)']);
        $this->dependencyResolver->method('sortForImport')->willReturnArgument(0);

        $calls = [];
        $this->connection
            ->expects($this->exactly(3))
            ->method('executeStatement')
            ->willReturnCallback(function ($sql) use (&$calls) {
                $calls[] = $sql;
                return 0;
            });

        $importer->import(true, true);

        $this->assertEquals('SET FOREIGN_KEY_CHECKS=0', $calls[0]);
        $this->assertEquals('INSERT INTO users VALUES (1)', $calls[1]);
        $this->assertEquals('SET FOREIGN_KEY_CHECKS=1', $calls[2]);
    }

    public function testImportDoesNotDisableForeignKeyChecksForPostgres(): void
    {
        $importer = $this->createImporter('postgresql');

        $this->fileSystem->method('isDirectory')->willReturn(true);
        $this->fileSystem->method('findFiles')->willReturn([
            '/project/database/dumps/public/users.sql',
        ]);
        $this->fileSystem->method('read')->willReturn('INSERT INTO users VALUES (1);');
        $this->parser->method('parseFile')->willReturn(['INSERT INTO users VALUES (1)']);
        $this->dependencyResolver->method('sortForImport')->willReturnArgument(0);

        // Только один вызов — сам INSERT, без FK_CHECKS
        $this->connection
            ->expects($this->once())
            ->method('executeStatement')
            ->with('INSERT INTO users VALUES (1)');

        $importer->import(true, true);
    }

    public function testImportRestoresForeignKeyChecksOnError(): void
    {
        $importer = $this->createImporter('mysql');

        $this->fileSystem->method('isDirectory')->willReturn(true);
        $this->fileSystem->method('findFiles')->willReturn([
            '/project/database/dumps/mydb/users.sql',
        ]);
        $this->fileSystem->method('read')->willReturn('BAD SQL;');
        $this->parser->method('parseFile')->willReturn(['BAD SQL']);
        $this->dependencyResolver->method('sortForImport')->willReturnArgument(0);

        $callIndex = 0;
        $this->connection
            ->expects($this->exactly(3))
            ->method('executeStatement')
            ->willReturnCallback(function ($sql) use (&$callIndex) {
                $callIndex++;
                if ($callIndex === 1) {
                    $this->assertEquals('SET FOREIGN_KEY_CHECKS=0', $sql);
                    return 0;
                }
                if ($callIndex === 2) {
                    $this->assertEquals('BAD SQL', $sql);
                    throw new \RuntimeException('SQL error');
                }
                // callIndex === 3: finally блок
                $this->assertEquals('SET FOREIGN_KEY_CHECKS=1', $sql);
                return 0;
            });

        $this->expectException(\RuntimeException::class);
        $importer->import(true, true);
    }
}
