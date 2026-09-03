<?php

namespace Timbrs\DatabaseDumps\Service\Analysis\Decision\Rule;

use Timbrs\DatabaseDumps\Service\Analysis\Decision\Decision;
use Timbrs\DatabaseDumps\Service\Analysis\Decision\DecisionRuleInterface;

/**
 * R9: enum кода и значения БД разошлись.
 *
 * Именно так теряются виды данных: в конфиге корзины на пять статусов из перечисления, а
 * шестой (`OVERDUE_CLOSED = -4`) в него не попал — и просроченных закрытых задач в дампе нет.
 * Обратный случай не менее важен: в БД есть значение, которого нет в enum'е, — либо enum
 * устарел, либо в таблице мусор.
 *
 * В отчёт уходят имена case'ов и коды, прошедшие PII-шлюз, — не значения данных.
 */
class R09EnumVersusDatabase implements DecisionRuleInterface
{
    use RuleHelperTrait;

    public function code(): string
    {
        return 'R9';
    }

    public function apply(array $table, array $context): array
    {
        if ($this->mode($table) === 'not_exported') {
            return [];
        }

        $decisions = [];
        foreach ($this->columns($table) as $column => $meta) {
            $enum = isset($meta['enum']) && is_array($meta['enum']) ? $meta['enum'] : null;
            if ($enum === null || empty($enum['cases'])) {
                continue;
            }
            $profile = isset($meta['profile']) && is_array($meta['profile']) ? $meta['profile'] : [];
            $codes = isset($profile['codes']) && is_array($profile['codes']) ? array_map('strval', $profile['codes']) : [];
            if ($codes === []) {
                continue;
            }

            $cases = $enum['cases'];
            $missingInDb = [];
            foreach ($cases as $caseName => $value) {
                if (!in_array((string) $value, $codes, true)) {
                    $missingInDb[] = $caseName;
                }
            }
            $missingInEnum = array_values(array_diff($codes, array_map('strval', array_values($cases))));

            if ($missingInDb === [] && $missingInEnum === []) {
                continue;
            }

            $complete = !empty($profile['codes_complete']);
            $parts = [];
            if ($missingInDb !== []) {
                $parts[] = sprintf(
                    'в БД нет значений case%s %s%s',
                    count($missingInDb) > 1 ? 'ов' : 'а',
                    implode(', ', $missingInDb),
                    $complete ? '' : ' (статистика показывает только частые значения)'
                );
            }
            if ($missingInEnum !== []) {
                $parts[] = sprintf('в БД есть %d значений, которых нет в enum', count($missingInEnum));
            }

            $decisions[] = new Decision(
                $context['full_name'],
                (string) $column,
                Decision::KIND_CRITERIA,
                $this->config($table, 'sample'),
                null,
                $this->code(),
                sprintf('%s: %s — проверьте, все ли виды попадают в выборку', $enum['class'], implode('; ', $parts)),
                [
                    ['source' => Decision::SOURCE_ORM, 'ref' => $enum['class'], 'note' => 'cases: ' . implode(', ', array_keys($cases))],
                    ['source' => Decision::SOURCE_DB, 'note' => 'codes: ' . implode(', ', $codes)],
                ],
                $complete ? 'high' : 'med',
                false
            );
        }

        return $decisions;
    }
}
