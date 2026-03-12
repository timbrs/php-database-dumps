<?php

namespace Timbrs\DatabaseDumps\Tests\Unit\Adapter;

use PHPUnit\Framework\TestCase;
use Timbrs\DatabaseDumps\Adapter\BooleanNormalizer;

class BooleanNormalizerTest extends TestCase
{
    public function testNormalizesStringTF(): void
    {
        $stmt = $this->createStmtMock([
            ['name' => 'id', 'native_type' => 'int4'],
            ['name' => 'is_active', 'native_type' => 'bool'],
            ['name' => 'name', 'native_type' => 'varchar'],
        ]);

        $rows = [
            ['id' => 1, 'is_active' => 't', 'name' => 'User 1'],
            ['id' => 2, 'is_active' => 'f', 'name' => 'User 2'],
        ];

        $result = BooleanNormalizer::normalize($stmt, $rows);

        $this->assertTrue($result[0]['is_active']);
        $this->assertFalse($result[1]['is_active']);
        $this->assertSame('User 1', $result[0]['name']);
        $this->assertSame(1, $result[0]['id']);
    }

    public function testNormalizesNativeBooleans(): void
    {
        $stmt = $this->createStmtMock([
            ['name' => 'id', 'native_type' => 'int4'],
            ['name' => 'is_active', 'native_type' => 'bool'],
        ]);

        $rows = [
            ['id' => 1, 'is_active' => true],
            ['id' => 2, 'is_active' => false],
        ];

        $result = BooleanNormalizer::normalize($stmt, $rows);

        $this->assertTrue($result[0]['is_active']);
        $this->assertFalse($result[1]['is_active']);
    }

    public function testPreservesNull(): void
    {
        $stmt = $this->createStmtMock([
            ['name' => 'is_active', 'native_type' => 'bool'],
        ]);

        $rows = [
            ['is_active' => null],
        ];

        $result = BooleanNormalizer::normalize($stmt, $rows);

        $this->assertNull($result[0]['is_active']);
    }

    public function testMultipleBooleanColumns(): void
    {
        $stmt = $this->createStmtMock([
            ['name' => 'is_active', 'native_type' => 'bool'],
            ['name' => 'is_deleted', 'native_type' => 'bool'],
        ]);

        $rows = [
            ['is_active' => 't', 'is_deleted' => 'f'],
            ['is_active' => 'f', 'is_deleted' => 't'],
        ];

        $result = BooleanNormalizer::normalize($stmt, $rows);

        $this->assertTrue($result[0]['is_active']);
        $this->assertFalse($result[0]['is_deleted']);
        $this->assertFalse($result[1]['is_active']);
        $this->assertTrue($result[1]['is_deleted']);
    }

    public function testNoBooleanColumnsReturnsUnchanged(): void
    {
        $stmt = $this->createStmtMock([
            ['name' => 'id', 'native_type' => 'int4'],
            ['name' => 'name', 'native_type' => 'varchar'],
        ]);

        $rows = [
            ['id' => 1, 'name' => 't'],
        ];

        $result = BooleanNormalizer::normalize($stmt, $rows);

        // Строковый 't' в не-boolean колонке не должен измениться
        $this->assertSame('t', $result[0]['name']);
    }

    public function testEmptyRowsReturnsEmpty(): void
    {
        $stmt = $this->createMock(\PDOStatement::class);

        $result = BooleanNormalizer::normalize($stmt, []);

        $this->assertSame([], $result);
    }

    /**
     * @param array<array{name: string, native_type: string}> $columns
     * @return \PDOStatement&\PHPUnit\Framework\MockObject\MockObject
     */
    private function createStmtMock(array $columns)
    {
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('columnCount')->willReturn(count($columns));
        $stmt->method('getColumnMeta')->willReturnCallback(
            function ($index) use ($columns) {
                return isset($columns[$index]) ? $columns[$index] : false;
            }
        );

        return $stmt;
    }
}
