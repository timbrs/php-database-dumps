<?php

namespace Timbrs\DatabaseDumps\Service\Validation;

/**
 * Человекочитаемый отчёт аудита — общий для обоих бриджей: рендерер отдаёт строки,
 * команда их печатает.
 */
class TextReportRenderer
{
    /**
     * @param string $severity порог вывода находок (error|warning|note)
     * @return array<int, string>
     */
    public function render(AuditResult $result, string $severity = Finding::SEVERITY_NOTE): array
    {
        $meta = $result->getMeta();
        $lines = [];

        $lines[] = 'Аудит конфигурации выгрузки — без подключения к БД';
        $lines[] = 'конфиг: ' . (isset($meta['config_path']) ? (string) $meta['config_path'] : '?');
        $inventory = isset($meta['inventory_path']) ? (string) $meta['inventory_path'] : '?';
        if (empty($meta['inventory_present'])) {
            $lines[] = 'слепок: ' . $inventory . ' — НЕ НАЙДЕН, сверять колонки не с чем';
        } else {
            $lines[] = 'слепок: ' . $inventory . ' (собран ' .
                (isset($meta['inventory_generated_at']) && $meta['inventory_generated_at'] !== null
                    ? (string) $meta['inventory_generated_at']
                    : 'дата неизвестна') . ')';
        }
        if (!empty($meta['schema_filter'])) {
            $lines[] = 'схемы: ' . implode(', ', (array) $meta['schema_filter']);
        }

        $lines[] = '';
        foreach ($this->coverageLines($result) as $line) {
            $lines[] = $line;
        }

        $lines[] = '';
        foreach ($this->findingLines($result, $severity) as $line) {
            $lines[] = $line;
        }

        $lines[] = '';
        $lines[] = sprintf(
            'Итог: ошибок %d, предупреждений %d, заметок %d; чинится автоматически — %d',
            $result->countBySeverity(Finding::SEVERITY_ERROR),
            $result->countBySeverity(Finding::SEVERITY_WARNING),
            $result->countBySeverity(Finding::SEVERITY_NOTE),
            count($result->fixableFindings())
        );

        return $lines;
    }

    /**
     * @return array<int, string>
     */
    private function coverageLines(AuditResult $result): array
    {
        $coverage = $result->getCoverage();
        $header = ['схема', 'в слепке', 'выгружается', 'не покрыто', 'вне слепка', 'full', 'partial', 'sample'];
        $rows = [];

        foreach ($coverage['schemas'] as $schema => $row) {
            $rows[] = [
                (string) $schema,
                (string) $row['inventory'],
                (string) $row['covered'],
                (string) $row['uncovered'],
                (string) $row['unknown'],
                (string) $row['full_export'],
                (string) $row['partial'],
                (string) $row['sample'],
            ];
        }

        $totals = $coverage['totals'];
        $rows[] = [
            'ИТОГО',
            (string) $totals['inventory'],
            (string) $totals['covered'],
            (string) $totals['uncovered'],
            (string) $totals['unknown'],
            (string) $totals['full_export'],
            (string) $totals['partial'],
            (string) $totals['sample'],
        ];

        $lines = ['Покрытие'];
        foreach ($this->table($header, $rows) as $line) {
            $lines[] = '  ' . $line;
        }
        $lines[] = sprintf(
            '  всего таблиц в конфиге: %d (из них вне слепка: %d)',
            $totals['config'],
            $totals['unknown']
        );

        return $lines;
    }

    /**
     * @return array<int, string>
     */
    private function findingLines(AuditResult $result, string $severity): array
    {
        $findings = $result->findingsAtLeast($severity);
        if (empty($findings)) {
            return ['Находки: нет (порог вывода — ' . $severity . ')'];
        }

        $lines = [sprintf('Находки (%d при пороге «%s»)', count($findings), $severity)];
        foreach ($findings as $finding) {
            $target = $finding->getTarget();
            $lines[] = sprintf(
                '  %-8s %-4s %s%s%s',
                $finding->getSeverity(),
                $finding->getCode(),
                $target === '' ? '' : $target . ' — ',
                $finding->getMessage(),
                $finding->isFixable() ? ' [--fix]' : ''
            );
        }

        $counts = $result->countsByCode();
        if (!empty($counts)) {
            $parts = [];
            foreach ($counts as $code => $count) {
                $parts[] = $code . ': ' . $count;
            }
            $lines[] = '  по кодам — ' . implode(', ', $parts);
        }

        return $lines;
    }

    /**
     * Простая таблица с выравниванием по ширине колонок.
     *
     * @param array<int, string> $header
     * @param array<int, array<int, string>> $rows
     * @return array<int, string>
     */
    private function table(array $header, array $rows): array
    {
        $widths = [];
        foreach ($header as $index => $title) {
            $widths[$index] = $this->width($title);
        }
        foreach ($rows as $row) {
            foreach ($row as $index => $cell) {
                $length = $this->width($cell);
                if (!isset($widths[$index]) || $length > $widths[$index]) {
                    $widths[$index] = $length;
                }
            }
        }

        $lines = [$this->row($header, $widths)];
        foreach ($rows as $row) {
            $lines[] = $this->row($row, $widths);
        }

        return $lines;
    }

    /**
     * @param array<int, string> $cells
     * @param array<int, int> $widths
     */
    private function row(array $cells, array $widths): string
    {
        $parts = [];
        foreach ($cells as $index => $cell) {
            $pad = isset($widths[$index]) ? $widths[$index] - $this->width($cell) : 0;
            $parts[] = $index === 0
                ? $cell . str_repeat(' ', max(0, $pad))
                : str_repeat(' ', max(0, $pad)) . $cell;
        }
        return implode('  ', $parts);
    }

    /**
     * Ширина в символах, а не в байтах: имена схем и заголовки — кириллица.
     */
    private function width(string $value): int
    {
        if (function_exists('mb_strlen')) {
            return (int) mb_strlen($value, 'UTF-8');
        }
        return strlen($value);
    }
}
