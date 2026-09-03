<?php

namespace Timbrs\DatabaseDumps\Tests\Unit\Config;

use PHPUnit\Framework\TestCase;
use Timbrs\DatabaseDumps\Config\TableConfig;

class TableConfigStratifyTest extends TestCase
{
    public function testStringAndListFormsAreBothAccepted(): void
    {
        $single = TableConfig::fromArray('public', 'clients', ['limit' => 10, 'sample' => ['stratify_by' => 'status']]);
        $list = TableConfig::fromArray('public', 'clients', ['limit' => 10, 'sample' => ['stratify_by' => ['status', 'kind']]]);

        self::assertSame(['status'], TableConfig::stratifyColumns($single->getSample()));
        self::assertSame(['status', 'kind'], TableConfig::stratifyColumns($list->getSample()));
        self::assertSame([], TableConfig::stratifyColumns(null));
        self::assertSame([], TableConfig::stratifyColumns(['criteria' => []]));
    }

    public function testEmptyListIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('stratify_by');

        TableConfig::fromArray('public', 'clients', ['limit' => 10, 'sample' => ['stratify_by' => []]]);
    }

    public function testListItemsMustBeIdentifiers(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('stratify_by[1]');

        TableConfig::fromArray('public', 'clients', ['limit' => 10, 'sample' => ['stratify_by' => ['status', 'bad name']]]);
    }

    public function testDefaultPerValueIsAHundred(): void
    {
        self::assertSame(100, TableConfig::DEFAULT_PER_VALUE);
    }

    public function testNestedStratifyIsNormalized(): void
    {
        $config = TableConfig::fromArray('clients', 'clients_attrs', ['limit' => 1000, 'sample' => [
            'stratify' => [
                ['column' => 'attr_id', 'per_value' => 50, 'then' => ['column' => 'value_int', 'per_value' => 7]],
                ['column' => 'active_flg'],
            ],
        ]]);

        $specs = TableConfig::stratifySpecs($config->getSample());
        self::assertSame('attr_id', $specs[0]['column']);
        self::assertSame(50, $specs[0]['per_value']);
        self::assertSame(['column' => 'value_int', 'per_value' => 7, 'max_values' => TableConfig::DEFAULT_THEN_MAX_VALUES], $specs[0]['then']);
        self::assertNull($specs[1]['then']);
        // Первый уровень stratify виден правилам наравне со stratify_by.
        self::assertSame(['attr_id', 'active_flg'], TableConfig::stratifyColumns($config->getSample()));
    }

    public function testNestingDeeperThanOneLevelIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('stratify[0].then.then');

        TableConfig::fromArray('clients', 'clients_attrs', ['limit' => 10, 'sample' => [
            'stratify' => [['column' => 'a', 'then' => ['column' => 'b', 'then' => ['column' => 'c']]]],
        ]]);
    }

    public function testStratifyViaIsNormalizedAndValidated(): void
    {
        $config = TableConfig::fromArray('clients', 'clients', ['limit' => 500, 'sample' => [
            'stratify_via' => [[
                'table' => 'clients.clients_attrs',
                'join' => ['core_id' => 'core_id'],
                'where' => 'attr_id = 4 AND active_flg',
                'column' => 'value_string',
                'per_value' => 100,
            ]],
        ]]);

        $via = TableConfig::stratifyVia($config->getSample());
        self::assertCount(1, $via);
        self::assertSame('clients.clients_attrs', $via[0]['table']);
        self::assertSame(['core_id' => 'core_id'], $via[0]['join']);
        self::assertSame('attr_id = 4 AND active_flg', $via[0]['where']);
        self::assertSame('value_string', $via[0]['column']);
        self::assertSame(100, $via[0]['per_value']);
    }

    public function testStratifyViaRejectsSemicolonInWhereAndBadTable(): void
    {
        try {
            TableConfig::fromArray('clients', 'clients', ['limit' => 10, 'sample' => [
                'stratify_via' => [['table' => 'clients_attrs', 'join' => ['core_id' => 'core_id'], 'column' => 'v']],
            ]]);
            self::fail('table without schema must be rejected');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('stratify_via[0].table', $e->getMessage());
        }

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('stratify_via[0].where');
        TableConfig::fromArray('clients', 'clients', ['limit' => 10, 'sample' => [
            'stratify_via' => [['table' => 'clients.clients_attrs', 'join' => ['core_id' => 'core_id'], 'where' => 'attr_id = 4; DROP TABLE x', 'column' => 'v']],
        ]]);
    }
}
