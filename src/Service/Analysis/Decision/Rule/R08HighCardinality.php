<?php

namespace Timbrs\DatabaseDumps\Service\Analysis\Decision\Rule;

use Timbrs\DatabaseDumps\Service\Analysis\Decision\Decision;
use Timbrs\DatabaseDumps\Service\Analysis\Decision\DecisionRuleInterface;

/**
 * R8: колонка похожа на разрез, но значений слишком много.
 *
 * Пятьсот значений — это не корзины, а вторая копия таблицы. Стратификация тут не годится,
 * нужны осмысленные группы («крупные/средние/мелкие», «этот год/прошлый»), а их придумывает
 * человек или агент по коду: в самой БД такого знания нет.
 */
class R08HighCardinality implements DecisionRuleInterface
{
    use RuleHelperTrait;

    /** Потолок корзин дампера. */
    private const MAX_BUCKETS = 50;

    /** Выше этого — уже не разрез, а идентификатор. */
    private const IDENTIFIER_LIKE = 5000;

    public function code(): string
    {
        return 'R8';
    }

    public function apply(array $table, array $context): array
    {
        if ($this->mode($table) !== 'partial_export') {
            return [];
        }

        $decisions = [];
        foreach ($this->columns($table) as $column => $meta) {
            $profile = isset($meta['profile']) && is_array($meta['profile']) ? $meta['profile'] : [];
            $distinct = isset($profile['distinct_count']) ? (int) $profile['distinct_count'] : 0;
            $hasEnum = isset($meta['enum']['class']);
            if ($distinct <= self::MAX_BUCKETS || $distinct >= self::IDENTIFIER_LIKE) {
                continue;
            }
            // Интересны только колонки, которые кто-то фильтрует, либо привязанные к enum:
            // остальные — просто данные.
            $usages = isset($meta['usages']) && is_array($meta['usages']) ? $meta['usages'] : [];
            if (!$hasEnum && !in_array('filter', $usages, true)) {
                continue;
            }

            $decisions[] = new Decision(
                $context['full_name'],
                (string) $column,
                Decision::KIND_CRITERIA,
                $this->config($table, 'sample'),
                null,
                $this->code(),
                sprintf(
                    'по колонке фильтруют, но у неё %d значений — стратификация упрётся в потолок %d корзин; нужны критерии по осмысленным группам',
                    $distinct,
                    self::MAX_BUCKETS
                ),
                [['source' => Decision::SOURCE_DB, 'note' => 'distinct=' . $distinct]],
                'low',
                false
            );
        }

        return $decisions;
    }
}
