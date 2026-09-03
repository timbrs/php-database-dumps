<?php

namespace Timbrs\DatabaseDumps\Tests\Unit\Service\Validation;

use PHPUnit\Framework\TestCase;
use Timbrs\DatabaseDumps\Service\Validation\Finding;
use Timbrs\DatabaseDumps\Service\Validation\FindingCatalog;

class FindingCatalogTest extends TestCase
{
    /**
     * Реестр — единственный источник кодов для check и docs. Код, который умеет выдавать
     * инструмент, но которого нет в реестре, попадёт в отчёт без стадии и без строки в
     * FINDINGS.md — поэтому сверяем со всеми литералами кодов в src.
     */
    public function testEveryCodeEmittedBySourceIsInTheCatalog(): void
    {
        $emitted = [];
        $directory = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(dirname(__DIR__, 4) . '/src'));
        foreach ($directory as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            if (preg_match_all('/\'([A-Z]-\d{1,2})\'/', (string) file_get_contents($file->getPathname()), $matches) === 0) {
                continue;
            }
            foreach ($matches[1] as $code) {
                $emitted[$code] = true;
            }
        }

        $missing = array_values(array_diff(array_keys($emitted), array_keys(FindingCatalog::all())));
        self::assertSame([], $missing, 'коды без записи в реестре: ' . implode(', ', $missing));
    }

    public function testEntriesAreWellFormed(): void
    {
        foreach (FindingCatalog::all() as $code => $entry) {
            self::assertContains($entry['stage'], FindingCatalog::stages(), $code);
            self::assertContains($entry['severity'], Finding::SEVERITIES, $code);
            self::assertNotSame('', $entry['title'], $code);
            self::assertContains($entry['decides'], [
                FindingCatalog::DECIDES_TOOL,
                FindingCatalog::DECIDES_AGENT,
                FindingCatalog::DECIDES_HUMAN,
            ], $code);
        }
    }

    public function testStageOfKnownAndUnknownCodes(): void
    {
        self::assertSame(FindingCatalog::STAGE_DUMP, FindingCatalog::stageOf('V-1'));
        self::assertSame(FindingCatalog::STAGE_IMPORT, FindingCatalog::stageOf('I-2'));
        self::assertSame(FindingCatalog::STAGE_LIVE, FindingCatalog::stageOf('Q-8'));
        self::assertSame(FindingCatalog::STAGE_STATIC, FindingCatalog::stageOf('WAT-9'));
        self::assertFalse(FindingCatalog::has('WAT-9'));
    }

    public function testMarkdownListsEveryCodeUnderItsStage(): void
    {
        $markdown = FindingCatalog::renderMarkdown();

        foreach (FindingCatalog::all() as $code => $entry) {
            self::assertStringContainsString('| `' . $code . '` |', $markdown);
        }
        self::assertStringContainsString('## ' . FindingCatalog::stageTitle(FindingCatalog::STAGE_IMPORT), $markdown);
    }
}
