<?php

namespace Timbrs\DatabaseDumps\Tests\Unit\Service\Verification\Sink;

use PHPUnit\Framework\TestCase;
use Timbrs\DatabaseDumps\Service\Verification\Sink\CountingSetSink;
use Timbrs\DatabaseDumps\Service\Verification\Sink\SampleSink;

class SinksTest extends TestCase
{
    public function testCountingSetKeepsCountsNullsAndStringKeys(): void
    {
        $sink = new CountingSetSink();
        foreach (['1', '1', '01', null, 'A'] as $value) {
            $sink->accept($value);
        }

        self::assertSame(3, $sink->distinct());
        self::assertSame(1, $sink->nulls());
        self::assertSame(5, $sink->total());
        self::assertSame(4, $sink->nonNull());
        self::assertSame(2, $sink->count('1'));
        self::assertTrue($sink->has('01'));
        self::assertFalse($sink->has('2'));
        // Числовые строки не превращаются в int при выдаче наружу.
        self::assertSame(['1', '01', 'A'], $sink->values());
        self::assertSame(['1' => 2, '01' => 1, 'A' => 1], $sink->counts());
        self::assertFalse($sink->isCapped());
    }

    public function testCountingSetStopsRememberingNewValuesAfterCap(): void
    {
        $sink = new CountingSetSink(2);
        foreach (['a', 'b', 'c', 'a'] as $value) {
            $sink->accept($value);
        }

        self::assertTrue($sink->isCapped());
        self::assertSame(2, $sink->distinct());
        // Уже известные значения продолжают считаться и после потолка.
        self::assertSame(2, $sink->count('a'));
        self::assertSame(4, $sink->total());
    }

    public function testCountingSetTruncatesLongValues(): void
    {
        $sink = new CountingSetSink(0, 3);
        $sink->accept('abcdef');
        $sink->accept('abcxyz');

        self::assertSame(['abc'], $sink->values());
        self::assertSame(2, $sink->count('abc'));
    }

    public function testSampleKeepsOnlyFirstNonEmptyValues(): void
    {
        $sink = new SampleSink(2);
        foreach ([null, '', 'x', 'y', 'z'] as $value) {
            $sink->accept($value);
        }

        self::assertSame(['x', 'y'], $sink->values());
        self::assertSame(3, $sink->nonNull());
        self::assertSame(5, $sink->total());
        self::assertTrue($sink->isSampled());
    }
}
