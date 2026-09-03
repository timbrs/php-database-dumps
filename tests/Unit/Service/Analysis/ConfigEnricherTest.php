<?php

namespace Timbrs\DatabaseDumps\Tests\Unit\Service\Analysis;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;
use Timbrs\DatabaseDumps\Contract\FileSystemInterface;
use Timbrs\DatabaseDumps\Contract\LoggerInterface;
use Timbrs\DatabaseDumps\Service\Analysis\ConfigEnricher;
use Timbrs\DatabaseDumps\Service\ConfigGenerator\ConfigSplitter;

class ConfigEnricherTest extends TestCase
{
    /** @var array<string, string> path => content */
    private $written = [];

    private const CONFIG_PATH = '/proj/docker/database/dump_config.yaml';

    private function enricher(string $configYaml): ConfigEnricher
    {
        $this->written = [];
        $fs = $this->createMock(FileSystemInterface::class);
        $fs->method('exists')->willReturnCallback(function ($path) {
            // конфиг существует; reportPath — нет (создаём новый)
            return strpos($path, 'REPORT.md') === false;
        });
        $fs->method('isDirectory')->willReturn(true);
        $fs->method('read')->willReturnCallback(function ($path) use ($configYaml) {
            if ($path === self::CONFIG_PATH) {
                return $configYaml;
            }
            return $this->written[$path] ?? '';
        });
        $fs->method('write')->willReturnCallback(function ($path, $content) {
            $this->written[$path] = $content;
        });

        $splitter = $this->createMock(ConfigSplitter::class);

        return new ConfigEnricher($fs, $splitter, $this->createMock(LoggerInterface::class));
    }

    private function baseConfig(): string
    {
        return Yaml::dump([
            'partial_export' => [
                'public' => [
                    'orders' => ['limit' => 500],
                    'clients' => ['limit' => 500],
                ],
            ],
        ], 6, 2);
    }

    /**
     * @return array{cascade_from: array<int, array<string, mixed>>, sample_criteria: array<int, array<string, mixed>>}
     */
    private function ingested(): array
    {
        return [
            'cascade_from' => [
                [
                    'schema' => 'public', 'table' => 'orders',
                    'parent' => 'public.clients', 'fk_column' => 'client_id', 'parent_column' => 'id',
                    'source' => 'code', 'confidence' => 90, 'kind' => 'belongs_to',
                ],
            ],
            'sample_criteria' => [
                [
                    'schema' => 'public', 'table' => 'clients',
                    'name' => 'active', 'where' => "status = 'active'", 'limit' => 50,
                    'source' => 'code', 'confidence' => 85,
                ],
            ],
        ];
    }

    public function testEnrichesCascadeFromAndSample(): void
    {
        $enricher = $this->enricher($this->baseConfig());
        $stats = $enricher->enrich(self::CONFIG_PATH, $this->ingested());

        $this->assertSame(1, $stats['cascade_added']);
        $this->assertSame(1, $stats['criteria_added']);

        $written = Yaml::parse($this->written[self::CONFIG_PATH]);
        $orders = $written['partial_export']['public']['orders'];
        $this->assertArrayHasKey('cascade_from', $orders);
        $this->assertSame('public.clients', $orders['cascade_from'][0]['parent']);

        $clients = $written['partial_export']['public']['clients'];
        $this->assertArrayHasKey('sample', $clients);
        $this->assertSame('active', $clients['sample']['criteria'][0]['name']);
        $this->assertSame(50, $clients['sample']['criteria'][0]['limit']);
    }

    public function testDoesNotDuplicateExistingCascade(): void
    {
        $yaml = Yaml::dump([
            'partial_export' => [
                'public' => [
                    'orders' => [
                        'limit' => 500,
                        'cascade_from' => [
                            ['parent' => 'public.clients', 'fk_column' => 'client_id', 'parent_column' => 'id'],
                        ],
                    ],
                    'clients' => ['limit' => 500],
                ],
            ],
        ], 6, 2);

        $enricher = $this->enricher($yaml);
        $stats = $enricher->enrich(self::CONFIG_PATH, $this->ingested());
        $this->assertSame(0, $stats['cascade_added']); // уже есть — не дублируем
    }

