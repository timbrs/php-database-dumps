<?php

namespace Timbrs\DatabaseDumps\Tests\Unit\Service\Importer;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Timbrs\DatabaseDumps\Config\DumpConfig;
use Timbrs\DatabaseDumps\Contract\ConnectionRegistryInterface;
use Timbrs\DatabaseDumps\Contract\DatabaseConnectionInterface;
use Timbrs\DatabaseDumps\Contract\FileSystemInterface;
use Timbrs\DatabaseDumps\Contract\LoggerInterface;
use Timbrs\DatabaseDumps\Platform\PostgresPlatform;
use Timbrs\DatabaseDumps\Service\Graph\TableDependencyResolver;
use Timbrs\DatabaseDumps\Service\Importer\DatabaseImporter;
use Timbrs\DatabaseDumps\Service\Importer\ImportReport;
use Timbrs\DatabaseDumps\Service\Importer\SchemaValidator;
use Timbrs\DatabaseDumps\Service\Importer\ScriptExecutor;
use Timbrs\DatabaseDumps\Service\Importer\TransactionManager;
use Timbrs\DatabaseDumps\Service\Importer\ValidationResult;
use Timbrs\DatabaseDumps\Service\Parser\SqlParser;
use Timbrs\DatabaseDumps\Service\Security\ProductionGuard;
use Timbrs\DatabaseDumps\Service\Verification\DumpValueReader;

/**
 * Отчёт импорта: что попало в ImportReport помимо «прошёл / упал».
 * Дамп лежит в настоящем временном файле — I-2 считает строки самим ридером.
 */
class DatabaseImporterReportTest extends TestCase
{
    /** @var MockObject&DatabaseConnectionInterface */
    private $connection;
    /** @var MockObject&FileSystemInterface */
    private $fileSystem;
    /** @var MockObject&SqlParser */
    private $parser;
    /** @var MockObject&SchemaValidator */
    private $schemaValidator;
    /** @var string */
    private $projectDir;
    /** @var string */
    private $dumpPath;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir() . '/importrep_' . bin2hex(random_bytes(4));
        mkdir($this->projectDir . '/database/dumps/public', 0777, true);
        $this->dumpPath = $this->projectDir . '/database/dumps/public/users.sql';
        file_put_contents(
            $this->dumpPath,
            "INSERT INTO \"public\".\"users\" (\"id\", \"login\") VALUES\n(1, 'a'),\n(2, 'b');\n"
        );

