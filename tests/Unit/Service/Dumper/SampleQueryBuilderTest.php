<?php

namespace Timbrs\DatabaseDumps\Tests\Unit\Service\Dumper;

use PHPUnit\Framework\TestCase;
use Timbrs\DatabaseDumps\Config\TableConfig;
use Timbrs\DatabaseDumps\Contract\ConnectionRegistryInterface;
use Timbrs\DatabaseDumps\Contract\DatabaseConnectionInterface;
use Timbrs\DatabaseDumps\Platform\PostgresPlatform;
use Timbrs\DatabaseDumps\Service\ConfigGenerator\PrimaryKeyInspector;
use Timbrs\DatabaseDumps\Service\Dumper\SampleQueryBuilder;
use Timbrs\DatabaseDumps\Service\Dumper\SelectedPkRegistry;

class SampleQueryBuilderTest extends TestCase
{
    /** @var DatabaseConnectionInterface&\PHPUnit\Framework\MockObject\MockObject */
    private $connection;

    /** @var ConnectionRegistryInterface&\PHPUnit\Framework\MockObject\MockObject */
    private $registry;

    /** @var PrimaryKeyInspector&\PHPUnit\Framework\MockObject\MockObject */
    private $pkInspector;

    /** @var SelectedPkRegistry */
    private $selectedPk;

    /** @var array<int, string> Захваченные фазы-1 SQL */
    private $capturedSql = [];

    protected function setUp(): void
    {
        $this->connection = $this->createMock(DatabaseConnectionInterface::class);
        $this->registry = $this->createMock(ConnectionRegistryInterface::class);
        $this->registry->method('getConnection')->willReturn($this->connection);
        $this->registry->method('getPlatform')->willReturn(new PostgresPlatform());
        $this->pkInspector = $this->createMock(PrimaryKeyInspector::class);
        $this->selectedPk = new SelectedPkRegistry();

        // quote() — заворачиваем в одинарные кавычки (детерминированно для assert).
        $this->connection->method('quote')->willReturnCallback(function ($v) {
            return "'" . $v . "'";
        });
    }

    private function builder(): SampleQueryBuilder
    {
        return new SampleQueryBuilder($this->registry, $this->pkInspector, $this->selectedPk);
    }

    public function testPhase1PerCriterionAndDedupAndPhase2(): void
    {
        $this->pkInspector->method('getPrimaryKeyColumns')->willReturn(['id']);

        $this->connection->method('fetchFirstColumn')->willReturnCallback(function ($sql) {
            $this->capturedSql[] = $sql;
            if (strpos($sql, "status = 'red'") !== false) {
                return [1, 2, 3];
            }
            if (strpos($sql, 'created_at') !== false) {
                return [3, 4, 5]; // 3 — дубль, должен схлопнуться
            }
            return [];
        });

        $sample = [
            TableConfig::SAMPLE_KEY_CRITERIA => [
                ['name' => 'red', 'where' => "status = 'red'", 'limit' => 10],
                ['name' => 'newish', 'where' => 'created_at > 100', 'limit' => 50],
            ],
        ];
        $config = new TableConfig('public', 'clients', null, null, null, null, null, null, $sample);

        $phase2 = $this->builder()->build($config);

        // Фаза 1: SELECT pk ... WHERE (crit) LIMIT n
        $this->assertStringContainsString('SELECT "id" FROM "public"."clients" WHERE (status = \'red\') LIMIT 10', $this->capturedSql[0]);
        $this->assertStringContainsString('WHERE (created_at > 100) LIMIT 50', $this->capturedSql[1]);

        // Фаза 2: дедуп id 1..5 в IN
        $this->assertStringContainsString('SELECT * FROM "public"."clients" WHERE "id" IN (', $phase2);
        $this->assertStringContainsString("IN ('1', '2', '3', '4', '5')", $phase2);

        // Реестр выбранных PK заполнен (для cascade-консистентности)
        $this->assertSame([1, 2, 3, 4, 5], $this->selectedPk->getColumnValues('public', 'clients', 'id'));
    }

