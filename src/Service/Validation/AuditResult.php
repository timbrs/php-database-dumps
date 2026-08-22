<?php

namespace Timbrs\DatabaseDumps\Service\Validation;

/**
 * Итог аудита: находки, сводка покрытия и метаданные прогона.
 *
 * Отдельный объект, потому что вызывающему нужны обе половины: находки — чтобы чинить и
 * докладывать, покрытие — чтобы сказать «243 из 245» без пересчёта руками.
 */
class AuditResult
{
    /** @var array<int, Finding> */
    private $findings;

    /** @var array{schemas: array<string, array<string, int>>, totals: array<string, int>} */
    private $coverage;

    /** @var array<string, mixed> */
    private $meta;

    /**
     * @param array<int, Finding> $findings
     * @param array{schemas: array<string, array<string, int>>, totals: array<string, int>} $coverage
     * @param array<string, mixed> $meta
     */
    public function __construct(array $findings, array $coverage, array $meta)
    {
        $this->findings = $findings;
        $this->coverage = $coverage;
        $this->meta = $meta;
    }

    /**
     * @return array<int, Finding>
     */
    public function getFindings(): array
    {
        return $this->findings;
    }

    /**
     * Находки не ниже указанного уровня важности.
     *
     * @return array<int, Finding>
     */
    public function findingsAtLeast(string $severity): array
    {
        $threshold = array_search($severity, Finding::SEVERITIES, true);
        if ($threshold === false) {
            return $this->findings;
        }

        $filtered = [];
        foreach ($this->findings as $finding) {
            if ($finding->severityRank() <= $threshold) {
                $filtered[] = $finding;
            }
        }
        return $filtered;
    }

    /**
     * @return array{schemas: array<string, array<string, int>>, totals: array<string, int>}
     */
    public function getCoverage(): array
    {
        return $this->coverage;
    }

    /**
     * @return array<string, mixed>
     */
    public function getMeta(): array
    {
        return $this->meta;
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
     * @return array<string, int> код находки => сколько раз встретился
     */
    public function countsByCode(): array
    {
        $counts = [];
        foreach ($this->findings as $finding) {
            $code = $finding->getCode();
            $counts[$code] = isset($counts[$code]) ? $counts[$code] + 1 : 1;
        }
        ksort($counts);
        return $counts;
    }

    public function hasErrors(): bool
    {
        return $this->countBySeverity(Finding::SEVERITY_ERROR) > 0;
    }

    /**
     * Находки, которые AuditFixer умеет применить сам.
     *
     * @return array<int, Finding>
     */
    public function fixableFindings(): array
    {
        $fixable = [];
        foreach ($this->findings as $finding) {
            if ($finding->isFixable()) {
                $fixable[] = $finding;
            }
        }
        return $fixable;
    }
}
