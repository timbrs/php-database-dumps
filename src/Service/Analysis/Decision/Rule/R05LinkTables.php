<?php

namespace Timbrs\DatabaseDumps\Service\Analysis\Decision\Rule;

use Timbrs\DatabaseDumps\Service\Analysis\Decision\Decision;
use Timbrs\DatabaseDumps\Service\Analysis\Decision\DecisionRuleInterface;

/**
 * R5: связь есть, а в конфиге её нет.
 *
 * Без `cascade_from` ребёнок набирается сам по себе, и в дампе оказываются строки, чьих
 * родителей там нет. Внешний ключ в БД — доказательство, которое не нуждается в обсуждении
 * (и импорт его проверит), поэтому такая связь добавляется автоматически. Связь, найденная
 * только в коде, уходит на подтверждение: имена там часто совпадают случайно.
 */
class R05LinkTables implements DecisionRuleInterface
{
    use RuleHelperTrait;

    public function code(): string
    {
        return 'R5';
    }

    public function apply(array $table, array $context): array
    {
        if ($this->mode($table) !== 'partial_export') {
            return [];
        }

        $existing = [];
        foreach ((array) $this->config($table, 'cascade_from', []) as $entry) {
            if (is_array($entry) && isset($entry['parent'], $entry['fk_column'])) {
                $existing[$entry['parent'] . '|' . $entry['fk_column']] = true;
            }
        }

        $decisions = [];
        $seen = [];
        foreach (isset($table['edges']) && is_array($table['edges']) ? $table['edges'] : [] as $edge) {
            if (!is_array($edge) || ($edge['dir'] ?? '') !== 'out' || empty($edge['table']) || empty($edge['column'])) {
                continue;
            }
            $key = $edge['table'] . '|' . $edge['column'];
            if (isset($existing[$key]) || isset($seen[$key])) {
                continue;
            }
            $source = isset($edge['source']) ? (string) $edge['source'] : 'config';
            if ($source === 'config') {
                continue;
            }
            $seen[$key] = true;

            $proposed = [
                'parent' => $edge['table'],
                'fk_column' => $edge['column'],
                'parent_column' => isset($edge['parent_column']) && $edge['parent_column'] !== null ? $edge['parent_column'] : 'id',
            ];
            $isDbFk = !empty($edge['in_db_fk']) || $source === 'db_fk';
            $evidence = [['source' => $isDbFk ? Decision::SOURCE_DB : Decision::SOURCE_ORM, 'note' => 'edge ' . $source]];
            if (isset($edge['evidence']['file'])) {
                $evidence[] = [
                    'source' => Decision::SOURCE_ORM,
                    'file' => $edge['evidence']['file'],
                    'line' => isset($edge['evidence']['line']) ? $edge['evidence']['line'] : null,
                ];
            }

            $decisions[] = new Decision(
                $context['full_name'],
                (string) $edge['column'],
                Decision::KIND_CASCADE_FROM,
                null,
                $proposed,
                $this->code(),
                $isDbFk
                    ? sprintf('внешний ключ на %s не отражён в cascade_from — импорт дампа с этим ключом не пройдёт', $edge['table'])
                    : sprintf('связь с %s найдена в коде, но выборка её не учитывает — в дампе будут строки без родителя', $edge['table']),
                $evidence,
                $isDbFk ? 'high' : 'med',
                $isDbFk
            );
        }

        return $decisions;
    }
}