    public function testBaseWhereCombinedWithCriterion(): void
    {
        $this->pkInspector->method('getPrimaryKeyColumns')->willReturn(['id']);
        $this->connection->method('fetchFirstColumn')->willReturnCallback(function ($sql) {
            $this->capturedSql[] = $sql;
            return [7];
        });

        $sample = [
            TableConfig::SAMPLE_KEY_CRITERIA => [
                ['name' => 'red', 'where' => "status = 'red'", 'limit' => 10],
            ],
        ];
        $config = new TableConfig('public', 'clients', null, 'is_active = true', null, null, null, null, $sample);

        $this->builder()->build($config);

        $this->assertStringContainsString('WHERE (is_active = true) AND (status = \'red\') LIMIT 10', $this->capturedSql[0]);
    }

    public function testOrderByPropagatedToPhase1AndPhase2(): void
    {
        $this->pkInspector->method('getPrimaryKeyColumns')->willReturn(['id']);
        $this->connection->method('fetchFirstColumn')->willReturnCallback(function ($sql) {
            $this->capturedSql[] = $sql;
            return [1];
        });

        $sample = [
            TableConfig::SAMPLE_KEY_CRITERIA => [
                ['name' => 'red', 'where' => "status = 'red'", 'limit' => 10],
            ],
        ];
        $config = new TableConfig('public', 'clients', null, null, 'id DESC', null, null, null, $sample);

        $phase2 = $this->builder()->build($config);

        $this->assertStringContainsString('ORDER BY id DESC LIMIT 10', $this->capturedSql[0]);
        $this->assertStringContainsString('ORDER BY id DESC', $phase2);
    }

    public function testStratifyByExpandsToBuckets(): void
    {
        $this->pkInspector->method('getPrimaryKeyColumns')->willReturn(['id']);

        $this->connection->method('fetchFirstColumn')->willReturnCallback(function ($sql) {
            $this->capturedSql[] = $sql;
            if (strpos($sql, 'DISTINCT') !== false) {
                return ['active', 'closed'];
            }
            if (strpos($sql, "'active'") !== false) {
                return [10, 11];
            }
            if (strpos($sql, "'closed'") !== false) {
                return [20];
            }
            return [];
        });

        $sample = [
            TableConfig::SAMPLE_KEY_STRATIFY_BY => 'status',
            TableConfig::SAMPLE_KEY_PER_VALUE => 10,
        ];
        $config = new TableConfig('public', 'clients', null, null, null, null, null, null, $sample);

        $phase2 = $this->builder()->build($config);

        // DISTINCT-запрос с потолком корзин
        $this->assertStringContainsString('SELECT DISTINCT "status" FROM "public"."clients" LIMIT ' . SampleQueryBuilder::MAX_STRATIFY_BUCKETS, $this->capturedSql[0]);
        // По корзине на значение
        $this->assertStringContainsString('WHERE ("status" = \'active\') LIMIT 10', $this->capturedSql[1]);
        $this->assertStringContainsString('WHERE ("status" = \'closed\') LIMIT 10', $this->capturedSql[2]);
        $this->assertStringContainsString("IN ('10', '11', '20')", $phase2);
    }

    public function testOverallLimitCapTruncatesUnion(): void
    {
        $this->pkInspector->method('getPrimaryKeyColumns')->willReturn(['id']);
        $this->connection->method('fetchFirstColumn')->willReturn([1, 2, 3, 4, 5]);

        $sample = [
            TableConfig::SAMPLE_KEY_CRITERIA => [
                ['name' => 'red', 'where' => "status = 'red'", 'limit' => 50],
            ],
        ];
        // Общий потолок = 3
        $config = new TableConfig('public', 'clients', 3, null, null, null, null, null, $sample);

        $phase2 = $this->builder()->build($config);

        $this->assertStringContainsString("IN ('1', '2', '3')", $phase2);
        $this->assertStringNotContainsString("'4'", $phase2);
    }

