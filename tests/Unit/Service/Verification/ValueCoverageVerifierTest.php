<?php

namespace Timbrs\DatabaseDumps\Tests\Unit\Service\Verification;

use PHPUnit\Framework\TestCase;
use Timbrs\DatabaseDumps\Config\TableConfig;
use Timbrs\DatabaseDumps\Service\Validation\Finding;
use Timbrs\DatabaseDumps\Service\Validation\InventoryReader;
use Timbrs\DatabaseDumps\Service\Verification\DumpValueReader;
use Timbrs\DatabaseDumps\Service\Verification\DumpVerificationInput;
use Timbrs\DatabaseDumps\Service\Verification\DumpVerificationRunner;
use Timbrs\DatabaseDumps\Service\Verification\ValueCoverageVerifier;
use Timbrs\DatabaseDumps\Tests\Support\InMemoryFileSystem;

class ValueCoverageVerifierTest extends TestCase
{
    /** @var string */
    private $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/coverage_' . bin2hex(random_bytes(4));
        mkdir($this->root . '/public', 0777, true);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->root . '/public/*') ?: []);
        rmdir($this->root . '/public');
        rmdir($this->root);
    }

    public function testMissingCodesAreNamedWithoutLeakingOtherValues(): void
    {
        $this->dump([['red', 'Иванов'], ['green', 'Петров'], ['red', 'Сидоров']]);
        $inventory = $this->inventory([
            ['column' => 'status', 'data_type' => 'character varying', 'distinct_count' => 3, 'categorical' => true,
                'codes' => ['red', 'green', 'blocked'], 'codes_complete' => true, 'n_distinct_source' => 'pg_stats'],
            ['column' => 'owner', 'data_type' => 'character varying', 'distinct_count' => 3, 'categorical' => false],
        ]);

        $findings = $this->verify($inventory);

        self::assertCount(1, $findings);
        $finding = $findings[0];
        self::assertSame(ValueCoverageVerifier::CODE_COVERAGE_GAP, $finding->getCode());
        self::assertSame(Finding::SEVERITY_WARNING, $finding->getSeverity());
        self::assertSame('status', $finding->getColumn());
        self::assertSame(['blocked'], $finding->getSuggestion()['missing_codes']);
        self::assertSame(2, $finding->getSuggestion()['dump_distinct']);
        // Значения ФИО из колонки без кодов в отчёт не попадают.
        $encoded = (string) json_encode($finding->toArray(), JSON_UNESCAPED_UNICODE);
        self::assertStringNotContainsString('Иванов', $encoded);
    }

    public function testCategoricalColumnWithoutCodesIsComparedByDistinctCount(): void
    {
        $this->dump([['red', 'a'], ['red', 'b']]);
        $inventory = $this->inventory([
            ['column' => 'status', 'data_type' => 'character varying', 'distinct_count' => 3, 'categorical' => true, 'n_distinct_source' => 'sample'],
        ]);

        $findings = $this->verify($inventory);

        self::assertCount(1, $findings);
        self::assertSame(['expected_distinct' => 3, 'dump_distinct' => 1, 'distinct_source' => 'sample', 'dump_rows' => 2], $findings[0]->getSuggestion());
    }

    public function testFullCoverageIsSilent(): void
    {
        $this->dump([['red', 'a'], ['green', 'b'], ['blocked', 'c']]);
        $inventory = $this->inventory([
            ['column' => 'status', 'data_type' => 'character varying', 'distinct_count' => 3, 'categorical' => true,
                'codes' => ['red', 'green', 'blocked'], 'codes_complete' => true, 'n_distinct_source' => 'pg_stats'],
        ]);

        self::assertSame([], $this->verify($inventory));
    }

    public function testWithoutInventoryNothingIsChecked(): void
    {
        $this->dump([['red', 'a']]);

        $runner = new DumpVerificationRunner(new DumpValueReader(), [new ValueCoverageVerifier()]);
        $result = $runner->run(new DumpVerificationInput($this->root, [new TableConfig('public', 'clients', 10)]));

        self::assertSame([], $result['findings']);
        self::assertSame(['columns_checked' => 0, 'gaps' => 0], $result['stats']['ValueCoverageVerifier']);
    }

    /**
     * @return array<int, Finding>
     */
    private function verify(InventoryReader $inventory): array
    {
        $runner = new DumpVerificationRunner(new DumpValueReader(), [new ValueCoverageVerifier()]);

        return $runner->run(new DumpVerificationInput($this->root, [new TableConfig('public', 'clients', 10)], $inventory))['findings'];
    }

    /**
     * @param array<int, array{0: string, 1: string}> $rows status, owner
     */
    private function dump(array $rows): void
    {
        $tuples = [];
        foreach ($rows as $i => $row) {
            $tuples[] = sprintf("(%d, '%s', '%s')", $i + 1, $row[0], $row[1]);
        }
        file_put_contents(
            $this->root . '/public/clients.sql',
            "INSERT INTO \"public\".\"clients\" (\"id\", \"status\", \"owner\") VALUES\n" . implode(",\n", $tuples) . ";\n"
        );
    }

    /**
     * @param array<int, array<string, mixed>> $profiles
     */
    private function inventory(array $profiles): InventoryReader
    {
        $path = '/proj/database/analysis/schema_inventory.json';
        $json = json_encode([
            'generated_at' => '2026-01-15T00:00:00Z',
            'database_platform' => 'postgresql',
            'connection' => 'default',
            'schemas' => ['public' => ['tables' => ['clients' => [
                'row_count' => 3,
                'columns' => [
                    ['name' => 'id', 'type' => 'bigint', 'nullable' => false],
                    ['name' => 'status', 'type' => 'character varying', 'nullable' => true],
                    ['name' => 'owner', 'type' => 'character varying', 'nullable' => true],
                ],
                'foreign_keys' => [],
                'profiles' => $profiles,
            ]]]],
        ]);

        return new InventoryReader(new InMemoryFileSystem([$path => (string) $json]), $path);
    }
}
