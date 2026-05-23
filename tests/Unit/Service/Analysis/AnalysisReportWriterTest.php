<?php

namespace Timbrs\DatabaseDumps\Tests\Unit\Service\Analysis;

use PHPUnit\Framework\TestCase;
use Timbrs\DatabaseDumps\Contract\FileSystemInterface;
use Timbrs\DatabaseDumps\Service\Analysis\AnalysisReportWriter;

class AnalysisReportWriterTest extends TestCase
{
    /** @var array<string, string> path => content */
    private $written = [];

    private function fsMock(): FileSystemInterface
    {
        $fs = $this->createMock(FileSystemInterface::class);
        $fs->method('exists')->willReturn(true);
        $fs->method('write')->willReturnCallback(function ($path, $content) {
            $this->written[$path] = $content;
        });
        return $fs;
    }

    /**
     * @return array<string, mixed>
     */
    private function sampleAnalysis(): array
    {
        return [
            'generated_at' => '2026-05-25T00:00:00Z',
            'tables' => [
                [
                    'connection' => 'default',
                    'schema' => 'public',
                    'table' => 'clients',
                    'export_mode' => 'partial',
                    'row_count' => 12345,
                    'criteria' => [
                        ['name' => 'status_red', 'where' => "\"status\" = 'red'", 'limit' => 10, 'source' => 'data', 'confidence' => 100],
                    ],
                    'pii' => [
                        ['column' => 'full_name', 'pattern' => 'fio', 'source' => 'llm'],
                    ],
                    'profiles' => [
                        ['column' => 'status', 'data_type' => 'varchar', 'nullable' => false, 'null_fraction' => 0.0, 'distinct_count' => 3, 'distinct_capped' => false, 'categorical' => true, 'top_values' => []],
                    ],
                ],
            ],
        ];
    }

    public function testWritesReportAndJson(): void
    {
        $writer = new AnalysisReportWriter($this->fsMock());
        $paths = $writer->write('/proj/database/analysis', $this->sampleAnalysis());

        $this->assertArrayHasKey($paths['report'], $this->written);
        $this->assertArrayHasKey($paths['json'], $this->written);
        $this->assertStringEndsWith('REPORT.md', $paths['report']);
        $this->assertStringEndsWith('analysis_result.json', $paths['json']);
    }

    public function testReportMarkdownContainsKeySections(): void
    {
        $writer = new AnalysisReportWriter($this->fsMock());
        $paths = $writer->write('/proj/database/analysis', $this->sampleAnalysis());
        $md = $this->written[$paths['report']];

        $this->assertStringContainsString('# Отчёт углублённого анализа', $md);
        $this->assertStringContainsString('## public.clients', $md);
        $this->assertStringContainsString('**partial**', $md);
        $this->assertStringContainsString('status_red', $md);
        $this->assertStringContainsString("\"status\" = 'red'", $md);
        $this->assertStringContainsString('full_name', $md);
        $this->assertStringContainsString('fio', $md);
    }

    public function testJsonIsValidAndRoundTrips(): void
    {
        $writer = new AnalysisReportWriter($this->fsMock());
        $analysis = $this->sampleAnalysis();
        $paths = $writer->write('/proj/database/analysis', $analysis);

        $decoded = json_decode($this->written[$paths['json']], true);
        $this->assertIsArray($decoded);
        $this->assertSame('public', $decoded['tables'][0]['schema']);
        $this->assertSame('clients', $decoded['tables'][0]['table']);
    }

    /**
     * Stateful-мок ФС: read возвращает ранее записанный контент (для проверки идемпотентности).
     */
    private function statefulFs(): FileSystemInterface
    {
        $fs = $this->createMock(FileSystemInterface::class);
        $fs->method('exists')->willReturnCallback(function ($path) {
            return isset($this->written[$path]);
        });
        $fs->method('read')->willReturnCallback(function ($path) {
            return $this->written[$path] ?? '';
        });
        $fs->method('write')->willReturnCallback(function ($path, $content): void {
            $this->written[$path] = $content;
        });
        $fs->method('createDirectory');
        return $fs;
    }

    public function testRepeatedWriteDoesNotDuplicateDataSection(): void
    {
        $writer = new AnalysisReportWriter($this->statefulFs());
        $writer->write('/proj/database/analysis', $this->sampleAnalysis());
        $writer->write('/proj/database/analysis', $this->sampleAnalysis());

        $md = $this->written['/proj/database/analysis/REPORT.md'] ?? '';
        // Маркер секции данных и заголовок присутствуют ровно один раз.
        $this->assertSame(1, substr_count($md, 'DATA-ANALYSIS:begin'));
        $this->assertSame(1, substr_count($md, '# Отчёт углублённого анализа БД'));
    }

    public function testWritePreservesExistingCodeSection(): void
    {
        // Эмулируем, что apply-analysis уже записал секцию кода.
        $this->written['/proj/database/analysis/REPORT.md'] =
            "# Отчёт углублённого анализа БД\n<!-- CODE-ANALYSIS:begin -->\n## Анализ кода (OPENCODE)\nкод-данные\n<!-- CODE-ANALYSIS:end -->\n";

        $writer = new AnalysisReportWriter($this->statefulFs());
        $writer->write('/proj/database/analysis', $this->sampleAnalysis());

        $md = $this->written['/proj/database/analysis/REPORT.md'];
        // Секция кода НЕ затёрта прогоном --deep.
        $this->assertStringContainsString('Анализ кода (OPENCODE)', $md);
        $this->assertStringContainsString('код-данные', $md);
        // И добавлена секция данных.
        $this->assertStringContainsString('## public.clients', $md);
    }

