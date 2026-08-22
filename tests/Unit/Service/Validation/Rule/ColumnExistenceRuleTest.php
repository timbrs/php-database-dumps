<?php

namespace Timbrs\DatabaseDumps\Tests\Unit\Service\Validation\Rule;

use Timbrs\DatabaseDumps\Service\Validation\Finding;
use Timbrs\DatabaseDumps\Service\Validation\Rule\ColumnExistenceRule;
use Timbrs\DatabaseDumps\Tests\Support\ValidationTestCase;

class ColumnExistenceRuleTest extends ValidationTestCase
{
    /**
     * @param array<string, mixed> $orders настройки таблицы pub.orders
     * @param array<string, array<string, string>> $faker
     * @return array<int, Finding>
     */
    private function findings(array $orders, array $faker = []): array
    {
        $schema = ['partial_export' => ['orders' => $orders, 'clients' => ['limit' => 10]]];
        if (!empty($faker)) {
            $schema['faker'] = $faker;
        }

        $files = $this->splitConfig(['pub' => $schema]);
        $files[self::INVENTORY_PATH] = $this->inventoryJson([
            'pub' => [
                'orders' => [
                    'row_count' => 100,
                    'columns' => [
                        'id' => 'bigint',
                        'client_id' => 'bigint',
                        'status' => 'integer',
                        'name' => 'character varying',
                        'created_at' => 'timestamp without time zone',
                    ],
                ],
                'clients' => ['row_count' => 50, 'columns' => ['id' => 'bigint', 'title' => 'character varying']],
                'orders_lines' => ['row_count' => 10, 'columns' => ['order_id' => 'bigint', 'qty' => 'integer']],
            ],
        ]);

        return (new ColumnExistenceRule())->apply($this->context($files));
    }

    public function testUnknownOrderByColumn(): void
    {
        $findings = $this->findings(['limit' => 10, 'order_by' => 'missing_col DESC']);

        $finding = $this->firstWithCode($findings, 'L-1');
        $this->assertNotNull($finding);
        $this->assertSame(Finding::SEVERITY_ERROR, $finding->getSeverity());
        $this->assertSame('missing_col', $finding->getColumn());
    }

    public function testKnownOrderByWithDirectionAndNullsIsFine(): void
    {
        $findings = $this->findings(['limit' => 10, 'order_by' => 'id DESC, created_at ASC NULLS LAST']);

        $this->assertSame(0, $this->countCode($findings, 'L-1'));
    }

    public function testOrderByExpressionIsNotJudged(): void
    {
        $findings = $this->findings(['limit' => 10, 'order_by' => 'coalesce(created_at, now()) DESC']);

        $this->assertSame(0, $this->countCode($findings, 'L-1'));
    }

    public function testUnknownWhereColumn(): void
    {
        $findings = $this->findings(['limit' => 10, 'where' => 'deleted_flg = false']);

        $finding = $this->firstWithCode($findings, 'L-2');
        $this->assertNotNull($finding);
        $this->assertSame(Finding::SEVERITY_ERROR, $finding->getSeverity());
    }

    public function testTrivialWhereIsFine(): void
    {
        $this->assertSame([], $this->codes($this->findings(['limit' => 10, 'where' => '1=1'])));
    }

    public function testDeadCascadeColumnIsFixable(): void
    {
        $findings = $this->findings([
            'limit' => 10,
            'cascade_from' => [['parent' => 'pub.clients', 'fk_column' => 'no_such_col', 'parent_column' => 'id']],
        ]);

        $finding = $this->firstWithCode($findings, 'L-3');
        $this->assertNotNull($finding);
        $this->assertTrue($finding->isFixable());
        $this->assertSame('remove_cascade_entry', $finding->getSuggestion()['fix']);
    }

