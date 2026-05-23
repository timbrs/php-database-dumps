<?php

namespace Timbrs\DatabaseDumps\Tests\Unit\Service\ConfigGenerator;

use PHPUnit\Framework\TestCase;
use Timbrs\DatabaseDumps\Contract\ConnectionRegistryInterface;
use Timbrs\DatabaseDumps\Contract\DatabaseConnectionInterface;
use Timbrs\DatabaseDumps\Platform\PostgresPlatform;
use Timbrs\DatabaseDumps\Service\ConfigGenerator\ColumnProfile;
use Timbrs\DatabaseDumps\Service\ConfigGenerator\ColumnStatisticsInspector;

class ColumnStatisticsInspectorTest extends TestCase
{
    /** @var DatabaseConnectionInterface&\PHPUnit\Framework\MockObject\MockObject */
    private $connection;

    /** @var ConnectionRegistryInterface&\PHPUnit\Framework\MockObject\MockObject */
    private $registry;

    protected function setUp(): void
    {
        $this->connection = $this->createMock(DatabaseConnectionInterface::class);
        $this->connection->method('getPlatformName')->willReturn('postgresql');
        $this->registry = $this->createMock(ConnectionRegistryInterface::class);
        $this->registry->method('getConnection')->willReturn($this->connection);
        $this->registry->method('getPlatform')->willReturn(new PostgresPlatform());
    }

    /**
     * @param array<int, array<string, mixed>> $columnsMeta
     * @param array<int, array<string, mixed>> $sampleRows
     */
    private function inspectorWith(array $columnsMeta, array $sampleRows): ColumnStatisticsInspector
    {
        $this->connection->method('fetchAllAssociative')->willReturnCallback(
            function ($sql) use ($columnsMeta, $sampleRows) {
                if (strpos($sql, 'information_schema.columns') !== false) {
                    return $columnsMeta;
                }
                return $sampleRows;
            }
        );
        return new ColumnStatisticsInspector($this->registry);
    }

    /**
     * @param array<int, ColumnProfile> $profiles
     */
    private function profileBy(array $profiles, string $column): ?ColumnProfile
    {
        foreach ($profiles as $p) {
            if ($p->getColumn() === $column) {
                return $p;
            }
        }
        return null;
    }

    public function testDetectsCategoricalColumn(): void
    {
        $columnsMeta = [
            ['column_name' => 'id', 'data_type' => 'integer', 'is_nullable' => 'NO'],
            ['column_name' => 'status', 'data_type' => 'varchar', 'is_nullable' => 'NO'],
        ];
        $sampleRows = [];
        $statuses = ['red', 'green', 'yellow'];
        for ($i = 0; $i < 30; $i++) {
            $sampleRows[] = ['id' => $i, 'status' => $statuses[$i % 3]];
        }

        $inspector = $this->inspectorWith($columnsMeta, $sampleRows);
        $profiles = $inspector->profileTable('public', 'clients');

        $status = $this->profileBy($profiles, 'status');
        $this->assertNotNull($status);
        $this->assertTrue($status->isCategorical());
        $this->assertSame(3, $status->getDistinctCount());

        $id = $this->profileBy($profiles, 'id');
        $this->assertNotNull($id);
        // id уникален в каждой строке → не категориальная
        $this->assertFalse($id->isCategorical());
    }

    public function testNullFractionComputed(): void
    {
        $columnsMeta = [
            ['column_name' => 'note', 'data_type' => 'text', 'is_nullable' => 'YES'],
        ];
        $sampleRows = [];
        for ($i = 0; $i < 10; $i++) {
            $sampleRows[] = ['note' => $i < 4 ? null : 'x']; // 4 из 10 null
        }

        $inspector = $this->inspectorWith($columnsMeta, $sampleRows);
        $profiles = $inspector->profileTable('public', 'clients');
        $note = $this->profileBy($profiles, 'note');
        $this->assertNotNull($note);
        $this->assertEqualsWithDelta(0.4, $note->getNullFraction(), 0.001);
        $this->assertTrue($note->isNullable());
    }

    public function testTopValuesOrderedByFrequency(): void
    {
        $columnsMeta = [
            ['column_name' => 'tier', 'data_type' => 'varchar', 'is_nullable' => 'NO'],
        ];
        $sampleRows = [];
        // a x5, b x3, c x1
        foreach (['a', 'a', 'a', 'a', 'a', 'b', 'b', 'b', 'c'] as $v) {
            $sampleRows[] = ['tier' => $v];
        }

        $inspector = $this->inspectorWith($columnsMeta, $sampleRows);
        $profiles = $inspector->profileTable('public', 'clients');
        $tier = $this->profileBy($profiles, 'tier');
        $this->assertNotNull($tier);
        $top = $tier->getTopValues();
        $this->assertSame('a', $top[0]['value']);
        $this->assertSame(5, $top[0]['count']);
        $this->assertSame('b', $top[1]['value']);
    }

