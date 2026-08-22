<?php

namespace Timbrs\DatabaseDumps\Tests\Unit\Service\Validation\Rule;

use Timbrs\DatabaseDumps\Service\Validation\Rule\HistoryRule;
use Timbrs\DatabaseDumps\Tests\Support\ValidationTestCase;

class HistoryRuleTest extends ValidationTestCase
{
    /**
     * @param array<int, array<string, mixed>> $criteria
     * @param array<string, string> $columns
     * @return array<int, \Timbrs\DatabaseDumps\Service\Validation\Finding>
     */
    private function findings(array $criteria, array $columns = []): array
    {
        if (empty($columns)) {
            $columns = [
                'id' => 'bigint',
                'active_flg' => 'boolean',
                'date_from' => 'timestamp without time zone',
                'date_to' => 'timestamp without time zone',
            ];
        }

        $files = $this->splitConfig([
            'pub' => ['partial_export' => ['versions' => [
                'limit' => 500,
                'sample' => ['criteria' => $criteria],
            ]]],
        ]);
        $files[self::INVENTORY_PATH] = $this->inventoryJson([
            'pub' => ['versions' => ['row_count' => 1000, 'columns' => $columns]],
        ]);

        return (new HistoryRule())->apply($this->context($files));
    }

    public function testOnlyCurrentVersionsIsANote(): void
    {
        $findings = $this->findings([
            ['name' => 'active', 'where' => "active_flg = true AND date_to = '2100-01-01 00:00:00'", 'limit' => 10],
        ]);

        $this->assertSame(1, $this->countCode($findings, 'H-1'));
    }

    public function testClosedVersionsCriterionSilencesTheRule(): void
    {
        $findings = $this->findings([
            ['name' => 'active', 'where' => "active_flg = true AND date_to = '2100-01-01 00:00:00'", 'limit' => 10],
            ['name' => 'inactive', 'where' => 'active_flg = false', 'limit' => 10],
        ]);

        $this->assertSame([], $this->codes($findings));
    }

    public function testClosedByDateComparison(): void
    {
        $findings = $this->findings([
            ['name' => 'past', 'where' => "date_to < '2026-01-01'", 'limit' => 10],
        ]);

        $this->assertSame([], $this->codes($findings));
    }

    public function testNonVersionedTableIsNotJudged(): void
    {
        $findings = $this->findings(
            [['name' => 'any', 'where' => 'id > 0', 'limit' => 10]],
            ['id' => 'bigint', 'active_flg' => 'boolean']
        );

        $this->assertSame([], $this->codes($findings));
    }
}
