<?php

namespace Timbrs\DatabaseDumps\Tests\Unit\Service\ConfigGenerator;

use PHPUnit\Framework\TestCase;
use Timbrs\DatabaseDumps\Config\TableConfig;
use Timbrs\DatabaseDumps\Contract\ConnectionRegistryInterface;
use Timbrs\DatabaseDumps\Contract\DatabaseConnectionInterface;
use Timbrs\DatabaseDumps\Platform\PostgresPlatform;
use Timbrs\DatabaseDumps\Service\ConfigGenerator\ColumnProfile;
use Timbrs\DatabaseDumps\Service\ConfigGenerator\CriteriaSuggester;

class CriteriaSuggesterTest extends TestCase
{
    /** @var CriteriaSuggester */
    private $suggester;

    protected function setUp(): void
    {
        $connection = $this->createMock(DatabaseConnectionInterface::class);
        $connection->method('quote')->willReturnCallback(function ($v) {
            return "'" . str_replace("'", "''", (string) $v) . "'";
        });

        $registry = $this->createMock(ConnectionRegistryInterface::class);
        $registry->method('getConnection')->willReturn($connection);
        $registry->method('getPlatform')->willReturn(new PostgresPlatform());

        $this->suggester = new CriteriaSuggester($registry);
    }

    /**
     * @param array<int, array{value: string, count: int}> $topValues
     */
    private function categorical(string $column, array $topValues, int $distinct): ColumnProfile
    {
        return new ColumnProfile($column, 'varchar', false, 0.0, $distinct, false, $topValues, true);
    }

    public function testBucketPerTopValue(): void
    {
        $profiles = [
            $this->categorical('status', [
                ['value' => 'red', 'count' => 10],
                ['value' => 'green', 'count' => 8],
                ['value' => 'yellow', 'count' => 5],
            ], 3),
        ];

        $criteria = $this->suggester->suggest($profiles);
        $this->assertCount(3, $criteria);
        $this->assertSame('status_red', $criteria[0]['name']);
        $this->assertSame('"status" = \'red\'', $criteria[0]['where']);
        $this->assertSame(CriteriaSuggester::DEFAULT_QUOTA, $criteria[0]['limit']);
        $this->assertSame('data', $criteria[0]['source']);
    }

    public function testSkipsNonCategoricalColumns(): void
    {
        $idLike = new ColumnProfile('id', 'integer', false, 0.0, 200, true, [['value' => '1', 'count' => 1]], false);
        $this->assertSame([], $this->suggester->suggest([$idLike]));
    }

    public function testQuotaClamped(): void
    {
        $profiles = [$this->categorical('s', [['value' => 'a', 'count' => 5]], 2)];

        $low = $this->suggester->suggest($profiles, null, 1);
        $this->assertSame(CriteriaSuggester::MIN_QUOTA, $low[0]['limit']);

        $high = $this->suggester->suggest($profiles, null, 9999);
        $this->assertSame(CriteriaSuggester::MAX_QUOTA, $high[0]['limit']);
    }

    public function testNonAsciiValueProducesValidUniqueIdentifier(): void
    {
        $profiles = [
            $this->categorical('status', [
                ['value' => 'в работе', 'count' => 10],
                ['value' => 'закрыт', 'count' => 5],
            ], 2),
        ];

        $criteria = $this->suggester->suggest($profiles);
        $this->assertCount(2, $criteria);
        foreach ($criteria as $c) {
            // имя — валидный идентификатор (проходит TableConfig)
            $this->assertMatchesRegularExpression('/^[A-Za-z_][A-Za-z0-9_$]*$/', $c['name']);
        }
        // имена уникальны
        $this->assertNotSame($criteria[0]['name'], $criteria[1]['name']);
    }

    public function testGeneratedSampleConfigPassesTableConfigValidation(): void
    {
        $profiles = [
            $this->categorical('status', [
                ['value' => 'red', 'count' => 10],
                ['value' => "O'Brien", 'count' => 3],
            ], 2),
        ];
        $criteria = $this->suggester->suggest($profiles);
        $sample = $this->suggester->toSampleConfig($criteria);

        // Не должно бросить исключение — where сбалансирован, name валиден.
        $config = TableConfig::fromArray('public', 'clients', [
            TableConfig::KEY_LIMIT => 100,
            TableConfig::KEY_SAMPLE => $sample,
        ]);
        $this->assertTrue($config->hasSample());
    }

    public function testPrioritisesLowerCardinalityColumns(): void
    {
        // 6 категориальных колонок — берётся максимум MAX_COLUMNS, приоритет меньшей кардинальности.
        $profiles = [];
        for ($i = 1; $i <= 6; $i++) {
            $profiles[] = $this->categorical('col' . $i, [['value' => 'v', 'count' => 5]], $i + 1);
        }
        $criteria = $this->suggester->suggest($profiles);
        $columns = array_values(array_unique(array_map(function ($c) {
            return $c['column'];
        }, $criteria)));
        $this->assertNotContains('col6', $columns); // самая высокая кардинальность отброшена
    }

