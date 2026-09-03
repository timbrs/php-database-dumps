<?php

namespace Timbrs\DatabaseDumps\Tests\Unit\Service\Db;

use PHPUnit\Framework\TestCase;
use Timbrs\DatabaseDumps\Service\ConfigGenerator\TableInspector;
use Timbrs\DatabaseDumps\Service\Db\RowCounter;
use Timbrs\DatabaseDumps\Service\Db\RowEstimate;
use Timbrs\DatabaseDumps\Service\Db\SafeQueryPolicy;

class RowCounterTest extends TestCase
{
    /** @var TableInspector&\PHPUnit\Framework\MockObject\MockObject */
    private $inspector;

    protected function setUp(): void
    {
        $this->inspector = $this->createMock(TableInspector::class);
    }

    public function testSmallEstimateIsRefinedByExactCount(): void
    {
        $this->inspector->expects($this->once())->method('estimateRows')
            ->willReturn(new RowEstimate(120, true, RowEstimate::SOURCE_PG_CLASS));
        $this->inspector->expects($this->once())->method('countRows')->willReturn(123);

        $result = (new RowCounter($this->inspector, new SafeQueryPolicy(['max_scan_rows' => 1000])))
            ->count('public', 'small');

        self::assertSame(123, $result->getValue());
        self::assertFalse($result->isEstimated());
        self::assertSame(RowEstimate::SOURCE_COUNT, $result->getSource());
    }

    public function testLargeEstimateIsNeverCountedExactly(): void
    {
        $this->inspector->method('estimateRows')
            ->willReturn(new RowEstimate(5000000, true, RowEstimate::SOURCE_PG_CLASS));
        $this->inspector->expects($this->never())->method('countRows');

        $result = (new RowCounter($this->inspector, new SafeQueryPolicy(['max_scan_rows' => 1000])))
            ->count('public', 'big');

        self::assertSame(5000000, $result->getValue());
        self::assertTrue($result->isEstimated());
    }

    public function testUnknownSizeStaysUnknown(): void
    {
        // Таблица без статистики может оказаться самой большой — точно её не считаем.
        $this->inspector->method('estimateRows')->willReturn(RowEstimate::unknown());
        $this->inspector->expects($this->never())->method('countRows');

        $result = (new RowCounter($this->inspector))->count('public', 'fresh');

        self::assertFalse($result->isKnown());
        self::assertSame(RowEstimate::SOURCE_NONE, $result->getSource());
    }

    public function testExactFlagForcesCountRegardlessOfSize(): void
    {
        $this->inspector->expects($this->never())->method('estimateRows');
        $this->inspector->expects($this->once())->method('countRows')->willReturn(7000000);

        $result = (new RowCounter($this->inspector))->count('public', 'big', null, true);

        self::assertSame(7000000, $result->getValue());
        self::assertFalse($result->isEstimated());
    }

    public function testResultsAreCachedPerTable(): void
    {
        $this->inspector->expects($this->once())->method('estimateRows')
            ->willReturn(new RowEstimate(9000000, true, RowEstimate::SOURCE_PG_CLASS));

        $counter = new RowCounter($this->inspector);
        $counter->count('public', 'big');
        $counter->count('public', 'big');

        self::assertSame(9000000, $counter->count('public', 'big')->getValue());
    }
}