    public function testEmptySelectionProducesNoRowsQuery(): void
    {
        $this->pkInspector->method('getPrimaryKeyColumns')->willReturn(['id']);
        $this->connection->method('fetchFirstColumn')->willReturn([]);

        $sample = [
            TableConfig::SAMPLE_KEY_CRITERIA => [
                ['name' => 'red', 'where' => "status = 'red'", 'limit' => 10],
            ],
        ];
        $config = new TableConfig('public', 'clients', null, null, null, null, null, null, $sample);

        $phase2 = $this->builder()->build($config);
        $this->assertStringContainsString('WHERE 1 = 0', $phase2);
        $this->assertSame([], $this->selectedPk->getColumnValues('public', 'clients', 'id'));
    }

    public function testCompositePkUsesDisjunctionOfEqualities(): void
    {
        $this->pkInspector->method('getPrimaryKeyColumns')->willReturn(['order_id', 'product_id']);

        $this->connection->method('fetchAllAssociative')->willReturnCallback(function ($sql) {
            $this->capturedSql[] = $sql;
            return [
                ['order_id' => 1, 'product_id' => 9],
                ['order_id' => 2, 'product_id' => 8],
            ];
        });

        $sample = [
            TableConfig::SAMPLE_KEY_CRITERIA => [
                ['name' => 'recent', 'where' => 'created_at > 100', 'limit' => 10],
            ],
        ];
        $config = new TableConfig('shop', 'order_items', null, null, null, null, null, null, $sample);

        $phase2 = $this->builder()->build($config);

        // Фаза 1 выбирает обе колонки PK
        $this->assertStringContainsString('SELECT "order_id", "product_id" FROM "shop"."order_items"', $this->capturedSql[0]);
        // Фаза 2 — дизъюнкция равенств
        $this->assertStringContainsString('("order_id" = \'1\' AND "product_id" = \'9\')', $phase2);
        $this->assertStringContainsString('("order_id" = \'2\' AND "product_id" = \'8\')', $phase2);
        $this->assertStringContainsString(' OR ', $phase2);
    }

    public function testZeroLimitCapProducesEmptySelection(): void
    {
        $this->pkInspector->method('getPrimaryKeyColumns')->willReturn(['id']);
        $this->connection->method('fetchFirstColumn')->willReturn([1, 2, 3]);

        $sample = [
            TableConfig::SAMPLE_KEY_CRITERIA => [
                ['name' => 'red', 'where' => "status = 'red'", 'limit' => 10],
            ],
        ];
        // Общий потолок 0 — выборка усекается в ноль.
        $config = new TableConfig('public', 'clients', 0, null, null, null, null, null, $sample);

        $phase2 = $this->builder()->build($config);
        $this->assertStringContainsString('WHERE 1 = 0', $phase2);
        $this->assertSame([], $this->selectedPk->getColumnValues('public', 'clients', 'id'));
    }

    public function testStratifySkipsNullBucketValue(): void
    {
        $this->pkInspector->method('getPrimaryKeyColumns')->willReturn(['id']);
        $this->connection->method('fetchFirstColumn')->willReturnCallback(function ($sql) {
            $this->capturedSql[] = $sql;
            if (strpos($sql, 'DISTINCT') !== false) {
                return ['active', null, 'closed']; // NULL-корзина должна быть пропущена
            }
            if (strpos($sql, "'active'") !== false) {
                return [1];
            }
            if (strpos($sql, "'closed'") !== false) {
                return [2];
            }
            return [];
        });

        $sample = [TableConfig::SAMPLE_KEY_STRATIFY_BY => 'status'];
        $config = new TableConfig('public', 'clients', null, null, null, null, null, null, $sample);

        $phase2 = $this->builder()->build($config);

        // Только 2 фазы-1 запроса по значениям (active/closed), без " = ''" для NULL.
        $bucketQueries = array_filter($this->capturedSql, function ($s) {
            return strpos($s, 'DISTINCT') === false;
        });
        $this->assertCount(2, $bucketQueries);
        $this->assertStringContainsString("IN ('1', '2')", $phase2);
    }