    public function testWritePreservesExistingCodeAnalysisJsonKey(): void
    {
        $this->written['/proj/database/analysis/analysis_result.json'] =
            (string) json_encode(['code_analysis' => ['cascade_added' => 3]]);

        $writer = new AnalysisReportWriter($this->statefulFs());
        $writer->write('/proj/database/analysis', $this->sampleAnalysis());

        $decoded = json_decode($this->written['/proj/database/analysis/analysis_result.json'], true);
        $this->assertArrayHasKey('code_analysis', $decoded);
        $this->assertSame(3, $decoded['code_analysis']['cascade_added']);
        $this->assertArrayHasKey('tables', $decoded); // данные тоже записаны
    }

    public function testCreatesDirectoryWhenMissing(): void
    {
        $created = [];
        $fs = $this->createMock(FileSystemInterface::class);
        $fs->method('exists')->willReturn(false);
        $fs->method('createDirectory')->willReturnCallback(function ($path) use (&$created) {
            $created[] = $path;
        });
        $fs->method('write');

        $writer = new AnalysisReportWriter($fs);
        $writer->write('/proj/database/analysis', $this->sampleAnalysis());
        $this->assertContains('/proj/database/analysis', $created);
    }

    public function testPipeCharacterEscapedInTableCells(): void
    {
        $writer = new AnalysisReportWriter($this->fsMock());
        $analysis = [
            'generated_at' => 'now',
            'tables' => [
                [
                    'schema' => 'public',
                    'table' => 't',
                    'export_mode' => 'partial',
                    'criteria' => [
                        ['name' => 'a|b', 'where' => "x = 'a|b'", 'limit' => 10, 'source' => 'da|ta', 'confidence' => 100],
                    ],
                ],
            ],
        ];
        $paths = $writer->write('/d', $analysis);
        $md = $this->written[$paths['report']];

        // '|' внутри ячеек экранирован — нет «голых» разделителей внутри значений
        $this->assertStringContainsString('a\\|b', $md);
        $this->assertStringContainsString('da\\|ta', $md);
        // в where '|' тоже экранирован (внутри `...`)
        $this->assertStringContainsString("x = 'a\\|b'", $md);
    }

    public function testNewlineInCellCollapsed(): void
    {
        $writer = new AnalysisReportWriter($this->fsMock());
        $analysis = [
            'tables' => [
                [
                    'schema' => 's',
                    'table' => 't',
                    'pii' => [
                        ['column' => "multi\nline", 'pattern' => 'fio', 'source' => 'llm'],
                    ],
                ],
            ],
        ];
        $paths = $writer->write('/d', $analysis);
        $md = $this->written[$paths['report']];

        // Перевод строки в значении не должен ломать строку таблицы
        $this->assertStringNotContainsString("multi\nline", $md);
        $this->assertStringContainsString('multi line', $md);
    }

    public function testJsonSurvivesInvalidUtf8(): void
    {
        $writer = new AnalysisReportWriter($this->fsMock());
        $analysis = [
            'generated_at' => 'now',
            'tables' => [
                [
                    'schema' => 'public',
                    'table' => 'bin',
                    'profiles' => [
                        // битый UTF-8 в top_values (бинарная колонка)
                        ['column' => 'blob', 'top_values' => [['value' => "\xB1\x31", 'count' => 1]]],
                    ],
                ],
            ],
        ];
        $paths = $writer->write('/d', $analysis);
        $json = $this->written[$paths['json']];

        // Не обнулилось в '{}' — структура сохранена
        $this->assertNotSame('{}', $json);
        $decoded = json_decode($json, true);
        $this->assertIsArray($decoded);
        $this->assertSame('bin', $decoded['tables'][0]['table']);
    }

    public function testHandlesMissingOptionalSections(): void
    {
        $writer = new AnalysisReportWriter($this->fsMock());
        // Таблица без criteria/pii/profiles и без generated_at
        $analysis = [
            'tables' => [
                ['schema' => 'public', 'table' => 'bare', 'export_mode' => 'full'],
            ],
        ];
        $paths = $writer->write('/d', $analysis);
        $md = $this->written[$paths['report']];

        $this->assertStringContainsString('## public.bare', $md);
        $this->assertStringContainsString('**full**', $md);
        // Пустые секции не выводятся
        $this->assertStringNotContainsString('Предложенные критерии', $md);
        $this->assertStringNotContainsString('Обнаруженные ПД', $md);
        $this->assertStringNotContainsString('Профиль колонок', $md);
    }

    public function testSkipsNonArrayTableEntries(): void
    {
        $writer = new AnalysisReportWriter($this->fsMock());
        $analysis = [
            'tables' => [
                'garbage',
                ['schema' => 'public', 'table' => 'ok', 'export_mode' => 'full'],
            ],
        ];
        // Не должно бросить исключение на не-массиве
        $paths = $writer->write('/d', $analysis);
        $md = $this->written[$paths['report']];
        $this->assertStringContainsString('## public.ok', $md);
    }

    public function testEmptyAnalysisProducesValidOutput(): void
    {
        $writer = new AnalysisReportWriter($this->fsMock());
        $paths = $writer->write('/d', []);

        $md = $this->written[$paths['report']];
        $this->assertStringContainsString('Всего таблиц в анализе: 0', $md);

        $decoded = json_decode($this->written[$paths['json']], true);
        $this->assertIsArray($decoded);
    }
}
