<?php

namespace Timbrs\DatabaseDumps\Tests\Unit\Config;

use PHPUnit\Framework\TestCase;
use Timbrs\DatabaseDumps\Config\TableConfig;

class TableConfigTest extends TestCase
{
    public function testFullExportConfig(): void
    {
        $config = new TableConfig('users', 'users');

        $this->assertEquals('users', $config->getSchema());
        $this->assertEquals('users', $config->getTable());
        $this->assertEquals('users.users', $config->getFullTableName());
        $this->assertNull($config->getLimit());
        $this->assertNull($config->getWhere());
        $this->assertNull($config->getOrderBy());
        $this->assertTrue($config->isFullExport());
        $this->assertFalse($config->isPartialExport());
    }

    public function testPartialExportConfig(): void
    {
        $config = new TableConfig(
            'clients',
            'clients',
            100,
            'is_active = true',
            'created_at DESC'
        );

        $this->assertEquals('clients', $config->getSchema());
        $this->assertEquals('clients', $config->getTable());
        $this->assertEquals('clients.clients', $config->getFullTableName());
        $this->assertEquals(100, $config->getLimit());
        $this->assertEquals('is_active = true', $config->getWhere());
        $this->assertEquals('created_at DESC', $config->getOrderBy());
        $this->assertFalse($config->isFullExport());
        $this->assertTrue($config->isPartialExport());
    }

    public function testFromArrayFullExport(): void
    {
        $config = TableConfig::fromArray('users', 'users', []);

        $this->assertEquals('users', $config->getSchema());
        $this->assertEquals('users', $config->getTable());
        $this->assertTrue($config->isFullExport());
    }

    public function testFromArrayPartialExport(): void
    {
        $config = TableConfig::fromArray('clients', 'clients', [
            TableConfig::KEY_LIMIT => 100,
            TableConfig::KEY_WHERE => 'is_active = true',
            TableConfig::KEY_ORDER_BY => 'created_at DESC'
        ]);

        $this->assertEquals('clients', $config->getSchema());
        $this->assertEquals('clients', $config->getTable());
        $this->assertEquals(100, $config->getLimit());
        $this->assertEquals('is_active = true', $config->getWhere());
        $this->assertEquals('created_at DESC', $config->getOrderBy());
        $this->assertTrue($config->isPartialExport());
    }

    public function testConnectionNameDefaultIsNull(): void
    {
        $config = new TableConfig('users', 'users');
        $this->assertNull($config->getConnectionName());
    }

    public function testConnectionNameFromConstructor(): void
    {
        $config = new TableConfig('users', 'users', null, null, null, 'mysql');
        $this->assertEquals('mysql', $config->getConnectionName());
    }

    public function testFromArrayWithConnectionName(): void
    {
        $config = TableConfig::fromArray('app_db', 'events', [TableConfig::KEY_LIMIT => 500], 'mysql');
        $this->assertEquals('mysql', $config->getConnectionName());
        $this->assertEquals('app_db', $config->getSchema());
        $this->assertEquals(500, $config->getLimit());
    }

    public function testFromArrayWithoutConnectionName(): void
    {
        $config = TableConfig::fromArray('public', 'users', []);
        $this->assertNull($config->getConnectionName());
    }

    public function testCascadeFromDefaultIsNull(): void
    {
        $config = new TableConfig('public', 'users');
        $this->assertNull($config->getCascadeFrom());
    }

    public function testCascadeFromFromConstructor(): void
    {
        $cascadeFrom = [
            ['parent' => 'public.users', 'fk_column' => 'user_id', 'parent_column' => 'id'],
        ];
        $config = new TableConfig('public', 'orders', 500, null, 'id DESC', null, $cascadeFrom);
        $this->assertEquals($cascadeFrom, $config->getCascadeFrom());
    }

    public function testFromArrayWithCascadeFrom(): void
    {
        $cascadeFrom = [
            ['parent' => 'public.users', 'fk_column' => 'user_id', 'parent_column' => 'id'],
            ['parent' => 'public.categories', 'fk_column' => 'category_id', 'parent_column' => 'id'],
        ];
        $config = TableConfig::fromArray('public', 'orders', [
            TableConfig::KEY_LIMIT => 500,
            TableConfig::KEY_ORDER_BY => 'id DESC',
            TableConfig::KEY_CASCADE_FROM => $cascadeFrom,
        ]);
        $this->assertEquals($cascadeFrom, $config->getCascadeFrom());
        $this->assertEquals(500, $config->getLimit());
    }

