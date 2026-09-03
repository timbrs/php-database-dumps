<?php

namespace Timbrs\DatabaseDumps\Service\Analysis\Decision\Rule;

use Timbrs\DatabaseDumps\Service\Analysis\Decision\Decision;
use Timbrs\DatabaseDumps\Service\Analysis\Decision\DecisionRuleInterface;

/**
 * R7: таблица есть в конфиге, а в базе её нет.
 *
 * Такая запись живёт до первого экспорта, где она молча ничего не выгружает, — и до первого
 * человека, который потратит полчаса на вопрос «почему файла нет». Удаление механическое:
 * состава выборки оно не меняет.
 */
class R07DropPhantomTables implements DecisionRuleInterface
{
    use RuleHelperTrait;

    public function code(): string
    {
        return 'R7';
    }

    public function apply(array $table, array $context): array
    {
        // Досье строится по слепку: фантомы приходят отдельным списком из движка.
        if (empty($table['phantom'])) {
            return [];
        }

        return [new Decision(
            $context['full_name'],
            null,
            Decision::KIND_REMOVE_TABLE,
            $this->mode($table),
            null,
            $this->code(),
            'таблица настроена в конфиге, но её нет в слепке схемы — запись мёртвая',
            [['source' => Decision::SOURCE_DB, 'note' => 'нет в schema_inventory']],
            'high',
            true
        )];
    }
}
