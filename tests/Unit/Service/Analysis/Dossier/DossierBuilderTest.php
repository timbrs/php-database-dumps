<?php

namespace Timbrs\DatabaseDumps\Tests\Unit\Service\Analysis\Dossier;

use PHPUnit\Framework\TestCase;
use Timbrs\DatabaseDumps\Config\DumpConfig;
use Timbrs\DatabaseDumps\Config\FakerConfig;
use Timbrs\DatabaseDumps\Service\Analysis\Dossier\DossierBuilder;

class DossierBuilderTest extends TestCase
{
    public function testColumnCarriesProfileEnumCoverageAndReasons(): void
    {
        $dossier = (new DossierBuilder())->build('tasks', $this->inventory(), $this->config());
        $table = $dossier['tables']['jobs'];

        self::assertSame(1200, $table['row_count']['value']);
        self::assertTrue($table['row_count']['estimated']);
        self::assertSame('partial_export', $table['config']['mode']);

        $status = $table['columns']['status_id'];
        self::assertSame('App\\Enum\\Tasks\\StatusEnum', $status['enum']['class']);
        self::assertSame(['1', '2'], $status['profile']['codes']);
        self::assertSame('criteria', $status['coverage']['covered_by']);
        // Enum знает про 3, в БД по полной статистике только 2 — это вопрос к агенту.
        self::assertContains(DossierBuilder::WHY_ENUM_DB_MISMATCH, $status['ambiguous']);

        $result = $table['columns']['result_id'];
        self::assertNull($result['coverage']['covered_by']);
        self::assertContains(DossierBuilder::WHY_COVERAGE_GAP, $result['ambiguous']);
        self::assertContains(DossierBuilder::WHY_NO_ENUM_FOR_CODES, $result['ambiguous']);
    }

    public function testTraitsDetectScd2DictionaryAndEav(): void
    {
        $dossier = (new DossierBuilder())->build('clients', $this->inventory(), $this->config());

        self::assertSame([], $dossier['tables'], 'схема выбирается по имени: в clients таблиц нет');

        $attrs = (new DossierBuilder())->build('tasks', $this->inventory(), $this->config())['tables'];
        self::assertArrayHasKey('clients_attrs', $attrs);
        self::assertSame(['role' => 'values', 'pair' => 'clients_attrs_dict'], $attrs['clients_attrs']['traits']['eav']);
        self::assertSame(['role' => 'dictionary', 'pair' => 'clients_attrs'], $attrs['clients_attrs_dict']['traits']['eav']);
        self::assertTrue($attrs['jobs']['traits']['scd2']);
        self::assertTrue($attrs['jobs']['traits']['active_flag']);
    }

    public function testFakerIsReadFromTheSchemaSectionNotFromTheTableConfig(): void
    {
        $table = (new DossierBuilder())->build('tasks', $this->inventory(), $this->config())['tables']['jobs'];

        // Замены ПД живут в отдельной секции `faker:` схемы, а не внутри конфига таблицы.
        // Пока досье искало их не там, оно на каждую колонку отвечало «faker'а нет» — и правила,
        // которые смотрят на текущий паттерн (R4 «нет замены», R11 «замена не по адресу»),
        // работали по пустоте.
        self::assertSame('fio', $table['columns']['owner_name']['pii']['faker']);
        self::assertNull($table['columns']['status_id']['pii']['faker']);
    }

    public function testEavPairIsTakenFromTheSchemaNotFromTheName(): void
    {
        $attrs = (new DossierBuilder())->build('tasks', $this->inventory(), $this->config())['tables'];

        // Конвенция промахнулась (`party_attrs_dict` не существует) — словарь нашёлся по связи,
        // и в досье попало имя из базы, без префикса своей же схемы.
        self::assertSame(['role' => 'values', 'pair' => 'attr_dict'], $attrs['party_attrs']['traits']['eav']);

        // Словаря нет ни по имени, ни по связи: роль остаётся, имени нет — выдумывать нечего.
        self::assertSame(['role' => 'values', 'pair' => null], $attrs['orphan_attrs']['traits']['eav']);
    }