    public function testFromArrayWithoutCascadeFrom(): void
    {
        $config = TableConfig::fromArray('public', 'users', []);
        $this->assertNull($config->getCascadeFrom());
    }

    public function testDeferredColumnsDefaultIsNull(): void
    {
        $config = new TableConfig('public', 'categories');
        $this->assertNull($config->getDeferredColumns());
    }

    public function testDeferredColumnsFromConstructor(): void
    {
        $deferred = [
            ['column' => 'parent_id', 'reference_table' => 'public.categories', 'reference_column' => 'id'],
        ];
        $config = new TableConfig('public', 'categories', null, null, null, null, null, $deferred);
        $this->assertEquals($deferred, $config->getDeferredColumns());
    }

    public function testFromArrayWithDeferredColumns(): void
    {
        $deferred = [
            ['column' => 'parent_id', 'reference_table' => 'public.categories', 'reference_column' => 'id'],
        ];
        $config = TableConfig::fromArray('public', 'categories', [
            TableConfig::KEY_DEFERRED_COLUMNS => $deferred,
        ]);
        $this->assertEquals($deferred, $config->getDeferredColumns());
    }

    public function testFromArrayWithoutDeferredColumns(): void
    {
        $config = TableConfig::fromArray('public', 'users', []);
        $this->assertNull($config->getDeferredColumns());
    }

    public function testSampleDefaultIsNull(): void
    {
        $config = new TableConfig('public', 'clients');
        $this->assertNull($config->getSample());
        $this->assertFalse($config->hasSample());
    }

    public function testValidSampleWithCriteria(): void
    {
        $sample = [
            TableConfig::SAMPLE_KEY_CRITERIA => [
                ['name' => 'red', 'where' => "status = 'red'", 'limit' => 10],
                ['name' => 'newish', 'where' => "created_at >= NOW() - INTERVAL '30 days'", 'limit' => 50],
            ],
        ];
        $config = TableConfig::fromArray('public', 'clients', [TableConfig::KEY_SAMPLE => $sample]);

        $this->assertTrue($config->hasSample());
        $this->assertSame($sample, $config->getSample());
    }

    public function testValidSampleWithExistsSubquery(): void
    {
        // EXISTS-подзапрос со сбалансированными скобками/кавычками проходит validateClause.
        $sample = [
            TableConfig::SAMPLE_KEY_CRITERIA => [
                [
                    'name' => 'vip',
                    'where' => "EXISTS (SELECT 1 FROM public.client_flags f WHERE f.client_id = clients.id AND f.flag = 'vip')",
                    'limit' => 30,
                ],
            ],
        ];
        $config = new TableConfig('public', 'clients', null, null, null, null, null, null, $sample);
        $this->assertTrue($config->hasSample());
    }

    public function testValidSampleWithStratifyBy(): void
    {
        $sample = [
            TableConfig::SAMPLE_KEY_STRATIFY_BY => 'status',
            TableConfig::SAMPLE_KEY_PER_VALUE => 10,
        ];
        $config = TableConfig::fromArray('public', 'clients', [TableConfig::KEY_SAMPLE => $sample]);
        $this->assertTrue($config->hasSample());
    }

    public function testSampleRejectsEmptyDefinition(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new TableConfig('public', 'clients', null, null, null, null, null, null, []);
    }

    public function testSampleRejectsCriterionWithSemicolon(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $sample = [
            TableConfig::SAMPLE_KEY_CRITERIA => [
                ['name' => 'evil', 'where' => "status = 'red'; DROP TABLE users", 'limit' => 10],
            ],
        ];
        new TableConfig('public', 'clients', null, null, null, null, null, null, $sample);
    }

    public function testSampleRejectsCriterionWithoutLimit(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $sample = [
            TableConfig::SAMPLE_KEY_CRITERIA => [
                ['name' => 'red', 'where' => "status = 'red'"],
            ],
        ];
        new TableConfig('public', 'clients', null, null, null, null, null, null, $sample);
    }

    public function testSampleRejectsNonIntLimit(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $sample = [
            TableConfig::SAMPLE_KEY_CRITERIA => [
                ['name' => 'red', 'where' => "status = 'red'", 'limit' => 'ten'],
            ],
        ];
        new TableConfig('public', 'clients', null, null, null, null, null, null, $sample);
    }

