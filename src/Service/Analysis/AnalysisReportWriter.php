<?php

namespace Timbrs\DatabaseDumps\Service\Analysis;

use Timbrs\DatabaseDumps\Util\JsonReport;
use Timbrs\DatabaseDumps\Contract\FileSystemInterface;

/**
 * Пишет результаты углублённого анализа в двух формах:
 *  - человекочитаемый отчёт {data_dir}/analysis/REPORT.md
 *  - машинный {data_dir}/analysis/analysis_result.json
 *
 * Провенанс/уверенность фиксируются здесь (symfony/yaml не хранит комментарии),
 * а сами значения попадают в dump_config.yaml через ConfigEnricher.
 *
 * Структура входного $analysis:
 *   [
 *     'generated_at' => string,
 *     'tables' => [
 *        [
 *          'connection'  => 'default'|string,
 *          'schema'      => string,
 *          'table'       => string,
 *          'export_mode' => 'full'|'partial',
 *          'row_count'   => int|null,
 *          'criteria'    => [ {name, where, limit, source, confidence} ],
 *          'pii'         => [ {column, pattern, source} ],
 *          'profiles'    => [ ColumnProfile::toArray() ],
 *        ], ...
 *     ],
 *   ]
 */
class AnalysisReportWriter
{
    public const REPORT_FILE = 'REPORT.md';
    public const JSON_FILE = 'analysis_result.json';

    /** @var FileSystemInterface */
    private $fileSystem;

    public function __construct(FileSystemInterface $fileSystem)
    {
        $this->fileSystem = $fileSystem;
    }

    /**
     * @param array<string, mixed> $analysis
     * @return array{report: string, json: string}
     */
    public function write(string $analysisDir, array $analysis): array
    {
        if (!$this->fileSystem->exists($analysisDir)) {
            $this->fileSystem->createDirectory($analysisDir);
        }

        $reportPath = rtrim($analysisDir, '/\\') . '/' . self::REPORT_FILE;
        $jsonPath = rtrim($analysisDir, '/\\') . '/' . self::JSON_FILE;

        // Идемпотентно обновляем ТОЛЬКО секцию данных, сохраняя секцию кода (CODE-ANALYSIS),
        // если apply-analysis уже её записал — порядок команд не важен.
        $existing = $this->fileSystem->exists($reportPath) ? $this->fileSystem->read($reportPath) : '';
        $report = MarkdownReport::upsertSection($existing, MarkdownReport::SECTION_DATA, $this->renderMarkdown($analysis));
        $this->fileSystem->write($reportPath, $report);

        // analysis_result.json: пишем секцию данных, сохраняя code_analysis (если есть).
        $result = $analysis;
        $existingJson = $this->readJson($jsonPath);
        if (isset($existingJson['code_analysis'])) {
            $result['code_analysis'] = $existingJson['code_analysis'];
        }

        $this->fileSystem->write($jsonPath, JsonReport::encode($result));

        return ['report' => $reportPath, 'json' => $jsonPath];
    }