    public function testEmptyTableYieldsProfilesWithoutCrash(): void
    {
        $columnsMeta = [
            ['column_name' => 'id', 'data_type' => 'integer', 'is_nullable' => 'NO'],
        ];
        $inspector = $this->inspectorWith($columnsMeta, []);
        $profiles = $inspector->profileTable('public', 'empty_t');
        $this->assertCount(1, $profiles);
        $this->assertSame(0, $profiles[0]->getDistinctCount());
        $this->assertFalse($profiles[0]->isCategorical());
    }

    public function testNoColumnsReturnsEmpty(): void
    {
        $inspector = $this->inspectorWith([], []);
        $this->assertSame([], $inspector->profileTable('public', 'ghost'));
    }

    public function testAllNullColumnIsNotCategorical(): void
    {
        $columnsMeta = [
            ['column_name' => 'note', 'data_type' => 'text', 'is_nullable' => 'YES'],
        ];
        $rows = [];
        for ($i = 0; $i < 10; $i++) {
            $rows[] = ['note' => null];
        }
        $inspector = $this->inspectorWith($columnsMeta, $rows);
        $note = $this->profileBy($inspector->profileTable('public', 't'), 'note');
        $this->assertNotNull($note);
        $this->assertFalse($note->isCategorical());
        $this->assertSame(0, $note->getDistinctCount());
        $this->assertEqualsWithDelta(1.0, $note->getNullFraction(), 0.001);
    }

    public function testSingleValueColumnIsNotCategorical(): void
    {
        $columnsMeta = [
            ['column_name' => 'flag', 'data_type' => 'varchar', 'is_nullable' => 'NO'],
        ];
        $rows = [];
        for ($i = 0; $i < 10; $i++) {
            $rows[] = ['flag' => 'same'];
        }
        $inspector = $this->inspectorWith($columnsMeta, $rows);
        $flag = $this->profileBy($inspector->profileTable('public', 't'), 'flag');
        $this->assertNotNull($flag);
        // distinct == 1 → не категориальная (бесполезно для корзин)
        $this->assertFalse($flag->isCategorical());
        $this->assertSame(1, $flag->getDistinctCount());
    }

    public function testAllUniqueValuesNotCategorical(): void
    {
        $columnsMeta = [
            ['column_name' => 'uid', 'data_type' => 'varchar', 'is_nullable' => 'NO'],
        ];
        $rows = [];
        for ($i = 0; $i < 10; $i++) {
            $rows[] = ['uid' => 'u' . $i];
        }
        $inspector = $this->inspectorWith($columnsMeta, $rows);
        $uid = $this->profileBy($inspector->profileTable('public', 't'), 'uid');
        $this->assertNotNull($uid);
        // distinct == nonNull → уникальный ключ, не категориальная
        $this->assertFalse($uid->isCategorical());
    }

    public function testAtDistinctCapNotCategorical(): void
    {
        // distinct == MAX_CATEGORICAL_DISTINCT → capped → не считаем категориальной
        $columnsMeta = [
            ['column_name' => 'k', 'data_type' => 'varchar', 'is_nullable' => 'NO'],
        ];
        $rows = [];
        $max = ColumnStatisticsInspector::MAX_CATEGORICAL_DISTINCT;
        // Создаём ровно $max различных значений, каждое дважды (distinct < nonNull).
        for ($i = 0; $i < $max; $i++) {
            $rows[] = ['k' => 'v' . $i];
            $rows[] = ['k' => 'v' . $i];
        }
        $inspector = $this->inspectorWith($columnsMeta, $rows);
        $k = $this->profileBy($inspector->profileTable('public', 't'), 'k');
        $this->assertNotNull($k);
        $this->assertSame($max, $k->getDistinctCount());
        $this->assertTrue($k->isDistinctCapped());
        $this->assertFalse($k->isCategorical());
    }

