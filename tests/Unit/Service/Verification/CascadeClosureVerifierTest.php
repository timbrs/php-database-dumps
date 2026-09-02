<?php

namespace Timbrs\DatabaseDumps\Tests\Unit\Service\Verification;

use PHPUnit\Framework\TestCase;
use Timbrs\DatabaseDumps\Config\TableConfig;
use Timbrs\DatabaseDumps\Service\Verification\CascadeClosureVerifier;
use Timbrs\DatabaseDumps\Service\Verification\DumpValueReader;

class CascadeClosureVerifierTest extends TestCase
{
    /** @var string */
    private $root;

    /** @var CascadeClosureVerifier */
    private $verifier;

    /** @var array<int, array{parent: string, fk_column: string, parent_column: string}> */
    private $cascade = [
        ['parent' => 'persons.persons', 'fk_column' => 'person_id', 'parent_column' => 'id'],
    ];

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/closure_' . bin2hex(random_bytes(4));
        mkdir($this->root . '/persons', 0777, true);
        $this->verifier = new CascadeClosureVerifier(new DumpValueReader());
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->root . '/persons/*') ?: []);
        rmdir($this->root . '/persons');
        rmdir($this->root);
    }

    public function testClosedCascadeProducesNoFindings(): void
    {
        $this->dump('persons', 'core_id', ['10', '11', '12']);
        $this->dump('persons_additional', 'person_id', ['1', '2', null, '3']);

        $result = $this->verifier->verify([$this->parent(), $this->child()], $this->root);

        self::assertSame([], $result['findings']);
        self::assertSame(1, $result['checked']);
        self::assertSame(0, $result['orphan_rows']);
    }

    public function testOrphanRowsAreReportedWithRate(): void
    {
        $this->dump('persons', 'core_id', ['10', '11', '12']);
        // id родителя в дампе — 1..3; 99 среди них нет.
        $this->dump('persons_additional', 'person_id', ['1', '2', '99']);

        $result = $this->verifier->verify([$this->parent(), $this->child()], $this->root);

        self::assertCount(1, $result['findings']);
        $finding = $result['findings'][0];
        self::assertSame(CascadeClosureVerifier::CODE_ORPHANS, $finding->getCode());
        self::assertSame('error', $finding->getSeverity());

        $suggestion = $finding->getSuggestion();
        self::assertSame(1, $suggestion['orphans']);
        self::assertSame(33.3, $suggestion['orphan_rate']);
        self::assertSame('persons.persons', $suggestion['parent']);
    }

    public function testNullForeignKeysAreNotOrphans(): void
    {
        $this->dump('persons', 'core_id', ['10']);
        $this->dump('persons_additional', 'person_id', [null, null, '1']);

        $result = $this->verifier->verify([$this->parent(), $this->child()], $this->root);

        self::assertSame([], $result['findings']);
    }

    public function testMissingForeignKeyColumnIsAnError(): void
    {
        $this->dump('persons', 'core_id', ['10']);
        $this->dump('persons_additional', 'person_id', ['1']);

        $wrong = [['parent' => 'persons.persons', 'fk_column' => 'persons_id', 'parent_column' => 'id']];
        $child = new TableConfig('persons', 'persons_additional', 100, null, null, null, $wrong);

        $result = $this->verifier->verify([$this->parent(), $child], $this->root);

        self::assertCount(1, $result['findings']);
        self::assertSame(CascadeClosureVerifier::CODE_COLUMN_MISSING, $result['findings'][0]->getCode());
        self::assertSame(0, $result['checked']);
    }

    public function testMissingParentDumpIsAWarning(): void
    {
        $this->dump('persons_additional', 'person_id', ['1']);

        $result = $this->verifier->verify([$this->parent(), $this->child()], $this->root);

        self::assertCount(1, $result['findings']);
        self::assertSame(CascadeClosureVerifier::CODE_NO_PARENT_DUMP, $result['findings'][0]->getCode());
    }

    public function testMissingChildDumpIsAWarning(): void
    {
        $this->dump('persons', 'core_id', ['10']);

        $result = $this->verifier->verify([$this->parent(), $this->child()], $this->root);

        self::assertCount(1, $result['findings']);
        self::assertSame(CascadeClosureVerifier::CODE_NO_DUMP, $result['findings'][0]->getCode());
        self::assertSame(1, $result['skipped']);
    }

    public function testFullExportParentIsSkippedBecauseClosureHoldsByConstruction(): void
    {
        $this->dump('persons_additional', 'person_id', ['1', '2', '99']);
        $parentFull = new TableConfig('persons', 'persons');

        $result = $this->verifier->verify([$parentFull, $this->child()], $this->root);

        self::assertSame([], $result['findings']);
        self::assertSame(0, $result['checked']);
        self::assertSame(1, $result['skipped']);
    }

    public function testParentAbsentFromConfigIsLeftToTheValidator(): void
    {
        // Мёртвого родителя ловит G-1 в validate — здесь сверять нечего, и молчание уместно.
        $this->dump('persons_additional', 'person_id', ['1']);

        $result = $this->verifier->verify([$this->child()], $this->root);

        self::assertSame([], $result['findings']);
        self::assertSame(1, $result['skipped']);
    }

    private function parent(): TableConfig
    {
        return new TableConfig('persons', 'persons', 100);
    }

    private function child(): TableConfig
    {
        return new TableConfig('persons', 'persons_additional', 100, null, null, null, $this->cascade);
    }

    /**
     * @param array<int, string|null> $values
     */
    private function dump(string $table, string $column, array $values): void
    {
        $rows = [];
        foreach ($values as $i => $value) {
            $rows[] = '(' . ($i + 1) . ', ' . ($value === null ? 'NULL' : $value) . ", 'x')";
        }

        file_put_contents(
            $this->root . '/persons/' . $table . '.sql',
            "TRUNCATE TABLE \"persons\".\"{$table}\" CASCADE;\n\n"
            . "INSERT INTO \"persons\".\"{$table}\" (\"id\", \"{$column}\", \"note\") VALUES\n"
            . implode(",\n", $rows) . ";\n"
        );
    }
}
