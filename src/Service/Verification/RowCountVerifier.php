<?php

namespace Timbrs\DatabaseDumps\Service\Verification;

use Timbrs\DatabaseDumps\Config\TableConfig;
use Timbrs\DatabaseDumps\Service\Validation\Finding;

/**
 * V-2 / V-8: файлы на месте и в них столько строк, сколько обещал конфиг.
 *
 * Полная выгрузка должна совпасть со слепком (с допуском, если размер там — оценка),
 * частичная — не превысить limit (иначе ограничение не применилось), а пустой файл при
 * непустой таблице — знак, что критерии или каскад отфильтровали всё.
 */
class RowCountVerifier implements DumpVerifierInterface
{
    /** Таблица есть в конфиге, файла дампа нет. */
    public const CODE_NO_DUMP = 'V-2';

    /** Число строк в дампе расходится с ожиданием. */
    public const CODE_ROW_COUNT = 'V-8';

    /** Допуск для оценённого размера: доля и минимум строк. */
    private const ESTIMATE_TOLERANCE = 0.1;
    private const ESTIMATE_TOLERANCE_MIN = 10;

    /** @var array<string, int> */
    private $stats = ['tables' => 0, 'rows_total' => 0, 'missing' => 0];

    public function plan(DumpVerificationInput $input, DumpColumnStore $store): void
    {
        $this->stats = ['tables' => 0, 'rows_total' => 0, 'missing' => 0];
        foreach ($input->getTables() as $config) {
            $store->requestRows($input->pathFor($config));
        }
    }

    public function check(DumpVerificationInput $input, DumpColumnStore $store): array
    {
        $findings = [];
        $inventory = $input->getInventory();

        foreach ($input->getTables() as $config) {
            $path = $input->pathFor($config);
            $schema = $config->getSchema();
            $table = $config->getTable();

            if ($store->isMissing($path)) {
                $this->stats['missing']++;
                $findings[] = Finding::warning(
                    self::CODE_NO_DUMP,
                    sprintf('%s есть в конфиге, но файла дампа нет — таблица не выгружалась', $config->getFullTableName()),
                    $schema,
                    $table
                );
                continue;
            }

            $rows = $store->rows($path);
            if ($rows === null) {
                continue;
            }
            $this->stats['tables']++;
            $this->stats['rows_total'] += $rows;

            $inDb = $inventory !== null ? $inventory->rowCount($schema, $table) : null;
            $estimated = $inventory !== null && $inventory->rowCountEstimated($schema, $table);

            if ($config->isFullExport()) {
                if ($inDb === null) {
                    continue;
                }
                $tolerance = $estimated ? max(self::ESTIMATE_TOLERANCE_MIN, (int) ceil($inDb * self::ESTIMATE_TOLERANCE)) : 0;
                if (abs($rows - $inDb) > $tolerance) {
                    $findings[] = Finding::warning(
                        self::CODE_ROW_COUNT,
                        sprintf(
                            'full_export: в дампе %d строк, в слепке %d%s — таблица изменилась после слепка или выгрузка неполная',
                            $rows,
                            $inDb,
                            $estimated ? ' (оценка планировщика)' : ''
                        ),
                        $schema,
                        $table,
                        null,
                        false,
                        ['dump_rows' => $rows, 'inventory_rows' => $inDb, 'inventory_estimated' => $estimated]
                    );
                }
                continue;
            }

            $limit = $config->getLimit();
            $expectedMax = $this->expectedMax($config);
            if ($expectedMax !== null && $rows > $expectedMax) {
                $findings[] = Finding::error(
                    self::CODE_ROW_COUNT,
                    sprintf(
                        'partial_export: в дампе %d строк при потолке %d (%s) — ограничение выборки не применилось',
                        $rows,
                        $expectedMax,
                        $config->hasSample() ? 'сумма квот sample' : 'limit'
                    ),
                    $schema,
                    $table,
                    null,
                    false,
                    ['dump_rows' => $rows, 'expected_max' => $expectedMax, 'limit' => $limit]
                );
                continue;
            }

            if ($rows === 0 && $inDb !== null && $inDb > 0) {
                $findings[] = Finding::warning(
                    self::CODE_ROW_COUNT,
                    sprintf(
                        'дамп пуст, а в БД %d строк%s — where, критерии или каскад отфильтровали всё',
                        $inDb,
                        $estimated ? ' (оценка)' : ''
                    ),
                    $schema,
                    $table,
                    null,
                    false,
                    ['dump_rows' => 0, 'inventory_rows' => $inDb, 'inventory_estimated' => $estimated]
                );
            }
        }

        return $findings;
    }

    public function stats(): array
    {
        return $this->stats;
    }

    /**
     * Потолок строк частичной выгрузки: limit, а при sample — сумма квот критериев
     * (стратификация квоту наперёд не знает → потолка нет).
     */
    private function expectedMax(TableConfig $config): ?int
    {
        $limit = $config->getLimit();
        if (!$config->hasSample()) {
            return $limit;
        }
        $sample = $config->getSample() ?? [];
        if (isset($sample[TableConfig::SAMPLE_KEY_STRATIFY_BY])
            || isset($sample[TableConfig::SAMPLE_KEY_STRATIFY])
            || isset($sample[TableConfig::SAMPLE_KEY_STRATIFY_VIA])
        ) {
            return null;
        }
        $sum = 0;
        foreach ($sample[TableConfig::SAMPLE_KEY_CRITERIA] ?? [] as $criterion) {
            if (!is_array($criterion) || !isset($criterion[TableConfig::CRITERION_KEY_LIMIT])) {
                return null;
            }
            $sum += (int) $criterion[TableConfig::CRITERION_KEY_LIMIT];
        }
        if ($sum === 0) {
            return $limit;
        }

        return $limit !== null ? max($limit, $sum) : $sum;
    }
}
