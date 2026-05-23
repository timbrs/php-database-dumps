<?php

namespace Timbrs\DatabaseDumps\Service\Analysis;

/**
 * Идемпотентная сборка REPORT.md из независимых секций.
 *
 * Отчёт состоит из двух независимых секций, которые пишут разные команды:
 *  - DATA-ANALYSIS — профиль данных + ПД (prepare-config --deep, AnalysisReportWriter),
 *  - CODE-ANALYSIS — связи/критерии из кода (apply-analysis, ConfigEnricher).
 *
 * Каждая секция обрамляется HTML-маркерами и заменяется ЦЕЛИКОМ при перезаписи —
 * поэтому порядок запуска команд не важен и повторный прогон не плодит дубли и не
 * затирает чужую секцию.
 */
class MarkdownReport
{
    public const TITLE = '# Отчёт углублённого анализа БД';

    public const SECTION_DATA = 'DATA-ANALYSIS';
    public const SECTION_CODE = 'CODE-ANALYSIS';

    /**
     * Вставить/заменить секцию между маркерами в существующем тексте отчёта.
     * Заголовок файла гарантируется. Если секции ещё нет — добавляется в конец.
     */
    public static function upsertSection(string $existing, string $marker, string $body): string
    {
        $existing = self::ensureTitle($existing);

        $begin = self::beginMarker($marker);
        $end = self::endMarker($marker);
        $block = $begin . "\n" . rtrim($body, "\n") . "\n" . $end;

        $startPos = strpos($existing, $begin);
        if ($startPos !== false) {
            $endPos = strpos($existing, $end, $startPos);
            if ($endPos !== false) {
                $endPos += strlen($end);
                return substr($existing, 0, $startPos) . $block . substr($existing, $endPos);
            }
        }

        $sep = (substr($existing, -1) === "\n") ? '' : "\n";
        return $existing . $sep . "\n" . $block . "\n";
    }

    private static function ensureTitle(string $existing): string
    {
        if ($existing === '') {
            return self::TITLE . "\n";
        }
        if (strpos($existing, self::TITLE) === false) {
            return self::TITLE . "\n\n" . $existing;
        }
        return $existing;
    }

    private static function beginMarker(string $marker): string
    {
        return "<!-- {$marker}:begin -->";
    }

    private static function endMarker(string $marker): string
    {
        return "<!-- {$marker}:end -->";
    }
}
