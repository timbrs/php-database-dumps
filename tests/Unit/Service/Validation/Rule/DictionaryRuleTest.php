<?php

namespace Timbrs\DatabaseDumps\Tests\Unit\Service\Validation\Rule;

use Timbrs\DatabaseDumps\Service\Validation\Finding;
use Timbrs\DatabaseDumps\Service\Validation\Rule\DictionaryRule;
use Timbrs\DatabaseDumps\Tests\Support\ValidationTestCase;

class DictionaryRuleTest extends ValidationTestCase
{
    /**
     * @param array<string, array<string, mixed>> $tables
     * @param array<string, array<string, mixed>> $inventoryTables
     * @return array<int, Finding>
     */
    private function findings(array $tables, array $inventoryTables): array
    {
        $files = $this->splitConfig(['pub' => ['partial_export' => $tables]]);
        $files[self::INVENTORY_PATH] = $this->inventoryJson(['pub' => $inventoryTables]);

        return (new DictionaryRule())->apply($this->context($files));
    }

    public function testDictionaryCutByLimit(): void
    {
        $findings = $this->findings(
            ['okveds_dict' => ['limit' => 500]],
            ['okveds_dict' => ['row_count' => 4397, 'columns' => ['code' => 'character varying']]]
        );

        $finding = $this->firstWithCode($findings, 'D-1');
        $this->assertNotNull($finding);
        $this->assertSame(Finding::SEVERITY_NOTE, $finding->getSeverity());
        $this->assertSame(500, $finding->getSuggestion()['cap']);
        $this->assertSame(4397, $finding->getSuggestion()['row_count']);
    }

    /**
     * Словарь на 13 строк с limit 500 формально влезает, но критерии режут его до 10.
     */
    public function testDictionaryCutBySampleQuota(): void
    {
        $findings = $this->findings(
            ['leads_attrs_dict' => [
                'limit' => 500,
                'sample' => ['criteria' => [['name' => 'active', 'where' => 'active_flg = true', 'limit' => 10]]],
            ]],
            ['leads_attrs_dict' => [
                'row_count' => 13,
                'columns' => ['id' => 'bigint', 'active_flg' => 'boolean'],
                'profiles' => ['active_flg' => ['distinct_count' => 1, 'distinct_capped' => false]],
            ]]
        );

        $finding = $this->firstWithCode($findings, 'D-1');
        $this->assertNotNull($finding);
        $this->assertSame(10, $finding->getSuggestion()['cap']);
        $this->assertStringContainsString('одно-единственное значение', $finding->getMessage());
    }

    public function testDictionaryThatFitsIsSilent(): void
    {
        $findings = $this->findings(
            ['small_dict' => ['limit' => 500]],
            ['small_dict' => ['row_count' => 42, 'columns' => ['id' => 'bigint']]]
        );

        $this->assertSame([], $this->codes($findings));
    }

    public function testNonDictionaryTableIsNotJudged(): void
    {
        $findings = $this->findings(
            ['orders' => ['limit' => 10]],
            ['orders' => ['row_count' => 100000, 'columns' => ['id' => 'bigint']]]
        );

        $this->assertSame([], $this->codes($findings));
    }
}
