<?php

namespace Timbrs\DatabaseDumps\Tests\Unit\Service\Verification;

use PHPUnit\Framework\TestCase;
use Timbrs\DatabaseDumps\Config\TableConfig;
use Timbrs\DatabaseDumps\Service\Validation\Finding;
use Timbrs\DatabaseDumps\Service\Validation\InventoryReader;
use Timbrs\DatabaseDumps\Service\Verification\DumpValueReader;
use Timbrs\DatabaseDumps\Service\Verification\DumpVerificationInput;
use Timbrs\DatabaseDumps\Service\Verification\DumpVerificationRunner;
use Timbrs\DatabaseDumps\Service\Verification\RowCountVerifier;
use Timbrs\DatabaseDumps\Tests\Support\InMemoryFileSystem;

class RowCountVerifierTest extends TestCase
{
    /** @var string */
    private $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/rowcount_' . bin2hex(random_bytes(4));
        mkdir($this->root . '/public', 0777, true);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->root . '/public/*') ?: []);
        rmdir($this->root . '/public');
        rmdir($this->root);
    }

    public function testFullExportMustMatchExactInventoryCount(): void
    {
        $this->dump('dict', 3);

        $findings = $this->verify([new TableConfig('public', 'dict')], $this->inventory(5, false));

        self::assertCount(1, $findings);
        self::assertSame(RowCountVerifier::CODE_ROW_COUNT, $findings[0]->getCode());
        self::assertSame(Finding::SEVERITY_WARNING, $findings[0]->getSeverity());
        self::assertSame(['dump_rows' => 3, 'inventory_rows' => 5, 'inventory_estimated' => false], $findings[0]->getSuggestion());
    }

    public function testEstimatedInventoryCountGetsTolerance(): void
    {
        $this->dump('dict', 3);

        self::assertSame([], $this->verify([new TableConfig('public', 'dict')], $this->inventory(5, true)));
    }

    public function testPartialExportAboveLimitIsAnError(): void
    {
        $this->dump('dict', 3);

        $findings = $this->verify([new TableConfig('public', 'dict', 2)], null);

        self::assertCount(1, $findings);
        self::assertSame(RowCountVerifier::CODE_ROW_COUNT, $findings[0]->getCode());
        self::assertSame(Finding::SEVERITY_ERROR, $findings[0]->getSeverity());
        self::assertSame(2, $findings[0]->getSuggestion()['expected_max']);
    }

    public function testSampleQuotasRaiseTheCeiling(): void
    {
        $this->dump('dict', 3);
        $config = TableConfig::fromArray('public', 'dict', [
            'limit' => 2,
            'sample' => ['criteria' => [
                ['name' => 'a', 'where' => 'x = 1', 'limit' => 2],
                ['name' => 'b', 'where' => 'x = 2', 'limit' => 2],
            ]],
        ]);

        self::assertSame([], $this->verify([$config], null));
    }

    public function testEmptyDumpOfNonEmptyTableIsAWarning(): void
    {
        $this->dump('dict', 0);

        $findings = $this->verify([new TableConfig('public', 'dict', 10)], $this->inventory(5, false));

        self::assertCount(1, $findings);
        self::assertSame(RowCountVerifier::CODE_ROW_COUNT, $findings[0]->getCode());
        self::assertSame(Finding::SEVERITY_WARNING, $findings[0]->getSeverity());
        self::assertStringContainsString('пуст', $findings[0]->getMessage());
    }

    public function testMissingFileIsReported(): void
    {
        $findings = $this->verify([new TableConfig('public', 'dict', 10)], null);

        self::assertCount(1, $findings);
        self::assertSame(RowCountVerifier::CODE_NO_DUMP, $findings[0]->getCode());
    }

    /**
     * @param array<int, TableConfig> $tables
     *
     * @return array<int, Finding>
     */
    private function verify(array $tables, ?InventoryReader $inventory): array
    {
        $runner = new DumpVerificationRunner(new DumpValueReader(), [new RowCountVerifier()]);

        return $runner->run(new DumpVerificationInput($this->root, $tables, $inventory))['findings'];
    }

    private function dump(string $table, int $rows): void
    {
        $sql = "TRUNCATE TABLE \"public\".\"{$table}\" CASCADE;\n";
        if ($rows > 0) {
            $tuples = [];
            for ($i = 1; $i <= $rows; $i++) {
                $tuples[] = "({$i}, 'v{$i}')";
            }
            $sql .= "INSERT INTO \"public\".\"{$table}\" (\"id\", \"code\") VALUES\n" . implode(",\n", $tuples) . ";\n";
        }
        file_put_contents($this->root . '/public/' . $table . '.sql', $sql);
    }

    private function inventory(int $rowCount, bool $estimated): InventoryReader
    {
        $path = '/proj/database/analysis/schema_inventory.json';
        $json = json_encode([
            'generated_at' => '2026-01-15T00:00:00Z',
            'database_platform' => 'postgresql',
            'connection' => 'default',
            'schemas' => ['public' => ['tables' => ['dict' => [
                'row_count' => $rowCount,
                'row_count_estimated' => $estimated,
                'columns' => [['name' => 'id', 'type' => 'bigint', 'nullable' => false]],
                'foreign_keys' => [],
                'profiles' => [],
            ]]]],
        ]);

        return new InventoryReader(new InMemoryFileSystem([$path => (string) $json]), $path);
    }
}