    public function testAppendsReport(): void
    {
        $enricher = $this->enricher($this->baseConfig());
        $enricher->enrich(self::CONFIG_PATH, $this->ingested());

        $reportPath = '/proj/docker/database/analysis/REPORT.md';
        $this->assertArrayHasKey($reportPath, $this->written);
        $this->assertStringContainsString('Анализ кода (OPENCODE)', $this->written[$reportPath]);
        $this->assertStringContainsString('public.clients', $this->written[$reportPath]);
    }

    public function testThrowsWhenConfigMissing(): void
    {
        $fs = $this->createMock(FileSystemInterface::class);
        $fs->method('exists')->willReturn(false);
        $enricher = new ConfigEnricher($fs, $this->createMock(ConfigSplitter::class), $this->createMock(LoggerInterface::class));

        $this->expectException(\RuntimeException::class);
        $enricher->enrich(self::CONFIG_PATH, $this->ingested());
    }

    public function testMovesFullExportTableToPartialWhenCriteriaApplied(): void
    {
        $yaml = Yaml::dump([
            'full_export' => ['public' => ['clients']],
            'partial_export' => ['public' => ['orders' => ['limit' => 500]]],
        ], 6, 2);

        $enricher = $this->enricher($yaml);
        $enricher->enrich(self::CONFIG_PATH, $this->ingested());

        $written = Yaml::parse($this->written[self::CONFIG_PATH]);
        // clients переехала из full_export в partial_export
        $this->assertArrayHasKey('clients', $written['partial_export']['public']);
        $this->assertArrayHasKey('sample', $written['partial_export']['public']['clients']);
        $fullClients = $written['full_export']['public'] ?? [];
        $this->assertNotContains('clients', $fullClients);
    }

    /**
     * Недоверенный вывод агента с path traversal в schema/table должен отбрасываться,
     * не загрязняя конфиг и не порождая файлов схем за пределами каталога.
     */
    public function testRejectsPathTraversalInSchemaTable(): void
    {
        $enricher = $this->enricher($this->baseConfig());
        $ingested = [
            'cascade_from' => [
                [
                    'schema' => '../../etc', 'table' => 'passwd',
                    'parent' => 'public.clients', 'fk_column' => 'client_id', 'parent_column' => 'id',
                ],
            ],
            'sample_criteria' => [
                [
                    'schema' => 'public', 'table' => '../evil',
                    'name' => 'x', 'where' => 'a = 1', 'limit' => 10,
                ],
            ],
        ];

        $stats = $enricher->enrich(self::CONFIG_PATH, $ingested);
        $this->assertSame(0, $stats['cascade_added']);
        $this->assertSame(0, $stats['criteria_added']);

        $written = Yaml::parse($this->written[self::CONFIG_PATH]);
        // Никаких поддельных ключей схем не появилось.
        $this->assertArrayNotHasKey('../../etc', $written['partial_export']);
        $this->assertArrayNotHasKey('../evil', $written['partial_export']['public']);
    }

    /**
     * Невалидное предложение (например, where с ';') должно отбрасываться
     * и НЕ должно создавать пустую запись таблицы / двигать её из full_export.
     */
    public function testRejectedSuggestionDoesNotPolluteConfig(): void
    {
        $yaml = Yaml::dump([
            'full_export' => ['public' => ['clients']],
            'partial_export' => ['public' => ['orders' => ['limit' => 500]]],
        ], 6, 2);

        $enricher = $this->enricher($yaml);
        $ingested = [
            'cascade_from' => [],
            'sample_criteria' => [
                [
                    'schema' => 'public', 'table' => 'clients',
                    'name' => 'bad', 'where' => "status = 'a'; DROP TABLE x", 'limit' => 10,
                ],
            ],
        ];

        $stats = $enricher->enrich(self::CONFIG_PATH, $ingested);
        $this->assertSame(0, $stats['criteria_added']);

        $written = Yaml::parse($this->written[self::CONFIG_PATH]);
        // clients осталась в full_export, не переехала из-за отклонённого критерия.
        $this->assertContains('clients', $written['full_export']['public']);
        $this->assertArrayNotHasKey('clients', $written['partial_export']['public'] ?? []);
    }

    /**
     * Дедуп критериев по name: повторное обогащение тем же критерием ничего не добавляет.
     */
    public function testDoesNotDuplicateExistingCriterion(): void
    {
        $yaml = Yaml::dump([
            'partial_export' => [
                'public' => [
                    'clients' => [
                        'limit' => 500,
                        'sample' => ['criteria' => [['name' => 'active', 'where' => "status = 'active'", 'limit' => 50]]],
                    ],
                ],
            ],
        ], 6, 2);

        $enricher = $this->enricher($yaml);
        $stats = $enricher->enrich(self::CONFIG_PATH, $this->ingested());
        $this->assertSame(0, $stats['criteria_added']);
    }

