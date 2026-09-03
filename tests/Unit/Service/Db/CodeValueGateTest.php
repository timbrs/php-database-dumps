<?php

namespace Timbrs\DatabaseDumps\Tests\Unit\Service\Db;

use PHPUnit\Framework\TestCase;
use Timbrs\DatabaseDumps\Service\Db\CodeValueGate;

class CodeValueGateTest extends TestCase
{
    public function testStatusCodesPass(): void
    {
        self::assertSame(['1', '2', '-4'], CodeValueGate::filter('status_id', 'integer', 3, 1000, ['1', '2', '-4']));
        self::assertSame(['RED', 'GREEN'], CodeValueGate::filter('color_code', 'character varying', 2, 50, ['RED', 'GREEN', null]));
        self::assertSame(['t', 'f'], CodeValueGate::filter('active_flg', 'boolean', 2, 100, ['t', 'f']));
    }

    public function testUnknownRowCountDoesNotBlock(): void
    {
        self::assertSame(['a', 'b'], CodeValueGate::filter('kind', 'text', 2, null, ['a', 'b']));
    }

    public function testDuplicatesCollapse(): void
    {
        self::assertSame(['a'], CodeValueGate::filter('kind', 'text', 1, 10, ['a', 'a']));
    }

    public function testTooManyOrNonRepeatingValuesAreNotCodes(): void
    {
        self::assertNull(CodeValueGate::filter('kind', 'text', 51, 1000, ['a']));
        self::assertNull(CodeValueGate::filter('inn_like', 'text', 10, 10, ['a']), 'значения не повторяются — ключ, не код');
        self::assertNull(CodeValueGate::filter('kind', 'text', null, 10, ['a']));
        self::assertNull(CodeValueGate::filter('kind', 'text', 0, 10, []));
    }

    public function testPiiLookingNamesAndTypesAreRejected(): void
    {
        self::assertNull(CodeValueGate::filter('inn', 'character varying', 5, 100, ['7707083893']));
        self::assertNull(CodeValueGate::filter('client_inn', 'character varying', 5, 100, ['7707083893']));
        self::assertNull(CodeValueGate::filter('last_name', 'character varying', 5, 100, ['Ivanov']));
        self::assertNull(CodeValueGate::filter('phone', 'character varying', 5, 100, ['79001234567']));
        self::assertNull(CodeValueGate::filter('birth_date', 'date', 5, 100, ['2000-01-01']));
        self::assertNull(CodeValueGate::filter('kind', 'timestamp without time zone', 5, 100, ['2000-01-01']));
        self::assertNull(CodeValueGate::filter('payload', 'jsonb', 5, 100, ['x']));
    }

    public function testValuesThatDoNotLookLikeCodesRejectTheWholeColumn(): void
    {
        self::assertNull(CodeValueGate::filter('kind', 'text', 2, 100, ['ok', 'Нижний Новгород']));
        self::assertNull(CodeValueGate::filter('kind', 'text', 2, 100, ['ok', 'with space']));
        self::assertNull(CodeValueGate::filter('kind', 'text', 2, 100, ['ok', str_repeat('x', 33)]));
        self::assertNull(CodeValueGate::filter('kind', 'text', 2, 100, ['ok', 'a@b.c']));
        self::assertNull(CodeValueGate::filter('kind', 'text', 2, 100, [null, null]));
    }
}
