<?php

namespace Timbrs\DatabaseDumps\Service\Analysis\Decision\Rule;

use Timbrs\DatabaseDumps\Service\Analysis\Decision\Decision;
use Timbrs\DatabaseDumps\Service\Analysis\Decision\DecisionRuleInterface;

/**
 * R3: «сто красных, сто зелёных» — категориальная колонка без покрытия.
 *
 * Колонка со статусом, типом или флагом задаёт вид данных. Если её не разрезать, в дамп
 * попадут те строки, что оказались первыми по порядку, и половина видов исчезнет. Для
 * EAV-таблиц разрез двухуровневый: `attr_id`, а внутри — значение атрибута; для «цвета
 * клиента», который лежит в дочерней таблице, — обратная стратификация через неё.
 */
class R03CoverCategoricalColumns implements DecisionRuleInterface
{
    use RuleHelperTrait;

    /** Больше стольких значений — это уже не разрез, а полная таблица (за это отвечает R8). */
    private const MAX_DISTINCT = 50;

    public function code(): string
    {
        return 'R3';
    }

    public function apply(array $table, array $context): array
    {
        if ($this->mode($table) !== 'partial_export') {
            return [];
        }

        $traits = $this->traits($table);
        $name = $context['full_name'];
        $columns = $this->columns($table);

        // EAV: разрез по attr_id, а внутри — по значению атрибута. Без второго уровня
        // выборка возьмёт один и тот же атрибут для всех строк.
        if (isset($traits['eav']['role']) && $traits['eav']['role'] === 'values' && isset($columns['attr_id'])) {
            $valueColumn = null;
            foreach (['value_int', 'value_string', 'value_num', 'value_date'] as $candidate) {
                if (isset($columns[$candidate])) {
                    $valueColumn = $candidate;
                    break;
                }
            }
            $spec = ['column' => 'attr_id'];
            if ($valueColumn !== null) {
                $spec['then'] = ['column' => $valueColumn];
            }
            if ($this->coveredBy($columns, 'attr_id') === null) {
                return [new Decision(
                    $name,
                    'attr_id',
                    Decision::KIND_STRATIFY,
                    $this->config($table, 'sample'),
                    [$spec],
                    $this->code(),
                    'EAV-таблица: корзина на каждый атрибут, внутри — на его значения; иначе в дампе окажется один атрибут из десятка',
                    [['source' => Decision::SOURCE_DB, 'note' => 'traits.eav=values']],
                    'med',
                    false
                )];
            }
        }

        $decisions = [];
        foreach ($columns as $column => $meta) {
            if ($this->coveredBy($columns, (string) $column) !== null) {
                continue;
            }
            $profile = isset($meta['profile']) && is_array($meta['profile']) ? $meta['profile'] : [];
            $distinct = isset($profile['distinct_count']) ? (int) $profile['distinct_count'] : 0;
            $hasEnum = isset($meta['enum']['class']);
            $categorical = !empty($profile['categorical']) || $hasEnum;
            if (!$categorical || $distinct < 2 || $distinct > self::MAX_DISTINCT) {
                continue;
            }

            $evidence = [['source' => Decision::SOURCE_DB, 'note' => 'distinct=' . $distinct]];
            if ($hasEnum) {
                $evidence[] = [
                    'source' => Decision::SOURCE_ORM,
                    'ref' => $meta['enum']['class'],
                    'note' => 'enum привязан мостом ' . (isset($meta['enum']['bridge']) ? $meta['enum']['bridge'] : '?'),
                ];
            }

            $decisions[] = new Decision(
                $name,
                (string) $column,
                Decision::KIND_STRATIFY,
                null,
                [['column' => (string) $column]],
                $this->code(),
                sprintf('колонка задаёт вид данных (%d значений), но в выборке не разрезана — часть видов в дамп не попадёт', $distinct),
                $evidence,
                $hasEnum ? 'high' : 'med',
                false
            );
        }

        return $decisions;
    }

    /**
     * @param array<string, array<string, mixed>> $columns
     * @return string|null
     */
    private function coveredBy(array $columns, string $column)
    {
        return isset($columns[$column]['coverage']['covered_by']) ? $columns[$column]['coverage']['covered_by'] : null;
    }
}