    public function testDeadParentColumnIsAlsoCaught(): void
    {
        $findings = $this->findings([
            'limit' => 10,
            'cascade_from' => [['parent' => 'pub.clients', 'fk_column' => 'client_id', 'parent_column' => 'gone']],
        ]);

        $this->assertSame(1, $this->countCode($findings, 'L-3'));
    }

    public function testLiveCascadeIsSilent(): void
    {
        $findings = $this->findings([
            'limit' => 10,
            'cascade_from' => [['parent' => 'pub.clients', 'fk_column' => 'client_id', 'parent_column' => 'id']],
        ]);

        $this->assertSame(0, $this->countCode($findings, 'L-3'));
    }

    public function testUnknownCriterionColumn(): void
    {
        $findings = $this->findings([
            'limit' => 10,
            'sample' => ['criteria' => [['name' => 'bad', 'where' => 'unknown_flag = true', 'limit' => 5]]],
        ]);

        $finding = $this->firstWithCode($findings, 'L-4');
        $this->assertNotNull($finding);
        $this->assertSame(Finding::SEVERITY_ERROR, $finding->getSeverity());
    }

    /**
     * EXISTS-подзапрос приносит с собой колонки чужой таблицы — они не «неизвестные».
     * Именно на этом ломалась ручная проверка: 2 ложных срабатывания на реальном конфиге.
     */
    public function testExistsSubqueryResolvesForeignColumns(): void
    {
        $findings = $this->findings([
            'limit' => 10,
            'sample' => ['criteria' => [[
                'name' => 'with_lines',
                'where' => 'EXISTS (SELECT 1 FROM pub.orders_lines WHERE orders_lines.order_id = orders.id)',
                'limit' => 5,
            ]]],
        ]);

        $this->assertSame(0, $this->countCode($findings, 'L-4'));
    }

    public function testUnresolvedNameInsideSubqueryIsOnlyAWarning(): void
    {
        $findings = $this->findings([
            'limit' => 10,
            'sample' => ['criteria' => [[
                'name' => 'weird',
                'where' => 'EXISTS (SELECT 1 FROM pub.orders_lines WHERE what_is_this = 1)',
                'limit' => 5,
            ]]],
        ]);

        $finding = $this->firstWithCode($findings, 'L-4');
        $this->assertNotNull($finding);
        $this->assertSame(Finding::SEVERITY_WARNING, $finding->getSeverity());
    }

    public function testUnknownStratifyColumn(): void
    {
        $findings = $this->findings([
            'limit' => 10,
            'sample' => ['stratify_by' => 'no_such', 'per_value' => 5],
        ]);

        $this->assertSame(1, $this->countCode($findings, 'L-5'));
    }

    public function testFakerOnMissingColumnIsFixableWarning(): void
    {
        $findings = $this->findings(['limit' => 10], ['orders' => ['create_date_chd' => 'phone']]);

        $finding = $this->firstWithCode($findings, 'L-6');
        $this->assertNotNull($finding);
        $this->assertSame(Finding::SEVERITY_WARNING, $finding->getSeverity());
        $this->assertTrue($finding->isFixable());
    }

    public function testUnknownDeferredColumn(): void
    {
        $findings = $this->findings([
            'limit' => 10,
            'deferred_columns' => [[
                'column' => 'ghost_id',
                'reference_table' => 'pub.clients',
                'reference_column' => 'id',
            ]],
        ]);

        $this->assertSame(1, $this->countCode($findings, 'L-7'));
    }

    public function testTableOutsideInventoryIsSkipped(): void
    {
        $files = $this->splitConfig([
            'pub' => ['partial_export' => ['ghost' => ['limit' => 10, 'order_by' => 'whatever DESC']]],
        ]);
        $files[self::INVENTORY_PATH] = $this->inventoryJson([
            'pub' => ['orders' => ['row_count' => 1, 'columns' => ['id' => 'bigint']]],
        ]);

        $this->assertSame([], $this->codes((new ColumnExistenceRule())->apply($this->context($files))));
    }
}
