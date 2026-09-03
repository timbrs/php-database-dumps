<?php

namespace Timbrs\DatabaseDumps\Tests\Unit\Service\Validation\Rule;

use Timbrs\DatabaseDumps\Service\Validation\Finding;
use Timbrs\DatabaseDumps\Service\Validation\Rule\FakerTypeRule;
use Timbrs\DatabaseDumps\Tests\Support\ValidationTestCase;

/**
 * F-1 на дате — самая дорогая находка в проде: именно из-за faker-паттерна «phone»
 * на 55 колонках timestamp/date/bytea импорт дампа падал целиком.
 */
class FakerTypeRuleTest extends ValidationTestCase
{
    /**
     * @param array<string, string> $faker
     * @return array<int, Finding>
     */
    private function findings(array $faker): array
    {
        $files = $this->splitConfig([
            'pdl' => [
                'partial_export' => ['accounts' => ['limit' => 100]],
                'faker' => ['accounts' => $faker],
            ],
        ]);
        $files[self::INVENTORY_PATH] = $this->inventoryJson([
            'pdl' => [
                'accounts' => [
                    'row_count' => 100,
                    'columns' => [
                        'id' => 'bigint',
                        'name' => 'character varying',
                        'date_entered' => 'timestamp without time zone',
                        'report_dt' => 'date',
                        'edw_row_hash' => 'bytea',
                        'client_uid' => 'numeric',
                        'active_flg' => 'boolean',
                        'external_id' => 'uuid',
                    ],
                ],
            ],
        ]);

        return (new FakerTypeRule())->apply($this->context($files));
    }

    public function testPhoneOnTimestampIsFixableError(): void
    {
        $findings = $this->findings(['date_entered' => 'phone']);

        $this->assertCount(1, $findings);
        $finding = $findings[0];
        $this->assertSame('F-1', $finding->getCode());
        $this->assertSame(Finding::SEVERITY_ERROR, $finding->getSeverity());
        $this->assertTrue($finding->isFixable());
        $this->assertSame('pdl.accounts.date_entered', $finding->getTarget());
        $this->assertSame('remove_faker_column', $finding->getSuggestion()['fix']);
    }

    public function testDateBytesBooleanAndUuidAreAllBreaking(): void
    {
        $findings = $this->findings([
            'report_dt' => 'phone',
            'edw_row_hash' => 'phone',
            'active_flg' => 'gender',
            'external_id' => 'fio',
        ]);

        $this->assertCount(4, $findings);
        foreach ($findings as $finding) {
            $this->assertSame(Finding::SEVERITY_ERROR, $finding->getSeverity());
            $this->assertTrue($finding->isFixable());
        }
    }

    public function testTextColumnIsFine(): void
    {
        $this->assertSame([], $this->findings(['name' => 'fio']));
    }

    /**
     * Числовая колонка — предупреждение, но НЕ автоправка: телефон, лежащий в bigint,
     * маскировать нужно, и решение о снятии маппинга принимает человек.
     */
    public function testNumericColumnIsWarningWithoutAutoFix(): void
    {
        $findings = $this->findings(['client_uid' => 'phone']);

        $this->assertCount(1, $findings);
        $this->assertSame('F-1', $findings[0]->getCode());
        $this->assertSame(Finding::SEVERITY_WARNING, $findings[0]->getSeverity());
        $this->assertFalse($findings[0]->isFixable());
    }

    public function testUnknownPatternIsReportedSeparately(): void
    {
        $findings = $this->findings(['name' => 'passport_scan']);

        $this->assertCount(1, $findings);
        $this->assertSame('F-2', $findings[0]->getCode());
        $this->assertSame(Finding::SEVERITY_ERROR, $findings[0]->getSeverity());
        $this->assertTrue($findings[0]->isFixable());
    }

    public function testColumnMissingFromInventoryIsLeftToColumnRule(): void
    {
        $this->assertSame([], $this->findings(['create_date_chd' => 'phone']));
    }
}