    /**
     * Split-конфиг (с includes) должен записываться через ConfigSplitter, а не одним файлом.
     */
    public function testSplitConfigWritesViaSplitter(): void
    {
        $mainYaml = Yaml::dump(['includes' => ['public' => 'public.yaml']], 4, 2);
        $schemaYaml = Yaml::dump([
            'partial_export' => [
                'orders' => ['limit' => 500],
                'clients' => ['limit' => 500],
            ],
        ], 4, 2);

        $splitCalled = false;
        $splitConfig = null;

        $fs = $this->createMock(FileSystemInterface::class);
        $fs->method('exists')->willReturnCallback(function ($path) {
            return strpos($path, 'REPORT.md') === false; // конфиг и includes есть
        });
        $fs->method('isDirectory')->willReturn(true);
        $fs->method('read')->willReturnCallback(function ($path) use ($mainYaml, $schemaYaml) {
            if ($path === self::CONFIG_PATH) {
                return $mainYaml;
            }
            if (strpos($path, 'public.yaml') !== false) {
                return $schemaYaml;
            }
            return '';
        });
        $fs->method('write')->willReturnCallback(function ($path, $content): void {
            $this->written[$path] = $content;
        });

        $splitter = $this->createMock(ConfigSplitter::class);
        $splitter->expects($this->once())->method('split')
            ->willReturnCallback(function ($path, $config) use (&$splitCalled, &$splitConfig): void {
                $splitCalled = true;
                $splitConfig = $config;
            });

        $enricher = new ConfigEnricher($fs, $splitter, $this->createMock(LoggerInterface::class));
        $stats = $enricher->enrich(self::CONFIG_PATH, $this->ingested());

        $this->assertTrue($splitCalled, 'split() должен быть вызван для split-конфига');
        $this->assertSame(1, $stats['cascade_added']);
        $this->assertSame(1, $stats['criteria_added']);
        // includes резолвлены в плоскую структуру перед передачей в splitter.
        $this->assertArrayHasKey('orders', $splitConfig['partial_export']['public']);
        $this->assertArrayHasKey('cascade_from', $splitConfig['partial_export']['public']['orders']);
        // Основной конфиг НЕ перезаписан как одиночный файл напрямую.
        $this->assertArrayNotHasKey(self::CONFIG_PATH, $this->written);
    }

    /**
     * Повторный apply-analysis заменяет секцию кода, а не плодит дубли;
     * секция данных (от --deep) и code_analysis в JSON сохраняются.
     */
    public function testCodeSectionIsIdempotentAndCoexistsWithDataSection(): void
    {
        /** @var array<string, string> $written */
        $written = [];
        $configYaml = $this->baseConfig();
        $fs = $this->createMock(FileSystemInterface::class);
        $fs->method('exists')->willReturnCallback(function ($path) use (&$written) {
            return $path === self::CONFIG_PATH || isset($written[$path]);
        });
        $fs->method('isDirectory')->willReturn(true);
        $fs->method('read')->willReturnCallback(function ($path) use (&$written, $configYaml) {
            if ($path === self::CONFIG_PATH) {
                return $configYaml;
            }
            return $written[$path] ?? '';
        });
        $fs->method('write')->willReturnCallback(function ($path, $content) use (&$written): void {
            $written[$path] = $content;
        });

        // Секция данных уже записана прогоном --deep.
        $reportPath = '/proj/docker/database/analysis/REPORT.md';
        $written[$reportPath] = "# Отчёт углублённого анализа БД\n<!-- DATA-ANALYSIS:begin -->\n## Анализ данных\nпрофиль\n<!-- DATA-ANALYSIS:end -->\n";

        $enricher = new ConfigEnricher($fs, $this->createMock(ConfigSplitter::class), $this->createMock(LoggerInterface::class), '/proj');
        $enricher->enrich(self::CONFIG_PATH, $this->ingested());
        $enricher->enrich(self::CONFIG_PATH, $this->ingested()); // повторно

        $md = $written[$reportPath];
        $this->assertSame(1, substr_count($md, 'CODE-ANALYSIS:begin'), 'секция кода не должна дублироваться');
        $this->assertStringContainsString('профиль', $md, 'секция данных сохранена');
        $this->assertStringContainsString('Анализ кода (OPENCODE)', $md);

        // code_analysis записан в JSON.
        $jsonPath = '/proj/docker/database/analysis/analysis_result.json';
        $this->assertArrayHasKey($jsonPath, $written);
        $decoded = json_decode($written[$jsonPath], true);
        $this->assertArrayHasKey('code_analysis', $decoded);
        $this->assertSame(1, $decoded['code_analysis']['cascade_added']);
    }

