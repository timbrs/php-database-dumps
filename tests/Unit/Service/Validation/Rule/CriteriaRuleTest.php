<?php

namespace Timbrs\DatabaseDumps\Tests\Unit\Service\Validation\Rule;

use Timbrs\DatabaseDumps\Service\Validation\Finding;
use Timbrs\DatabaseDumps\Service\Validation\Rule\CriteriaRule;
use Timbrs\DatabaseDumps\Tests\Support\ValidationTestCase;

class CriteriaRuleTest extends ValidationTestCase
{
    /**
     * @param array<string, mixed> $orders
     * @return array<int, Finding>
     */
    private function findings(array $orders): array
    {
        $files = $this->splitConfig(['pub' => ['partial_export' => ['orders' => $orders]]]);
        $files[self::INVENTORY_PATH] = $this->inventoryJson([
            'pub' => [
                'orders' => [
                    'row_count' => 10000,
                    'columns' => ['id' => 'bigint', 'status' => 'integer', 'product_id' => 'integer'],
                    'profiles' => [
                        'product_id' => ['distinct_count' => 8, 'distinct_capped' => false, 'categorical' => true],
                    ],
                ],
            ],
        ]);

        return (new CriteriaRule())->apply($this->context($files));
    }

    public function testTableAliasIsError(): void
    {
        $findings = $this->findings([
            'limit' => 500,
            'sample' => ['criteria' => [
                ['name' => 'ok', 'where' => 'status = 1', 'limit' => 10],
                ['name' => 'from_orm', 'where' => 't1.status = 2', 'limit' => 10],
            ]],
        ]);

        $finding = $this->firstWithCode($findings, 'Q-1');
        $this->assertNotNull($finding);
        $this->assertSame(Finding::SEVERITY_ERROR, $finding->getSeverity());
        $this->assertSame(0, $this->countCode($findings, 'Q-3'), 'один рабочий критерий остаётся');
    }

    public function testBindParameterIsError(): void
    {
        $findings = $this->findings([
            'limit' => 500,
            'sample' => ['criteria' => [
                ['name' => 'ok', 'where' => 'status = 1', 'limit' => 10],
                ['name' => 'bound', 'where' => 'status = :status', 'limit' => 10],
            ]],
        ]);

        $this->assertSame(1, $this->countCode($findings, 'Q-2'));
    }

    public function testPostgresCastIsNotABindParameter(): void
    {
        $findings = $this->findings([
            'limit' => 500,
            'sample' => ['criteria' => [['name' => 'casted', 'where' => "status::text = '1'", 'limit' => 10]]],
        ]);

        $this->assertSame([], $this->codes($findings));
    }

    public function testAllCriteriaBrokenIsReportedOnce(): void
    {
        $findings = $this->findings([
            'limit' => 500,
            'sample' => ['criteria' => [
                ['name' => 'a', 'where' => 't1.status = 1', 'limit' => 10],
                ['name' => 'b', 'where' => 'status = :s', 'limit' => 10],
            ]],
        ]);

        $q3 = $this->firstWithCode($findings, 'Q-3');
        $this->assertNotNull($q3);
        $this->assertStringContainsString('плоским срезом', $q3->getMessage());
    }

    public function testDuplicateNamesAreFixableWarning(): void
    {
        $findings = $this->findings([
            'limit' => 500,
            'sample' => ['criteria' => [
                ['name' => 'active', 'where' => 'status = 1', 'limit' => 10],
                ['name' => 'active', 'where' => 'status = 2', 'limit' => 10],
            ]],
        ]);

        $finding = $this->firstWithCode($findings, 'Q-4');
        $this->assertNotNull($finding);
        $this->assertTrue($finding->isFixable());
        $this->assertSame(1, $finding->getSuggestion()['index']);
        $this->assertSame('active', $finding->getSuggestion()['name']);
    }

    public function testQuotaOverLimitIsWarning(): void
    {
        $findings = $this->findings([
            'limit' => 15,
            'sample' => ['criteria' => [
                ['name' => 'a', 'where' => 'status = 1', 'limit' => 10],
                ['name' => 'b', 'where' => 'status = 2', 'limit' => 10],
            ]],
        ]);

        $finding = $this->firstWithCode($findings, 'Q-5');
        $this->assertNotNull($finding);
        $this->assertSame(20, $finding->getSuggestion()['quota']);
        $this->assertSame(15, $finding->getSuggestion()['limit']);
    }

    /**
     * Корзины stratify_by считаются по реальному числу значений из профиля,
     * а не по потолку в 50 — иначе почти каждая таблица выглядела бы обрезанной.
     */
    public function testStratifyBucketsComeFromProfile(): void
    {
        $findings = $this->findings([
            'limit' => 500,
            'sample' => ['stratify_by' => 'product_id', 'per_value' => 5],
        ]);

        $this->assertSame(0, $this->countCode($findings, 'Q-5'), '8 корзин × 5 = 40 < 500');
    }

    public function testStratifyWithoutProfileUsesWorstCase(): void
    {
        $findings = $this->findings([
            'limit' => 100,
            'sample' => ['stratify_by' => 'status', 'per_value' => 5],
        ]);

        $this->assertSame(1, $this->countCode($findings, 'Q-5'), '50 корзин × 5 = 250 > 100');
    }

    public function testQuotaWithinLimitIsSilent(): void
    {
        $findings = $this->findings([
            'limit' => 500,
            'sample' => ['criteria' => [['name' => 'a', 'where' => 'status = 1', 'limit' => 10]]],
        ]);

        $this->assertSame([], $this->codes($findings));
    }
}