    public function testStratifyDefaultPerValueWhenNotConfigured(): void
    {
        $this->pkInspector->method('getPrimaryKeyColumns')->willReturn(['id']);
        $this->connection->method('fetchFirstColumn')->willReturnCallback(function ($sql) {
            $this->capturedSql[] = $sql;
            if (strpos($sql, 'DISTINCT') !== false) {
                return ['a'];
            }
            return [1];
        });

        $sample = [TableConfig::SAMPLE_KEY_STRATIFY_BY => 'status'];
        $config = new TableConfig('public', 'clients', null, null, null, null, null, null, $sample);

        $this->builder()->build($config);

        // per_value не задан — используется DEFAULT_PER_VALUE.
        $this->assertStringContainsString('LIMIT ' . TableConfig::DEFAULT_PER_VALUE, $this->capturedSql[1]);
    }

    public function testCompositePkDedupDistinguishesByTuple(): void
    {
        $this->pkInspector->method('getPrimaryKeyColumns')->willReturn(['a', 'b']);

        $this->connection->method('fetchAllAssociative')->willReturnCallback(function ($sql) {
            if (strpos($sql, 'first') !== false) {
                return [
                    ['a' => 1, 'b' => 2],
                    ['a' => 1, 'b' => 3],
                ];
            }
            // Второй критерий повторяет (1,2) — должен схлопнуться, но (1,4) новый.
            return [
                ['a' => 1, 'b' => 2],
                ['a' => 1, 'b' => 4],
            ];
        });

        $sample = [
            TableConfig::SAMPLE_KEY_CRITERIA => [
                ['name' => 'first', 'where' => "tag = 'first'", 'limit' => 10],
                ['name' => 'second', 'where' => "tag = 'second'", 'limit' => 10],
            ],
        ];
        $config = new TableConfig('shop', 'pivot', null, null, null, null, null, null, $sample);

        $phase2 = $this->builder()->build($config);

        // Три уникальных кортежа: (1,2), (1,3), (1,4).
        $this->assertSame(2, substr_count($phase2, ' OR '));
        $this->assertStringContainsString('("a" = \'1\' AND "b" = \'2\')', $phase2);
        $this->assertStringContainsString('("a" = \'1\' AND "b" = \'3\')', $phase2);
        $this->assertStringContainsString('("a" = \'1\' AND "b" = \'4\')', $phase2);
    }

    public function testCompositePkCapTruncatesAfterDedup(): void
    {
        $this->pkInspector->method('getPrimaryKeyColumns')->willReturn(['a', 'b']);
        $this->connection->method('fetchAllAssociative')->willReturn([
            ['a' => 1, 'b' => 1],
            ['a' => 2, 'b' => 2],
            ['a' => 3, 'b' => 3],
        ]);

        $sample = [
            TableConfig::SAMPLE_KEY_CRITERIA => [
                ['name' => 'x', 'where' => 'id > 0', 'limit' => 10],
            ],
        ];
        $config = new TableConfig('shop', 'pivot', 2, null, null, null, null, null, $sample);

        $phase2 = $this->builder()->build($config);
        // Только 2 кортежа после усечения.
        $this->assertSame(1, substr_count($phase2, ' OR '));
        $this->assertStringNotContainsString("'3'", $phase2);
        $this->assertSame([1, 2], $this->selectedPk->getColumnValues('shop', 'pivot', 'a'));
    }

