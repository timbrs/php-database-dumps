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
}