    public function testSampleRejectsInvalidCriterionName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $sample = [
            TableConfig::SAMPLE_KEY_CRITERIA => [
                ['name' => 'red-zone', 'where' => "status = 'red'", 'limit' => 10],
            ],
        ];
        new TableConfig('public', 'clients', null, null, null, null, null, null, $sample);
    }

    public function testSampleRejectsInvalidStratifyByIdentifier(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $sample = [TableConfig::SAMPLE_KEY_STRATIFY_BY => 'sta;tus'];
        new TableConfig('public', 'clients', null, null, null, null, null, null, $sample);
    }

    public function testSampleRejectsCriterionWithHashComment(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $sample = [
            TableConfig::SAMPLE_KEY_CRITERIA => [
                ['name' => 'evil', 'where' => "status = 'red' # comment", 'limit' => 10],
            ],
        ];
        new TableConfig('public', 'clients', null, null, null, null, null, null, $sample);
    }

    public function testWhereRejectsHashComment(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new TableConfig('public', 'clients', 10, 'id > 0 # drop');
    }

    public function testSampleRejectsZeroPerValue(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $sample = [
            TableConfig::SAMPLE_KEY_STRATIFY_BY => 'status',
            TableConfig::SAMPLE_KEY_PER_VALUE => 0,
        ];
        new TableConfig('public', 'clients', null, null, null, null, null, null, $sample);
    }

    public function testSampleRejectsNonIntPerValue(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $sample = [
            TableConfig::SAMPLE_KEY_STRATIFY_BY => 'status',
            TableConfig::SAMPLE_KEY_PER_VALUE => 5.5,
        ];
        new TableConfig('public', 'clients', null, null, null, null, null, null, $sample);
    }

    public function testSampleRejectsZeroCriterionLimit(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $sample = [
            TableConfig::SAMPLE_KEY_CRITERIA => [
                ['name' => 'red', 'where' => "status = 'red'", 'limit' => 0],
            ],
        ];
        new TableConfig('public', 'clients', null, null, null, null, null, null, $sample);
    }

    public function testSampleRejectsNonArrayCriteria(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $sample = [TableConfig::SAMPLE_KEY_CRITERIA => 'not-an-array'];
        new TableConfig('public', 'clients', null, null, null, null, null, null, $sample);
    }

    public function testSampleRejectsNonArrayCriterion(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $sample = [TableConfig::SAMPLE_KEY_CRITERIA => ['not-an-array']];
        new TableConfig('public', 'clients', null, null, null, null, null, null, $sample);
    }

    public function testSampleRejectsEmptyCriterionWhere(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $sample = [
            TableConfig::SAMPLE_KEY_CRITERIA => [
                ['name' => 'red', 'where' => '', 'limit' => 10],
            ],
        ];
        new TableConfig('public', 'clients', null, null, null, null, null, null, $sample);
    }

    public function testSampleAcceptsStratifyWithDefaultPerValue(): void
    {
        // per_value не задан — допустимо, билдер подставит DEFAULT_PER_VALUE.
        $sample = [TableConfig::SAMPLE_KEY_STRATIFY_BY => 'status'];
        $config = new TableConfig('public', 'clients', null, null, null, null, null, null, $sample);
        $this->assertTrue($config->hasSample());
    }

    public function testSampleAcceptsCriteriaAndStratifyTogether(): void
    {
        $sample = [
            TableConfig::SAMPLE_KEY_CRITERIA => [
                ['name' => 'red', 'where' => "status = 'red'", 'limit' => 10],
            ],
            TableConfig::SAMPLE_KEY_STRATIFY_BY => 'region',
            TableConfig::SAMPLE_KEY_PER_VALUE => 5,
        ];
        $config = new TableConfig('public', 'clients', null, null, null, null, null, null, $sample);
        $this->assertTrue($config->hasSample());
    }

    public function testFromArrayRejectsNonArraySample(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        TableConfig::fromArray('public', 'clients', [TableConfig::KEY_SAMPLE => 'nope']);
    }

    public function testWithDeferredColumnsPreservesSample(): void
    {
        $sample = [
            TableConfig::SAMPLE_KEY_CRITERIA => [
                ['name' => 'red', 'where' => "status = 'red'", 'limit' => 10],
            ],
        ];
        $config = new TableConfig('public', 'clients', null, null, null, null, null, null, $sample);
        $updated = $config->withDeferredColumns([
            ['column' => 'parent_id', 'reference_table' => 'public.x', 'reference_column' => 'id'],
        ]);
        $this->assertSame($sample, $updated->getSample());
    }
}