    public function testThrowsWhenNoPrimaryKey(): void
    {
        $this->pkInspector->method('getPrimaryKeyColumns')->willReturn([]);

        $sample = [
            TableConfig::SAMPLE_KEY_CRITERIA => [
                ['name' => 'red', 'where' => "status = 'red'", 'limit' => 10],
            ],
        ];
        $config = new TableConfig('public', 'logs', null, null, null, null, null, null, $sample);

        $this->expectException(\InvalidArgumentException::class);
        $this->builder()->build($config);
    }

    public function testPreFiltersAliasCriterionButKeepsValid(): void
    {
        // Битый criterion с алиасом t1. отсеивается ДО запроса; валидный отрабатывает.
        $this->pkInspector->method('getPrimaryKeyColumns')->willReturn(['id']);
        $this->connection->method('fetchFirstColumn')->willReturnCallback(function ($sql) {
            $this->capturedSql[] = $sql;
            return [1, 2];
        });

        $sample = [
            TableConfig::SAMPLE_KEY_CRITERIA => [
                ['name' => 'bad', 'where' => 't1.active_flg = 1', 'limit' => 10],
                ['name' => 'ok', 'where' => "status = 'red'", 'limit' => 10],
            ],
        ];
        $config = new TableConfig('public', 'clients', null, null, null, null, null, null, $sample);
        $phase2 = $this->builder()->build($config);

        // Ровно один запрос — по валидному criterion; алиасный не исполнялся.
        $this->assertCount(1, $this->capturedSql);
        $this->assertStringContainsString("status = 'red'", $this->capturedSql[0]);
        $this->assertStringNotContainsString('t1.', $this->capturedSql[0]);
        $this->assertStringContainsString("IN ('1', '2')", $phase2);
    }

    public function testFailingCriterionSkippedAtRuntime(): void
    {
        // Criterion проходит пре-фильтр (нет алиаса/параметра), но падает в БД (несуществующая
        // колонка) — ловим, пропускаем, продолжаем валидным. Экспорт не падает.
        $this->pkInspector->method('getPrimaryKeyColumns')->willReturn(['id']);
        $this->connection->method('fetchFirstColumn')->willReturnCallback(function ($sql) {
            $this->capturedSql[] = $sql;
            if (strpos($sql, 'activeflg') !== false) {
                throw new \RuntimeException('SQLSTATE[42703]: column "activeflg" does not exist');
            }
            return [9];
        });

        $sample = [
            TableConfig::SAMPLE_KEY_CRITERIA => [
                ['name' => 'bad', 'where' => 'activeflg = 1', 'limit' => 10],
                ['name' => 'ok', 'where' => "status = 'red'", 'limit' => 10],
            ],
        ];
        $config = new TableConfig('public', 'clients', null, null, null, null, null, null, $sample);
        $phase2 = $this->builder()->build($config);

        $this->assertStringContainsString("IN ('9')", $phase2);
    }

    public function testAllCriteriaInvalidFallsBackToLimit(): void
    {
        // Все criteria непригодны (алиас) → плоский срез по limit, а не пустая таблица.
        $this->pkInspector->method('getPrimaryKeyColumns')->willReturn(['id']);
        $this->connection->method('fetchFirstColumn')->willReturnCallback(function ($sql) {
            $this->capturedSql[] = $sql;
            return [11, 12];
        });

        $sample = [
            TableConfig::SAMPLE_KEY_CRITERIA => [
                ['name' => 'bad', 'where' => 't1.active_flg = 1', 'limit' => 10],
            ],
        ];
        $config = new TableConfig('public', 'clients', 100, null, null, null, null, null, $sample);
        $phase2 = $this->builder()->build($config);

        // Фолбэк-запрос: плоский срез WHERE (1 = 1) LIMIT 100.
        $this->assertCount(1, $this->capturedSql);
        $this->assertStringContainsString('WHERE (1 = 1) LIMIT 100', $this->capturedSql[0]);
        $this->assertStringContainsString("IN ('11', '12')", $phase2);
    }
}
