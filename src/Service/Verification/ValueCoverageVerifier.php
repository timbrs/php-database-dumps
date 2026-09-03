<?php

namespace Timbrs\DatabaseDumps\Service\Verification;

use Timbrs\DatabaseDumps\Config\TableConfig;
use Timbrs\DatabaseDumps\Service\Validation\Finding;
use Timbrs\DatabaseDumps\Service\Verification\Sink\CountingSetSink;

/**
 * V-5: все ли виды данных попали в дамп.
 *
 * «Сто красных, сто зелёных, сто закрытых» — цель выборки. Проверяется по слепку схемы:
 * для колонок с кодами (после шлюза) сверяются сами коды, для прочих категориальных —
 * только число различных значений. Значений ПД у проверки нет и быть не может: в слепке
 * их нет, а из дампа наружу уходит лишь счётчик.
 *
 * Коды из pg_stats — только частые значения (codes_complete = false), и отсутствие редкого
 * кода в дампе это предупреждение, а не ошибка: ошибкой станет расхождение с enum'ом кода,
 * когда появится привязка enum'ов к колонкам.
 */
class ValueCoverageVerifier implements DumpVerifierInterface
{
    /** В дампе нет части значений, которые есть в БД. */
    public const CODE_COVERAGE_GAP = 'V-5';

    /**
     * Потолок кардинальности категориальной колонки — тот же, что у профайлера
     * (ColumnStatisticsInspector::MAX_CATEGORICAL_DISTINCT), без зависимости от ConfigGenerator.
     */
    private const MAX_CATEGORICAL = 50;

    /** Потолок множества: нужны только «≤ 50» и «не меньше, чем в слепке». */
    private const CAP = self::MAX_CATEGORICAL + 1;

    private const MAX_VALUE_LENGTH = 64;

    /** @var array<string, array<string, array{sink: CountingSetSink, path: string, profile: array<string, mixed>}>> */
    private $planned = [];

    /** @var array<string, int> */
    private $stats = ['columns_checked' => 0, 'gaps' => 0];

    public function plan(DumpVerificationInput $input, DumpColumnStore $store): void
    {
        $this->planned = [];
        $this->stats = ['columns_checked' => 0, 'gaps' => 0];

        $inventory = $input->getInventory();
        if ($inventory === null) {
            return;
        }

        foreach ($input->getTables() as $config) {
            $path = $input->pathFor($config);
            if (!is_file($path)) {
                continue;
            }
            $key = $config->getFullTableName();
            foreach ($inventory->columns($config->getSchema(), $config->getTable()) as $column) {
                $profile = $inventory->profile($config->getSchema(), $config->getTable(), $column);
                if ($profile === null || !$this->isWorthChecking($profile)) {
                    continue;
                }
                $sink = new CountingSetSink(self::CAP, self::MAX_VALUE_LENGTH);
                $store->request($path, $column, $sink);
                $this->planned[$key][$column] = ['sink' => $sink, 'path' => $path, 'profile' => $profile];
            }
        }
    }

    public function check(DumpVerificationInput $input, DumpColumnStore $store): array
    {
        $findings = [];

        foreach ($input->getTables() as $config) {
            $key = $config->getFullTableName();
            foreach ($this->planned[$key] ?? [] as $column => $plan) {
                if (!$store->found($plan['path'], $column)) {
                    continue;
                }
                $rows = $store->rows($plan['path']);
                if ($rows === null || $rows === 0) {
                    // Пустой дамп — забота V-8, покрытие тут ни при чём.
                    continue;
                }
                $this->stats['columns_checked']++;

                $finding = $this->compare($config, (string) $column, $plan['sink'], $plan['profile']);
                if ($finding !== null) {
                    $this->stats['gaps']++;
                    $findings[] = $finding;
                }
            }
        }

        return $findings;
    }

    public function stats(): array
    {
        return $this->stats;
    }

    /**
     * @param array<string, mixed> $profile
     */
    private function isWorthChecking(array $profile): bool
    {
        if (isset($profile['codes']) && is_array($profile['codes']) && $profile['codes'] !== []) {
            return true;
        }
        $distinct = isset($profile['distinct_count']) ? (int) $profile['distinct_count'] : 0;

        return !empty($profile['categorical']) && $distinct >= 2 && $distinct <= self::MAX_CATEGORICAL;
    }

    /**
     * Есть ли значение в дампе — с поправкой на написание.
     *
     * `pg_stats` отдаёт булево как `t`/`f`, а в дампе стоит SQL-литерал `TRUE`/`FALSE`. При
     * прямом сравнении колонка, выгруженная целиком, выглядела «не покрытой»: оба кода из двух
     * отсутствуют. Цифры при этом не трогаем — у integer-колонки коды `1` и `0` это значения,
     * а не булево, и подменять их написанием нельзя.
     */
    private function present(CountingSetSink $sink, string $code): bool
    {
        foreach ($this->spellings($code) as $spelling) {
            if ($sink->has($spelling)) {
                return true;
            }
        }

        return false;
    }

    /** @return array<int, string> */
    private function spellings(string $code): array
    {
        $low = strtolower(trim($code));
        if ($low === 't' || $low === 'true') {
            return ['t', 'true', 'TRUE', 'True'];
        }
        if ($low === 'f' || $low === 'false') {
            return ['f', 'false', 'FALSE', 'False'];
        }

        return [$code];
    }

    /**
     * @param array<string, mixed> $profile
     */
    private function compare(TableConfig $config, string $column, CountingSetSink $sink, array $profile): ?Finding
    {
        $codes = isset($profile['codes']) && is_array($profile['codes']) ? array_map('strval', $profile['codes']) : [];
        $source = isset($profile['n_distinct_source']) ? (string) $profile['n_distinct_source'] : 'sample';

        if ($codes !== []) {
            $missing = [];
            foreach ($codes as $code) {
                if (!$this->present($sink, $code)) {
                    $missing[] = $code;
                }
            }
            if ($missing === []) {
                return null;
            }
            $complete = !empty($profile['codes_complete']);

            return Finding::warning(
                self::CODE_COVERAGE_GAP,
                sprintf(
                    'в дампе нет значений %s, которые есть в БД (%s): %d из %d кодов отсутствуют — эти виды данных выборка не захватила',
                    $column,
                    $source === 'pg_stats' ? ($complete ? 'по статистике планировщика, список полный' : 'по статистике планировщика, только частые значения') : 'по выборке при инвентаризации',
                    count($missing),
                    count($codes)
                ),
                $config->getSchema(),
                $config->getTable(),
                $column,
                false,
                [
                    'expected_codes' => $codes,
                    'missing_codes' => $missing,
                    'codes_complete' => $complete,
                    'codes_source' => $source,
                    'dump_distinct' => $sink->distinct(),
                    'dump_rows' => $sink->total(),
                ]
            );
        }

        $expected = (int) $profile['distinct_count'];
        if ($sink->isCapped() || $sink->distinct() >= $expected) {
            return null;
        }

        return Finding::warning(
            self::CODE_COVERAGE_GAP,
            sprintf(
                'в дампе %d из %d различных значений %s (%s) — часть видов данных выборка не захватила',
                $sink->distinct(),
                $expected,
                $column,
                $source === 'pg_stats' ? 'по статистике планировщика' : 'по выборке при инвентаризации, оценка снизу'
            ),
            $config->getSchema(),
            $config->getTable(),
            $column,
            false,
            [
                'expected_distinct' => $expected,
                'dump_distinct' => $sink->distinct(),
                'distinct_source' => $source,
                'dump_rows' => $sink->total(),
            ]
        );
    }
}