    /**
     * При заданном projectDir отчёт пишется в projectDir/docker/database/analysis,
     * независимо от расположения конфига (важно для Symfony, где конфиг лежит в config/).
     */
    public function testReportAnchoredOnProjectDirWhenProvided(): void
    {
        $this->written = [];
        $fs = $this->createMock(FileSystemInterface::class);
        $fs->method('exists')->willReturnCallback(function ($path) {
            return strpos($path, 'REPORT.md') === false;
        });
        $fs->method('isDirectory')->willReturn(true);
        $fs->method('read')->willReturnCallback(function ($path) {
            // Конфиг лежит в config/ (как в Symfony по умолчанию).
            if ($path === '/proj/config/dump_config.yaml') {
                return $this->baseConfig();
            }
            return $this->written[$path] ?? '';
        });
        $fs->method('write')->willReturnCallback(function ($path, $content): void {
            $this->written[$path] = $content;
        });

        $enricher = new ConfigEnricher(
            $fs,
            $this->createMock(ConfigSplitter::class),
            $this->createMock(LoggerInterface::class),
            '/proj'
        );
        $enricher->enrich('/proj/config/dump_config.yaml', $this->ingested());

        // Отчёт — в projectDir/docker/database/analysis, а не рядом с конфигом (config/analysis).
        $this->assertArrayHasKey('/proj/docker/database/analysis/REPORT.md', $this->written);
        $this->assertArrayNotHasKey('/proj/config/analysis/REPORT.md', $this->written);
    }

    public function testSelfHealsBrokenSameNameCriterion(): void
    {
        // Существующий criterion 'active' СИНТАКСИЧЕСКИ БИТЫЙ (алиас t1.) — попал на старой версии.
        $config = Yaml::dump([
            'partial_export' => [
                'public' => [
                    'clients' => [
                        'limit' => 500,
                        'sample' => ['criteria' => [
                            ['name' => 'active', 'where' => 't1.status = 1', 'limit' => 50],
                        ]],
                    ],
                ],
            ],
        ], 6, 2);

        $this->enricher($config)->enrich(self::CONFIG_PATH, $this->ingested());

        $written = Yaml::parse($this->written[self::CONFIG_PATH]);
        $criteria = $written['partial_export']['public']['clients']['sample']['criteria'];
        // Битый 'active' ЗАМЕНЁН исправленным (из ingest), не задвоился.
        $this->assertCount(1, $criteria);
        $this->assertSame('active', $criteria[0]['name']);
        $this->assertSame("status = 'active'", $criteria[0]['where']);
    }

    public function testKeepsValidSameNameCriterion(): void
    {
        // Существующий 'active' ВАЛИДНЫЙ (пользовательский) — в приоритете, новый пропускаем.
        $config = Yaml::dump([
            'partial_export' => [
                'public' => [
                    'clients' => [
                        'limit' => 500,
                        'sample' => ['criteria' => [
                            ['name' => 'active', 'where' => 'is_active = true', 'limit' => 50],
                        ]],
                    ],
                ],
            ],
        ], 6, 2);

        $this->enricher($config)->enrich(self::CONFIG_PATH, $this->ingested());

        $written = Yaml::parse($this->written[self::CONFIG_PATH]);
        $criteria = $written['partial_export']['public']['clients']['sample']['criteria'];
        $this->assertCount(1, $criteria);
        $this->assertSame('is_active = true', $criteria[0]['where']); // не заменён
    }

