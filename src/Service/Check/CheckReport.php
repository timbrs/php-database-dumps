<?php

namespace Timbrs\DatabaseDumps\Service\Check;

use Timbrs\DatabaseDumps\Service\Validation\Finding;
use Timbrs\DatabaseDumps\Service\Validation\FindingCatalog;

/**
 * Итог одного прогона check: по стадиям — что выполнялось, почему пропущено, сколько
 * заняло; общий список находок в одном пространстве кодов; покрытие из static-стадии.
 */
class CheckReport
{
    /** @var string */
    private $runId;

    /** @var array<string, array<string, mixed>> */
    private $stages = [];

    /** @var array<int, Finding> */
    private $findings = [];

    /** @var array<string, mixed>|null */
    private $coverage;

    /** @var array<string, mixed> */
    private $meta;

    /**
     * @param array<string, mixed> $meta
     */
    public function __construct(array $meta = [])
    {
        $this->runId = gmdate('Ymd\THis\Z') . '-' . bin2hex(random_bytes(3));
        $this->meta = $meta;
    }

    /**
     * @param array<int, Finding>  $findings
     * @param array<string, mixed> $extra   стадийные данные (план, статистика проверок, автоправки)
     */
    public function addStage(string $name, bool $ran, ?string $whySkipped, int $ms, ?int $queries, array $findings, array $extra = []): void
    {
        $this->stages[$name] = array_merge([
            'ran' => $ran,
            'why_skipped' => $whySkipped,
            'ms' => $ms,
            'queries' => $queries,
            'findings' => count($findings),
        ], $extra);
        foreach ($findings as $finding) {
            $this->findings[] = $finding;
        }
    }

    /**
     * @param array<string, mixed> $coverage
     */
    public function setCoverage(array $coverage): void
    {
        $this->coverage = $coverage;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function getStages(): array
    {
        return $this->stages;
    }

    public function stageRan(string $name): bool
    {
        return !empty($this->stages[$name]['ran']);
    }

    /**
     * @return array<int, Finding>
     */
    public function getFindings(): array
    {
        return $this->sorted($this->findings);
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

    /**
     * Есть ли находки не ниже порога (error < warning < note).
     */
    public function hasAtLeast(string $severity): bool
    {
        $threshold = array_search($severity, Finding::SEVERITIES, true);
        if ($threshold === false) {
            $threshold = 0;
        }
        foreach ($this->findings as $finding) {
            if ($finding->severityRank() <= $threshold) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $byCode = [];
        $findings = [];
        foreach ($this->getFindings() as $finding) {
            $byCode[$finding->getCode()] = ($byCode[$finding->getCode()] ?? 0) + 1;
            $entry = $finding->toArray();
            $entry['stage'] = FindingCatalog::stageOf($finding->getCode());
            $findings[] = $entry;
        }
        ksort($byCode);

        return array_merge(
            [
                'generated_at' => gmdate('Y-m-d\TH:i:s\Z'),
                'run_id' => $this->runId,
            ],
            $this->meta,
            [
                'stages' => $this->stages,
                'summary' => [
                    'total' => count($this->findings),
                    'error' => $this->countBySeverity(Finding::SEVERITY_ERROR),
                    'warning' => $this->countBySeverity(Finding::SEVERITY_WARNING),
                    'note' => $this->countBySeverity(Finding::SEVERITY_NOTE),
                    'by_code' => $byCode,
                ],
                'coverage' => $this->coverage,
                'findings' => $findings,
            ]
        );
    }

    /**
     * Порядок: важность, код, адрес — отчёты двух прогонов отличаются содержанием, не перестановкой.
     *
     * @param array<int, Finding> $findings
     * @return array<int, Finding>
     */
    private function sorted(array $findings): array
    {
        usort($findings, function (Finding $a, Finding $b): int {
            $bySeverity = $a->severityRank() <=> $b->severityRank();
            if ($bySeverity !== 0) {
                return $bySeverity;
            }
            $byCode = strcmp($a->getCode(), $b->getCode());

            return $byCode !== 0 ? $byCode : strcmp($a->getTarget(), $b->getTarget());
        });

        return $findings;
    }
}