    public function testEdgesMergeForeignKeysConfigAndIncomingLinks(): void
    {
        $dossier = (new DossierBuilder())->build('tasks', $this->inventory(), $this->config());

        $edges = $dossier['tables']['clients_attrs']['edges'];
        $sources = [];
        foreach ($edges as $edge) {
            $sources[$edge['dir'] . ':' . $edge['table']] = $edge['source'];
        }
        self::assertSame('db_fk', $sources['out:tasks.jobs']);

        $incoming = $dossier['tables']['jobs']['edges'];
        self::assertSame('in', $incoming[0]['dir']);
        self::assertSame('tasks.clients_attrs', $incoming[0]['table']);
        self::assertSame(1, $dossier['tables']['jobs']['traits']['in_degree']);
    }

    /**
     * Досье уходит агенту: значений данных в нём быть не должно — только коды после шлюза.
     */
    public function testDossierCarriesNoDataValuesBeyondGatedCodes(): void
    {
        $dossier = (new DossierBuilder())->build('tasks', $this->inventory(), $this->config());
        $encoded = (string) json_encode($dossier, JSON_UNESCAPED_UNICODE);

        self::assertStringNotContainsString('Иванов', $encoded);
        self::assertStringNotContainsString('+7900', $encoded);
    }

    /**
     * @return array<string, mixed>
     */
    private function inventory(): array
    {
        return [
            'generated_at' => '2026-01-15T00:00:00Z',
            'schemas' => [
                'tasks' => [
                    'tables' => [
                        'jobs' => [
                            'row_count' => 1200,
                            'row_count_estimated' => true,
                            'row_count_source' => 'pg_class.reltuples',
                            'columns' => [
                                ['name' => 'id', 'type' => 'bigint', 'nullable' => false],
                                ['name' => 'status_id', 'type' => 'integer', 'nullable' => true],
                                ['name' => 'result_id', 'type' => 'integer', 'nullable' => true],
                                ['name' => 'owner_name', 'type' => 'character varying', 'nullable' => true],
                                ['name' => 'date_from', 'type' => 'timestamp', 'nullable' => true],
                                ['name' => 'date_to', 'type' => 'timestamp', 'nullable' => true],
                                ['name' => 'active_flg', 'type' => 'boolean', 'nullable' => true],
                            ],
                            'foreign_keys' => [],
                            'profiles' => [
                                ['column' => 'status_id', 'categorical' => true, 'distinct_count' => 2, 'codes' => ['1', '2'], 'codes_complete' => true, 'n_distinct_source' => 'pg_stats'],
                                ['column' => 'result_id', 'categorical' => true, 'distinct_count' => 3, 'codes' => ['1', '2', '-4'], 'codes_complete' => true],
                                ['column' => 'owner_name', 'categorical' => false, 'distinct_count' => 900],
                            ],
                            'code_hints' => [
                                'summary' => 'entity 1, repository 2',
                                'counts' => ['entity' => 1, 'repository' => 2],
                                'columns' => [
                                    'status_id' => [
                                        'count' => 7,
                                        'usages' => ['filter'],
                                        'enum' => [
                                            'class' => 'App\\Enum\\Tasks\\StatusEnum',
                                            'values' => ['1', '2', '3'],
                                            'cases' => ['NEW' => '1', 'DONE' => '2', 'LOST' => '3'],
                                            'confidence' => 'high',
                                            'bridge' => 'dql',
                                        ],
                                    ],
                                    'owner_name' => ['count' => 2, 'usages' => ['read']],
                                ],
                                'relationships' => [],
                            ],
                        ],
                        'clients_attrs' => [
                            'row_count' => 50,
                            'columns' => [
                                ['name' => 'id', 'type' => 'bigint', 'nullable' => false],
                                ['name' => 'job_id', 'type' => 'bigint', 'nullable' => true],
                                ['name' => 'attr_id', 'type' => 'integer', 'nullable' => true],
                                ['name' => 'value_string', 'type' => 'character varying', 'nullable' => true],
                            ],
                            'foreign_keys' => [
                                ['column' => 'job_id', 'references_table' => 'tasks.jobs', 'references_column' => 'id'],
                            ],
                            'profiles' => [],
                        ],
                        // Пара по конвенции: `<x>_attrs` + `<x>_attrs_dict`.
                        'clients_attrs_dict' => [
                            'row_count' => 12,
                            'columns' => [
                                ['name' => 'id', 'type' => 'integer', 'nullable' => false],
                                ['name' => 'name', 'type' => 'character varying', 'nullable' => true],
                            ],
                            'foreign_keys' => [],
                            'profiles' => [],
                        ],
                        // Словарь назван не по конвенции — его находит внешний ключ от attr_id.
                        'party_attrs' => [
                            'row_count' => 30,
                            'columns' => [
                                ['name' => 'id', 'type' => 'bigint', 'nullable' => false],
                                ['name' => 'attr_id', 'type' => 'integer', 'nullable' => true],
                                ['name' => 'value_int', 'type' => 'integer', 'nullable' => true],
                            ],
                            'foreign_keys' => [
                                ['column' => 'attr_id', 'references_table' => 'tasks.attr_dict', 'references_column' => 'id'],
                            ],
                            'profiles' => [],
                        ],
                        'attr_dict' => [
                            'row_count' => 4,
                            'columns' => [
                                ['name' => 'id', 'type' => 'integer', 'nullable' => false],
                                ['name' => 'name', 'type' => 'character varying', 'nullable' => true],
                            ],
                            'foreign_keys' => [],
                            'profiles' => [],
                        ],
                        // Словаря нет вовсе: ни по имени, ни по связи.
                        'orphan_attrs' => [
                            'row_count' => 7,
                            'columns' => [
                                ['name' => 'id', 'type' => 'bigint', 'nullable' => false],
                                ['name' => 'attr_id', 'type' => 'integer', 'nullable' => true],
                            ],
                            'foreign_keys' => [],
                            'profiles' => [],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * Таблица есть в конфиге, но её нет в БД: экспорт молча её пропустит, поэтому
     * в досье она обязана появиться с пометкой — по ней R7 предложит удаление.
     */
    public function testConfiguredTableMissingFromInventoryIsMarkedPhantom(): void
    {
        $dossier = (new DossierBuilder())->build('tasks', $this->inventory(), $this->config());

        self::assertArrayHasKey('ghost_table', $dossier['tables']);
        $ghost = $dossier['tables']['ghost_table'];
        self::assertTrue($ghost['phantom']);
        self::assertSame('partial_export', $ghost['config']['mode']);
        self::assertNull($ghost['row_count']['value']);
        self::assertSame([], $ghost['columns']);

        // Существующие таблицы фантомами не помечаются.
        self::assertArrayNotHasKey('phantom', $dossier['tables']['jobs']);
    }

    private function config(): DumpConfig
    {
        return new DumpConfig([], [
            'tasks' => [
                'jobs' => [
                    'limit' => 500,
                    'sample' => ['criteria' => [['name' => 'open', 'where' => 'status_id = 1', 'limit' => 100]]],
                ],
                'ghost_table' => ['limit' => 10],
                'clients_attrs' => [
                    'limit' => 100,
                    'cascade_from' => [['parent' => 'tasks.jobs', 'fk_column' => 'job_id', 'parent_column' => 'id']],
                ],
            ],
        ], [], new FakerConfig([
            'tasks' => [
                'jobs' => ['owner_name' => 'fio'],
            ],
        ]));
    }
}