        $this->connection = $this->createMock(DatabaseConnectionInterface::class);
        $this->connection->method('getPlatformName')->willReturn('postgresql');
        $this->fileSystem = $this->createMock(FileSystemInterface::class);
        $this->fileSystem->method('isDirectory')->willReturn(true);
        $this->fileSystem->method('findFiles')->willReturn([$this->dumpPath]);
        $this->fileSystem->method('read')->willReturn((string) file_get_contents($this->dumpPath));
        $this->parser = $this->createMock(SqlParser::class);
        $this->parser->method('parseColumnList')->willReturn(['id', 'login']);
        $this->parser->method('parseFile')->willReturn(['INSERT INTO users VALUES (1)']);
        $this->schemaValidator = $this->createMock(SchemaValidator::class);
    }

    protected function tearDown(): void
    {
        unlink($this->dumpPath);
        rmdir($this->projectDir . '/database/dumps/public');
        rmdir($this->projectDir . '/database/dumps');
        rmdir($this->projectDir . '/database');
        rmdir($this->projectDir);
    }

    public function testSchemaMismatchSkipsTableAsError(): void
    {
        $this->schemaValidator->method('validate')->willReturn(new ValidationResult(['login'], []));
        $this->connection->expects(self::never())->method('executeStatement');

        $report = $this->importer()->import(true, true);

        self::assertTrue($report->hasErrors());
        self::assertSame(1, $report->getTablesSkipped());
        self::assertSame(0, $report->getTablesImported());
        self::assertSame(ImportReport::CODE_SCHEMA_MISMATCH, $report->getFindings()[0]->getCode());
        self::assertSame('public.users', $report->getFindings()[0]->getTarget());
    }

    public function testRowCountAfterImportIsComparedWithTheFile(): void
    {
        $this->schemaValidator->method('validate')->willReturn(new ValidationResult());
        $this->connection->method('fetchAllAssociative')->willReturnCallback(function (string $sql): array {
            if (strpos($sql, 'COUNT(*)') !== false && strpos($sql, '"public"."users"') !== false) {
                return [['c' => 1]];
            }

            return [];
        });

        $report = $this->importer()->import(true, true);

        self::assertSame(1, $report->getTablesImported());
        self::assertSame(2, $report->getRowsLoaded());
        self::assertCount(1, $report->getFindings());
        $finding = $report->getFindings()[0];
        self::assertSame(ImportReport::CODE_ROW_COUNT, $finding->getCode());
        self::assertSame(['db_rows' => 1, 'dump_rows' => 2], $finding->getSuggestion());
        self::assertSame(['I-2' => 1], $report->toArray()['summary']['by_code']);
    }

    public function testMatchingRowCountAndFreshSequenceAreSilent(): void
    {
        $this->schemaValidator->method('validate')->willReturn(new ValidationResult());
        $this->connection->method('fetchAllAssociative')->willReturnCallback(function (string $sql): array {
            if (strpos($sql, 'COUNT(*)') !== false && strpos($sql, '"public"."users"') !== false) {
                return [['c' => 2]];
            }
            if (strpos($sql, 'pg_depend') !== false) {
                return [['column_name' => 'id', 'seq_schema' => 'public', 'seq_name' => 'users_id_seq']];
            }
            if (strpos($sql, 'last_value') !== false) {
                return [['last_value' => 2, 'is_called' => true]];
            }
            if (strpos($sql, 'MAX(') !== false) {
                return [['m' => 2]];
            }

            return [];
        });

        $report = $this->importer()->import(true, true);

        self::assertSame([], $report->getFindings());
    }

    public function testLaggingSequenceIsAWarning(): void
    {
        $this->schemaValidator->method('validate')->willReturn(new ValidationResult());
        $this->connection->method('fetchAllAssociative')->willReturnCallback(function (string $sql): array {
            if (strpos($sql, 'COUNT(*)') !== false) {
                return [['c' => 2]];
            }
            if (strpos($sql, 'pg_depend') !== false) {
                return [['column_name' => 'id', 'seq_schema' => 'public', 'seq_name' => 'users_id_seq']];
            }
            if (strpos($sql, 'last_value') !== false) {
                return [['last_value' => 1, 'is_called' => false]];
            }
            if (strpos($sql, 'MAX(') !== false) {
                return [['m' => 2]];
            }

            return [];
        });

        $report = $this->importer()->import(true, true);

        self::assertFalse($report->hasErrors());
        self::assertCount(1, $report->getFindings());
        self::assertSame(ImportReport::CODE_SEQUENCE, $report->getFindings()[0]->getCode());
        self::assertSame('id', $report->getFindings()[0]->getColumn());
    }

    public function testForeignKeyViolationIsRecordedBeforeRethrow(): void
    {
        $this->schemaValidator->method('validate')->willReturn(new ValidationResult());
        $this->connection->method('executeStatement')->willThrowException(
            new \RuntimeException('SQLSTATE[23503]: insert or update on table "users" violates foreign key constraint "users_group_fk"')
        );
        $importer = $this->importer();

        try {
            $importer->import(true, true);
            self::fail('исключение должно пробрасываться — транзакция откатывается');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('23503', $e->getMessage());
        }

        $findings = $importer->getReport()->getFindings();
        self::assertCount(1, $findings);
        self::assertSame(ImportReport::CODE_FOREIGN_KEY, $findings[0]->getCode());
        self::assertSame('public.users', $findings[0]->getTarget());
    }

    private function importer(): DatabaseImporter
    {
        $registry = $this->createMock(ConnectionRegistryInterface::class);
        $registry->method('getConnection')->willReturn($this->connection);
        $registry->method('getPlatform')->willReturn(new PostgresPlatform());

        $transactionManager = $this->createMock(TransactionManager::class);
        $transactionManager->method('transaction')->willReturnCallback(function (callable $callback) {
            return $callback();
        });
        $dependencyResolver = $this->createMock(TableDependencyResolver::class);
        $dependencyResolver->method('sortForImport')->willReturnArgument(0);

        return new DatabaseImporter(
            $registry,
            new DumpConfig([], []),
            $this->fileSystem,
            $this->createMock(ProductionGuard::class),
            $transactionManager,
            $this->createMock(ScriptExecutor::class),
            $this->parser,
            $this->createMock(LoggerInterface::class),
            $this->projectDir,
            $dependencyResolver,
            $this->schemaValidator,
            null,
            null,
            new DumpValueReader()
        );
    }
}
