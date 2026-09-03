<?php

namespace Timbrs\DatabaseDumps\Service\Analysis\Decision\Rule;

use Timbrs\DatabaseDumps\Service\Analysis\Decision\Decision;
use Timbrs\DatabaseDumps\Service\Analysis\Decision\DecisionEngine;
use Timbrs\DatabaseDumps\Service\Analysis\Decision\DecisionRuleInterface;

/**
 * R1: полная выгрузка большой таблицы.
 *
 * Гигабайтная таблица в дампе не нужна никому: стенд поднимается дольше, чем живёт задача.
 * Переводим в частичную и сразу предлагаем разрезы — иначе получится «первые N строк по id»,
 * то есть один вид данных вместо всех.
 */
class R01SplitHugeFullExport implements DecisionRuleInterface
{
    use RuleHelperTrait;

    public function code(): string
    {
        return 'R1';
    }

    public function apply(array $table, array $context): array
    {
        if ($this->mode($table) !== 'full_export') {
            return [];
        }
        $rows = $this->rows($table);
        $threshold = (int) $this->setting($context, 'full_export_threshold', DecisionEngine::DEFAULT_FULL_EXPORT_THRESHOLD);
        if ($rows === null || $rows <= $threshold) {
            return [];
        }

        $name = $context['full_name'];
        $why = sprintf(
            'полная выгрузка %s строк%s при пороге %s — дамп раздувается, а видов данных в нём не прибавляется',
            number_format($rows, 0, '.', ' '),
            $this->rowsEstimated($table) ? ' (оценка планировщика)' : '',
            number_format($threshold, 0, '.', ' ')
        );
        $evidence = [['source' => Decision::SOURCE_DB, 'note' => 'row_count=' . $rows]];

        $decisions = [
            new Decision($name, null, Decision::KIND_MODE, 'full_export', 'partial_export', $this->code(), $why, $evidence, 'high', false),
            new Decision($name, null, Decision::KIND_LIMIT, null, $threshold, $this->code(), 'потолок объединённой выборки', $evidence, 'med', false),
        ];

        // Разрезы по категориальным колонкам — иначе частичная выгрузка сведётся к плоскому срезу.
        $stratify = [];
        foreach ($this->columns($table) as $column => $meta) {
            $profile = isset($meta['profile']) && is_array($meta['profile']) ? $meta['profile'] : [];
            if (empty($profile['categorical'])) {
                continue;
            }
            $distinct = isset($profile['distinct_count']) ? (int) $profile['distinct_count'] : 0;
            if ($distinct >= 2 && $distinct <= 50) {
                $stratify[] = ['column' => (string) $column];
            }
        }
        if ($stratify !== []) {
            $decisions[] = new Decision(
                $name,
                null,
                Decision::KIND_STRATIFY,
                null,
                $stratify,
                $this->code(),
                'корзина на каждое значение категориальных колонок — так в дампе окажутся все виды, а не первые N строк',
                $evidence,
                'med',
                false
            );
        }

        $order = $this->config($table, 'order_by');
        if ($order === null && $this->hasColumn($table, 'id')) {
            $decisions[] = new Decision($name, null, Decision::KIND_ORDER_BY, null, 'id DESC', $this->code(), 'детерминированный порядок частичной выборки', $evidence, 'med', false);
        }

        return $decisions;
    }
}
