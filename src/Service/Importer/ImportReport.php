<?php

namespace Timbrs\DatabaseDumps\Service\Importer;

use Timbrs\DatabaseDumps\Service\Validation\Finding;

/**
 * Что случилось при импорте, помимо «прошёл / упал»: пропущенные из-за расхождения схемы
 * таблицы (I-1), несовпадение числа строк после заливки (I-2), отставшие sequence (I-3),
 * нарушенные внешние ключи (I-4). Раньше всё это было предупреждениями в логе и exit 0.
 */
class ImportReport
{
    public const CODE_SCHEMA_MISMATCH = 'I-1';
    public const CODE_ROW_COUNT = 'I-2';
    public const CODE_SEQUENCE = 'I-3';
    public const CODE_FOREIGN_KEY = 'I-4';

    /** @var array<int, Finding> */
    private $findings = [];

    /** @var int */
    private $tablesImported = 0;

    /** @var int */
    private $tablesSkipped = 0;

    /** @var int */
    private $rowsLoaded = 0;

    public function add(Finding $finding): void
    {
        $this->findings[] = $finding;
    }

    public function tableImported(int $rows): void
    {
        $this->tablesImported++;
        $this->rowsLoaded += max(0, $rows);
    }

    public function tableSkipped(): void
    {
        $this->tablesSkipped++;
    }

    /**
     * @return array<int, Finding>
     */
    public function getFindings(): array
    {
        return $this->findings;
    }

    public function hasErrors(): bool
    {
        foreach ($this->findings as $finding) {
            if ($finding->getSeverity() === Finding::SEVERITY_ERROR) {
                return true;
            }
        }

        return false;
    }

    public function countBySeverity(string $severity): int
    {
        $count = 0;
        foreach ($this->findings as $finding) {
            if ($finding->getSeverity() === $severity) {
                $count++;
            }
        }

        return $count;
    }

    public function getTablesImported(): int
    {
        return $this->tablesImported;
    }

    public function getTablesSkipped(): int
    {
        return $this->tablesSkipped;
    }

    public function getRowsLoaded(): int
    {
        return $this->rowsLoaded;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $byCode = [];
        $findings = [];
        foreach ($this->findings as $finding) {
            $byCode[$finding->getCode()] = ($byCode[$finding->getCode()] ?? 0) + 1;
            $findings[] = $finding->toArray();
        }
        ksort($byCode);

        return [
            'tables_imported' => $this->tablesImported,
            'tables_skipped' => $this->tablesSkipped,
            'rows_loaded' => $this->rowsLoaded,
            'summary' => [
                'total' => count($this->findings),
                'error' => $this->countBySeverity(Finding::SEVERITY_ERROR),
                'warning' => $this->countBySeverity(Finding::SEVERITY_WARNING),
                'note' => $this->countBySeverity(Finding::SEVERITY_NOTE),
                'by_code' => $byCode,
            ],
            'findings' => $findings,
        ];
    }
}
