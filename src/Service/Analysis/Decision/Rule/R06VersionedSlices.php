<?php

namespace Timbrs\DatabaseDumps\Service\Analysis\Decision\Rule;

use Timbrs\DatabaseDumps\Service\Analysis\Decision\Decision;
use Timbrs\DatabaseDumps\Service\Analysis\Decision\DecisionRuleInterface;

/**
 * R6: версионная таблица без разрезов по состояниям.
 *
 * У SCD2-таблицы три разных состояния строки: действующая версия, деактивированная и
 * историческая. Выборка «первые N по id» почти всегда приносит только историю — и приложение
 * на стенде показывает пустые карточки. Предлагаем по корзине на состояние.
 */
class R06VersionedSlices implements DecisionRuleInterface
{
    use RuleHelperTrait;

    /** Дата, которой в проекте помечают «версия действует по сей день». */
    private const OPEN_DATE = '2100-01-01';

    public function code(): string
    {
        return 'R6';
    }

    public function apply(array $table, array $context): array
    {
        if ($this->mode($table) !== 'partial_export') {
            return [];
        }
        $traits = $this->traits($table);
        if (empty($traits['scd2'])) {
            return [];
        }

        $sample = $this->config($table, 'sample');
        if (is_array($sample) && !empty($sample['criteria'])) {
            return [];
        }

        $hasFlag = !empty($traits['active_flag']) && $this->hasColumn($table, 'active_flg');
        $criteria = [
            [
                'name' => 'current',
                'where' => $hasFlag
                    ? sprintf("active_flg AND date_to >= '%s'", self::OPEN_DATE)
                    : sprintf("date_to >= '%s'", self::OPEN_DATE),
                'limit' => 100,
            ],
            [
                'name' => 'history',
                'where' => sprintf("date_to < '%s'", self::OPEN_DATE),
                'limit' => 100,
            ],
        ];
        if ($hasFlag) {
            $criteria[] = [
                'name' => 'deactivated',
                'where' => sprintf("NOT active_flg AND date_to >= '%s'", self::OPEN_DATE),
                'limit' => 100,
            ];
        }

        return [new Decision(
            $context['full_name'],
            null,
            Decision::KIND_CRITERIA,
            $sample,
            $criteria,
            $this->code(),
            'версионная таблица (date_from/date_to): без разрезов в дамп попадёт одно состояние из трёх — чаще всего история',
            [['source' => Decision::SOURCE_DB, 'note' => 'traits.scd2']],
            'med',
            false
        )];
    }
}