    public function testApplyDecisionsWritesConfigAndReport(): void
    {
        $enricher = $this->enricher($this->baseConfig());
        $stats = $enricher->applyDecisions(self::CONFIG_PATH, [
            [
                'id' => 'a1',
                'table' => 'public.orders',
                'column' => null,
                'kind' => 'limit',
                'current' => 500,
                'proposed' => 2000,
                'rule' => 'R1',
                'why' => 'таблица крупнее порога',
                'evidence' => [['source' => 'db', 'note' => 'reltuples']],
                'confidence' => 'high',
                'auto' => false,
                'accepted' => true,
                'override' => true,
            ],
            [
                'id' => 'a2',
                'table' => 'public.clients',
                'column' => 'last_name',
                'kind' => 'faker',
                'current' => null,
                'proposed' => 'lastname',
                'rule' => 'R4',
                'why' => 'колонка с ПД-именем без faker',
                'evidence' => [],
                'confidence' => 'high',
                'auto' => true,
            ],
            [
                'id' => 'a3',
                'table' => 'public.clients',
                'column' => null,
                'kind' => 'stratify',
                'current' => null,
                'proposed' => [['column' => 'status_id', 'per_value' => 100]],
                'rule' => 'R3',
                'why' => 'категориальная колонка без покрытия',
                'evidence' => [],
                'confidence' => 'med',
                'auto' => false,
            ],
        ]);

        $this->assertSame(2, $stats['applied']);
        $this->assertSame(1, $stats['skipped']);
        $this->assertSame(0, $stats['stale']);

        $written = Yaml::parse($this->written[self::CONFIG_PATH]);
        $this->assertSame(2000, $written['partial_export']['public']['orders']['limit']);
        $this->assertSame('lastname', $written['faker']['public']['clients']['last_name']);
        // Решение без accepted в конфиг не попало.
        $this->assertArrayNotHasKey('sample', $written['partial_export']['public']['clients']);

        $reportPath = '/proj/docker/database/analysis/' . ConfigEnricher::APPLY_REPORT_FILE;
        $this->assertArrayHasKey($reportPath, $this->written);
        $report = json_decode($this->written[$reportPath], true);
        $this->assertSame(3, $report['summary']['total']);
        $this->assertSame(['public.clients', 'public.orders'], $report['changed_tables']);

        // Провенанс в отчёте: правило, обоснование и доказательства.
        $byId = [];
        foreach ($report['decisions'] as $entry) {
            $byId[$entry['id']] = $entry;
        }
        $this->assertSame('R1', $byId['a1']['rule']);
        $this->assertSame('таблица крупнее порога', $byId['a1']['why']);
        $this->assertSame('db', $byId['a1']['evidence'][0]['source']);
        $this->assertSame('applied', $byId['a1']['status']);
        $this->assertSame('skipped_not_accepted', $byId['a3']['status']);
        $this->assertFalse($byId['a3']['stale']);
    }

    /**
     * Конфиг поправили после анализа: решение видело другое значение и молча затирать
     * ручную правку не должно.
     */
    public function testApplyDecisionsMarksOutdatedDecisionStale(): void
    {
        $enricher = $this->enricher($this->baseConfig());
        $stats = $enricher->applyDecisions(self::CONFIG_PATH, [[
            'id' => 'b1',
            'table' => 'public.orders',
            'kind' => 'limit',
            'current' => 10,
            'proposed' => 2000,
            'rule' => 'R1',
            'why' => 'порог',
            'auto' => false,
            'accepted' => true,
            'override' => true,
        ]]);

        $this->assertSame(0, $stats['applied']);
        $this->assertSame(1, $stats['stale']);
        // Конфиг не перезаписан: applied == 0.
        $this->assertArrayNotHasKey(self::CONFIG_PATH, $this->written);

        $report = json_decode(
            $this->written['/proj/docker/database/analysis/' . ConfigEnricher::APPLY_REPORT_FILE],
            true
        );
        $this->assertTrue($report['decisions'][0]['stale']);
        $this->assertSame(1, $report['summary']['by_status']['stale']);
    }

    public function testApplyDecisionsReportsInvalidProposal(): void
    {
        $enricher = $this->enricher($this->baseConfig());
        $stats = $enricher->applyDecisions(self::CONFIG_PATH, [[
            'id' => 'c1',
            'table' => 'public.orders',
            'kind' => 'order_by',
            'current' => null,
            'proposed' => ['не строка'],
            'rule' => 'R1',
            'why' => 'нужен детерминированный порядок',
            'auto' => true,
        ]]);

        $this->assertSame(0, $stats['applied']);
        $this->assertSame(1, $stats['invalid']);
        $this->assertArrayNotHasKey(self::CONFIG_PATH, $this->written);
    }
}
