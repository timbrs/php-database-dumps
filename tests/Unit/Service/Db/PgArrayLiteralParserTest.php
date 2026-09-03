<?php

namespace Timbrs\DatabaseDumps\Tests\Unit\Service\Db;

use PHPUnit\Framework\TestCase;
use Timbrs\DatabaseDumps\Service\Db\PgArrayLiteralParser;

class PgArrayLiteralParserTest extends TestCase
{
    public function testPlainElements(): void
    {
        self::assertSame(['a', 'b', 'c'], PgArrayLiteralParser::parse('{a,b,c}'));
        self::assertSame(['1', '-4', '2.5'], PgArrayLiteralParser::parse('{1,-4,2.5}'));
    }

    public function testEmptyArray(): void
    {
        self::assertSame([], PgArrayLiteralParser::parse('{}'));
    }

    public function testQuotedElementsWithCommasQuotesAndNulls(): void
    {
        $parsed = PgArrayLiteralParser::parse('{"b, c","with \\"quote\\"",NULL,"NULL","","back\\\\slash"}');

        self::assertSame(['b, c', 'with "quote"', null, 'NULL', '', 'back\\slash'], $parsed);
    }

    public function testDimensionDecorationIsIgnored(): void
    {
        self::assertSame(['x', 'y'], PgArrayLiteralParser::parse('[1:2]={x,y}'));
    }

    public function testSpacesAroundElementsAreTrimmed(): void
    {
        self::assertSame(['a', 'b'], PgArrayLiteralParser::parse('{ a , b }'));
    }

    public function testCyrillicValuesSurvive(): void
    {
        self::assertSame(['Иванов', 'Пётр Петров'], PgArrayLiteralParser::parse('{Иванов,"Пётр Петров"}'));
    }

    public function testGarbageIsNotAnArray(): void
    {
        self::assertNull(PgArrayLiteralParser::parse(null));
        self::assertNull(PgArrayLiteralParser::parse('not an array'));
        self::assertNull(PgArrayLiteralParser::parse('{unterminated'));
        self::assertNull(PgArrayLiteralParser::parse('{"open}'));
        self::assertNull(PgArrayLiteralParser::parse('{"a" b}'));
    }

    public function testFloats(): void
    {
        self::assertSame([0.5, 0.25], PgArrayLiteralParser::parseFloats('{0.5,0.25}'));
        self::assertNull(PgArrayLiteralParser::parseFloats('{a}'));
        self::assertNull(PgArrayLiteralParser::parseFloats(null));
    }
}
