<?php

namespace Timbrs\DatabaseDumps\Service\Analysis\Decision\Rule;

use Timbrs\DatabaseDumps\Config\TableConfig;
use Timbrs\DatabaseDumps\Service\Analysis\Decision\Decision;
use Timbrs\DatabaseDumps\Service\Analysis\Decision\DecisionRuleInterface;

/**
 * R10: квоты корзин не помещаются в общий limit.
 *
 * Дампер сольёт корзины по кругу и каждой отдаст лишь часть строк — виды сохранятся, но
 * обещанные «сто зелёных» станут пятнадцатью. Честнее выбрать явно: снизить квоту (тогда
 * дамп остаётся прежнего размера) или поднять limit (тогда растёт объём). По умолчанию
 * предлагаем первое — покрытие важнее объёма.
 */
class R10QuotaFitsLimit implements DecisionRuleInterface
{
    use RuleHelperTrait;

    public function code(): string
    {
        return 'R10';
    }

    public function apply(array $table, array $context): array
    {
        $sample = $this->config($table, 'sample');
        $limit = $this->config($table, 'limit');
        if (!is_array($sample) || $limit === null || (int) $limit <= 0) {
            return [];
        }
        $limit = (int) $limit;

        $quota = 0;
        foreach (isset($sample[TableConfig::SAMPLE_KEY_CRITERIA]) && is_array($sample[TableConfig::SAMPLE_KEY_CRITERIA]) ? $sample[TableConfig::SAMPLE_KEY_CRITERIA] : [] as $criterion) {
            if (is_array($criterion) && isset($criterion[TableConfig::CRITERION_KEY_LIMIT])) {
                $quota += (int) $criterion[TableConfig::CRITERION_KEY_LIMIT];
            }
        }

        $perValue = isset($sample[TableConfig::SAMPLE_KEY_PER_VALUE])
            ? (int) $sample[TableConfig::SAMPLE_KEY_PER_VALUE]
            : TableConfig::DEFAULT_PER_VALUE;
        $buckets = 0;
        foreach (TableConfig::stratifyColumns($sample) as $column) {
            $profile = isset($table['columns'][$column]['profile']) && is_array($table['columns'][$column]['profile'])
                ? $table['columns'][$column]['profile']
                : [];
            $distinct = isset($profile['distinct_count']) ? (int) $profile['distinct_count'] : 0;
            $buckets += $distinct > 0 ? min($distinct, 50) : 50;
        }
        $quota += $buckets * $perValue;

        if ($quota <= $limit) {
            return [];
        }

        $proposed = $buckets > 0 ? max(5, intdiv($limit, max(1, $buckets))) : $perValue;

        return [new Decision(
            $context['full_name'],
            null,
            Decision::KIND_PER_VALUE,
            $perValue,
            $proposed,
            $this->code(),
            sprintf(
                'квоты обещают %d строк при limit %d: корзины сольются round-robin и каждая отдаст лишь часть — снизить квоту корзины до %d (или поднять limit)',
                $quota,
                $limit,
                $proposed
            ),
            [['source' => Decision::SOURCE_DB, 'note' => 'корзин ' . $buckets]],
            'med',
            false
        )];
    }
}