    /**
     * Прочитать существующий analysis_result.json (или []), безопасно.
     *
     * @return array<string, mixed>
     */
    private function readJson(string $jsonPath): array
    {
        if (!$this->fileSystem->exists($jsonPath)) {
            return [];
        }
        $decoded = json_decode($this->fileSystem->read($jsonPath), true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string, mixed> $analysis
     */
    private function renderMarkdown(array $analysis): string
    {
        $generatedAt = isset($analysis['generated_at']) ? (string) $analysis['generated_at'] : '';
        $tables = isset($analysis['tables']) && is_array($analysis['tables']) ? $analysis['tables'] : [];

        $md = "## Анализ данных (профиль + ПД)\n\n";
        if ($generatedAt !== '') {
            $md .= "_Сгенерировано: {$generatedAt}_\n\n";
        }
        $md .= 'Всего таблиц в анализе: ' . count($tables) . "\n\n";
        $md .= "Источники: `data` — детерминированный профиль данных; `regex`/`llm` — детекция ПД; "
            . "`code` — анализ кода хоста (OPENCODE).\n\n";

        foreach ($tables as $t) {
            if (!is_array($t)) {
                continue;
            }
            $md .= $this->renderTable($t);
        }

        return $md;
    }

    /**
     * @param array<string, mixed> $t
     */
    private function renderTable(array $t): string
    {
        $conn = isset($t['connection']) ? (string) $t['connection'] : 'default';
        $schema = isset($t['schema']) ? (string) $t['schema'] : '';
        $table = isset($t['table']) ? (string) $t['table'] : '';
        $mode = isset($t['export_mode']) ? (string) $t['export_mode'] : '';
        $rowCount = $t['row_count'] ?? null;

        $md = "## {$schema}.{$table}\n\n";
        $md .= "- Подключение: `{$conn}`\n";
        $md .= "- Режим экспорта: **{$mode}**\n";
        if ($rowCount !== null) {
            $md .= '- Строк в таблице: ' . (int) $rowCount . "\n";
        }
        $md .= "\n";

        $md .= $this->renderCriteria($t['criteria'] ?? []);
        $md .= $this->renderPii($t['pii'] ?? []);
        $md .= $this->renderProfiles($t['profiles'] ?? []);

        return $md;
    }

    /**
     * @param mixed $criteria
     */
    private function renderCriteria($criteria): string
    {
        if (!is_array($criteria) || empty($criteria)) {
            return '';
        }
        $md = "### Предложенные критерии выборки (sample.criteria)\n\n";
        $md .= "| name | source | confidence | limit | where |\n";
        $md .= "|------|--------|-----------|-------|-------|\n";
        foreach ($criteria as $c) {
            if (!is_array($c)) {
                continue;
            }
            $name = $this->mdCell((string) ($c['name'] ?? ''));
            $source = $this->mdCell((string) ($c['source'] ?? ''));
            $confidence = isset($c['confidence']) ? $this->mdCell((string) $c['confidence']) : '';
            $limit = isset($c['limit']) ? $this->mdCell((string) $c['limit']) : '';
            $where = $this->mdCell((string) ($c['where'] ?? ''));
            $md .= "| {$name} | {$source} | {$confidence} | {$limit} | `{$where}` |\n";
        }
        return $md . "\n";
    }

    /**
     * @param mixed $pii
     */
    private function renderPii($pii): string
    {
        if (!is_array($pii) || empty($pii)) {
            return '';
        }
        $md = "### Обнаруженные ПД (faker)\n\n";
        $md .= "| column | pattern | source |\n";
        $md .= "|--------|---------|--------|\n";
        foreach ($pii as $p) {
            if (!is_array($p)) {
                continue;
            }
            $col = $this->mdCell((string) ($p['column'] ?? ''));
            $pattern = $this->mdCell((string) ($p['pattern'] ?? ''));
            $source = $this->mdCell((string) ($p['source'] ?? ''));
            $md .= "| {$col} | {$pattern} | {$source} |\n";
        }
        return $md . "\n";
    }

    /**
     * @param mixed $profiles
     */
    private function renderProfiles($profiles): string
    {
        if (!is_array($profiles) || empty($profiles)) {
            return '';
        }
        $md = "### Профиль колонок\n\n";
        $md .= "| column | type | nullable | null% | distinct | categorical |\n";
        $md .= "|--------|------|----------|-------|----------|-------------|\n";
        foreach ($profiles as $p) {
            if (!is_array($p)) {
                continue;
            }
            $col = $this->mdCell((string) ($p['column'] ?? ''));
            $type = $this->mdCell((string) ($p['data_type'] ?? ''));
            $nullable = !empty($p['nullable']) ? 'да' : 'нет';
            $nullPct = isset($p['null_fraction']) ? round((float) $p['null_fraction'] * 100, 1) . '%' : '';
            $distinct = isset($p['distinct_count']) ? (string) $p['distinct_count'] : '';
            if (!empty($p['distinct_capped'])) {
                $distinct .= '+';
            }
            $categorical = !empty($p['categorical']) ? 'да' : 'нет';
            $md .= "| {$col} | {$type} | {$nullable} | {$nullPct} | {$distinct} | {$categorical} |\n";
        }
        return $md . "\n";
    }

    /**
     * Экранировать значение для ячейки markdown-таблицы.
     *
     * '|' экранируется (иначе ломает разметку), переводы строк/табы схлопываются
     * в пробел (ячейка таблицы однострочна).
     */
    private function mdCell(string $value): string
    {
        $value = str_replace(["\r\n", "\r", "\n", "\t"], ' ', $value);

        return str_replace('|', '\\|', $value);
    }
}