    public function testBooleanValuesProfiledDistinctly(): void
    {
        $columnsMeta = [
            ['column_name' => 'active', 'data_type' => 'boolean', 'is_nullable' => 'NO'],
        ];
        // false НЕ должен схлопываться в пустой ключ и теряться.
        $rows = [
            ['active' => true],
            ['active' => true],
            ['active' => false],
        ];
        $inspector = $this->inspectorWith($columnsMeta, $rows);
        $active = $this->profileBy($inspector->profileTable('public', 't'), 'active');
        $this->assertNotNull($active);
        $this->assertSame(2, $active->getDistinctCount());
        $this->assertTrue($active->isCategorical());
        // false посчитан как nonNull → null_fraction == 0
        $this->assertEqualsWithDelta(0.0, $active->getNullFraction(), 0.001);
        $values = array_map(function ($tv) {
            return $tv['value'];
        }, $active->getTopValues());
        $this->assertContains('0', $values);
        $this->assertContains('1', $values);
    }

    public function testEmptyStringTreatedAsNull(): void
    {
        $columnsMeta = [
            ['column_name' => 'name', 'data_type' => 'varchar', 'is_nullable' => 'YES'],
        ];
        $rows = [
            ['name' => ''],
            ['name' => ''],
            ['name' => 'x'],
            ['name' => 'y'],
        ];
        $inspector = $this->inspectorWith($columnsMeta, $rows);
        $name = $this->profileBy($inspector->profileTable('public', 't'), 'name');
        $this->assertNotNull($name);
        // 2 пустых из 4 → null_fraction 0.5; пустые не попадают в distinct
        $this->assertEqualsWithDelta(0.5, $name->getNullFraction(), 0.001);
        $this->assertSame(2, $name->getDistinctCount());
    }

    public function testCaseInsensitiveKeyNormalization(): void
    {
        // Doctrine/PDO/Laravel могут отдавать ключи в другом регистре, чем имя колонки.
        $columnsMeta = [
            ['column_name' => 'Status', 'data_type' => 'varchar', 'is_nullable' => 'NO'],
        ];
        $rows = [
            ['STATUS' => 'a'],
            ['STATUS' => 'a'],
            ['STATUS' => 'b'],
        ];
        $inspector = $this->inspectorWith($columnsMeta, $rows);
        $profiles = $inspector->profileTable('public', 't');
        $status = $this->profileBy($profiles, 'Status');
        $this->assertNotNull($status);
        // Значения нашлись несмотря на разный регистр ключей строк
        $this->assertSame(2, $status->getDistinctCount());
    }

    public function testOracleColumnMetaPath(): void
    {
        // Oracle: all_tab_columns, nullable 'Y'/'N', ключи в верхнем регистре.
        $connection = $this->createMock(DatabaseConnectionInterface::class);
        $connection->method('getPlatformName')->willReturn('oracle');
        $connection->method('fetchAllAssociative')->willReturnCallback(
            function ($sql) {
                if (strpos($sql, 'all_tab_columns') !== false) {
                    return [
                        ['COLUMN_NAME' => 'status', 'DATA_TYPE' => 'varchar2', 'NULLABLE' => 'Y'],
                        ['COLUMN_NAME' => 'id', 'DATA_TYPE' => 'number', 'NULLABLE' => 'N'],
                    ];
                }
                return [
                    ['STATUS' => 'a', 'ID' => 1],
                    ['STATUS' => 'a', 'ID' => 2],
                    ['STATUS' => 'b', 'ID' => 3],
                ];
            }
        );

        $registry = $this->createMock(ConnectionRegistryInterface::class);
        $registry->method('getConnection')->willReturn($connection);
        $registry->method('getPlatform')->willReturn(new \Timbrs\DatabaseDumps\Platform\OraclePlatform());

        $inspector = new ColumnStatisticsInspector($registry);
        $profiles = $inspector->profileTable('HR', 'CLIENTS');

        $status = $this->profileBy($profiles, 'status');
        $this->assertNotNull($status);
        $this->assertTrue($status->isNullable());
        $this->assertSame('varchar2', $status->getDataType());
        $this->assertSame(2, $status->getDistinctCount());

        $id = $this->profileBy($profiles, 'id');
        $this->assertNotNull($id);
        $this->assertFalse($id->isNullable());
    }

    public function testNullFractionZeroOnEmptySample(): void
    {
        $columnsMeta = [
            ['column_name' => 'c', 'data_type' => 'int', 'is_nullable' => 'YES'],
        ];
        $inspector = $this->inspectorWith($columnsMeta, []);
        $c = $this->profileBy($inspector->profileTable('public', 't'), 'c');
        $this->assertNotNull($c);
        // Пустая выборка: деления на ноль нет, null_fraction == 0.0
        $this->assertEqualsWithDelta(0.0, $c->getNullFraction(), 0.001);
    }
}