    /**
     * Значения, ломающие TableConfig::validateClause, должны пропускаться,
     * а не попадать в sample (иначе fromArray() бросит исключение).
     */
    public function testSkipsValuesThatBreakClauseValidation(): void
    {
        $profiles = [
            $this->categorical('status', [
                ['value' => 'ok', 'count' => 10],          // валиден
                ['value' => 'a;drop', 'count' => 8],         // ';' — отбрасывается
                ['value' => 'see -- here', 'count' => 7],    // комментарий — отбрасывается
                ['value' => 'c /* x', 'count' => 6],         // комментарий — отбрасывается
                ['value' => 'unbalanced(', 'count' => 5],    // дисбаланс скобок — отбрасывается
            ], 5),
        ];

        $criteria = $this->suggester->suggest($profiles);
        $this->assertCount(1, $criteria);
        $this->assertSame('ok', $criteria[0]['value']);

        // Итоговый sample проходит валидацию TableConfig.
        $sample = $this->suggester->toSampleConfig($criteria);
        $config = TableConfig::fromArray('public', 'clients', [
            TableConfig::KEY_LIMIT => 100,
            TableConfig::KEY_SAMPLE => $sample,
        ]);
        $this->assertTrue($config->hasSample());
    }

    /**
     * Колонка и значение целиком из кириллицы дают валидный идентификатор имени
     * (фолбэк с префиксом 'c'), без падения и без коллизий.
     */
    public function testFullyNonAsciiColumnAndValueYieldValidNames(): void
    {
        $profiles = [
            $this->categorical('статус', [
                ['value' => 'закрыт', 'count' => 10],
                ['value' => 'открыт', 'count' => 5],
            ], 2),
        ];

        $criteria = $this->suggester->suggest($profiles);
        $this->assertCount(2, $criteria);
        foreach ($criteria as $c) {
            $this->assertMatchesRegularExpression('/^[A-Za-z_][A-Za-z0-9_$]*$/', $c['name']);
        }
        $this->assertNotSame($criteria[0]['name'], $criteria[1]['name']);
    }

    /**
     * Идентичные после слагификации значения получают уникальные суффиксы.
     */
    public function testCollidingSlugsGetUniqueSuffixes(): void
    {
        $profiles = [
            $this->categorical('status', [
                ['value' => 'a b', 'count' => 10],   // -> status_a_b
                ['value' => 'a-b', 'count' => 8],    // -> status_a_b (коллизия)
                ['value' => 'a/b', 'count' => 6],    // -> status_a_b (коллизия)
            ], 3),
        ];

        $criteria = $this->suggester->suggest($profiles);
        $names = array_map(function ($c) {
            return $c['name'];
        }, $criteria);
        $this->assertSame($names, array_values(array_unique($names)));
        $this->assertCount(3, $names);
    }

    /**
     * Потолок корзин на колонку (MAX_BUCKETS_PER_COLUMN = 10).
     */
    public function testCapsBucketsPerColumn(): void
    {
        $topValues = [];
        for ($i = 0; $i < 25; $i++) {
            $topValues[] = ['value' => 'v' . $i, 'count' => 25 - $i];
        }
        // distinct < MAX_CATEGORICAL_DISTINCT, чтобы колонка считалась категориальной на входе.
        $profiles = [$this->categorical('status', $topValues, 25)];

        $criteria = $this->suggester->suggest($profiles);
        $this->assertCount(10, $criteria);
    }

    /**
     * Потолок суммарных критериев на таблицу (MAX_TOTAL_CRITERIA = 30).
     */
    public function testCapsTotalCriteria(): void
    {
        // 5 колонок (= MAX_COLUMNS) по 10 корзин = 50 потенциальных, обрезается до 30.
        $profiles = [];
        for ($c = 0; $c < 5; $c++) {
            $tv = [];
            for ($i = 0; $i < 10; $i++) {
                $tv[] = ['value' => 'c' . $c . '_v' . $i, 'count' => 10 - $i];
            }
            $profiles[] = $this->categorical('col' . $c, $tv, 12 + $c);
        }

        $criteria = $this->suggester->suggest($profiles);
        $this->assertCount(30, $criteria);
    }

    /**
     * Имя корзины не превышает 60 символов даже при длинных значениях.
     */
    public function testNameLengthIsCapped(): void
    {
        $longValue = str_repeat('x', 200);
        $profiles = [$this->categorical('status', [['value' => $longValue, 'count' => 5]], 2)];

        $criteria = $this->suggester->suggest($profiles);
        $this->assertCount(1, $criteria);
        $this->assertLessThanOrEqual(60, strlen($criteria[0]['name']));
        $this->assertMatchesRegularExpression('/^[A-Za-z_][A-Za-z0-9_$]*$/', $criteria[0]['name']);
    }

    /**
     * toSampleConfig оставляет только name/where/limit (без служебных полей).
     */
    public function testToSampleConfigStripsMetaFields(): void
    {
        $profiles = [$this->categorical('status', [['value' => 'red', 'count' => 5]], 2)];
        $sample = $this->suggester->toSampleConfig($this->suggester->suggest($profiles));

        $this->assertArrayHasKey('criteria', $sample);
        $entry = $sample['criteria'][0];
        $this->assertSame(['name', 'where', 'limit'], array_keys($entry));
    }
}
