<?php

namespace Timbrs\DatabaseDumps\Service\Analysis\Decision\Rule;

use Timbrs\DatabaseDumps\Service\Analysis\Decision\Decision;
use Timbrs\DatabaseDumps\Service\Analysis\Decision\DecisionRuleInterface;
use Timbrs\DatabaseDumps\Service\Faker\PatternDetector;

/**
 * R11: faker-паттерн не по адресу.
 *
 * Пока в наборе были только ФИО, телефон и почта, `phone` служил затычкой для всего: ИНН,
 * КПП, номеров документов и дат. В дампе от этого ИНН переставал быть ИНН, а дата рождения —
 * датой. Теперь у каждого из этих случаев свой паттерн, и такие маппинги надо переставить.
 */
class R11FixFakerPattern implements DecisionRuleInterface
{
    use RuleHelperTrait;

    public function code(): string
    {
        return 'R11';
    }

    public function apply(array $table, array $context): array
    {
        $decisions = [];
        foreach ($this->columns($table) as $column => $meta) {
            $current = isset($meta['pii']['faker']) ? (string) $meta['pii']['faker'] : '';
            if ($current === '' || in_array($current, PatternDetector::VALUE_SEEDED_PATTERNS, true)) {
                continue;
            }
            $better = $this->better((string) $column, isset($meta['type']) ? (string) $meta['type'] : '');
            if ($better === null || $better === $current) {
                continue;
            }

            $decisions[] = new Decision(
                $context['full_name'],
                (string) $column,
                Decision::KIND_FAKER,
                $current,
                $better,
                $this->code(),
                sprintf(
                    'паттерн «%s» на колонке «%s» портит форму значения; «%s» сохраняет длину, формат и контрольные разряды',
                    $current,
                    $column,
                    $better
                ),
                [['source' => Decision::SOURCE_DB, 'note' => 'type=' . (isset($meta['type']) ? $meta['type'] : '?')]],
                'high',
                true,
                // Перезапись обязательна: правило только и делает, что исправляет уже стоящее
                // значение — без неё апплаер отбивает каждое решение как «уже занято», и правило
                // остаётся бесполезным. Затирается ровно тот паттерн, который правило видело:
                // если конфиг с тех пор правили руками, решение уйдёт в stale, а не в запись.
                true
            );
        }

        return $decisions;
    }

    private function better(string $column, string $type): ?string
    {
        if (preg_match('/(^|_)inn($|_)|\binn\b/i', $column) === 1) {
            return PatternDetector::PATTERN_INN;
        }
        if (preg_match('/ogrn/i', $column) === 1) {
            return PatternDetector::PATTERN_OGRN;
        }
        if (preg_match('/kpp|okpo|okato|oktmo|bik|snils/i', $column) === 1) {
            return PatternDetector::PATTERN_DIGITS;
        }
        if (preg_match('/(birth|born)|дата_?рожд/iu', $column) === 1) {
            return PatternDetector::PATTERN_BIRTH_DATE;
        }
        if (preg_match('/doc(ument)?_?(num|number|no)|passport_?num/i', $column) === 1) {
            return PatternDetector::PATTERN_DOC_NUMBER;
        }
        if (preg_match('/doc(ument)?_?ser|passport_?ser/i', $column) === 1) {
            return PatternDetector::PATTERN_DOC_SERIES;
        }

        // Дата под текстовым паттерном: INSERT дампа на ней упадёт.
        $normalized = strtolower((string) preg_replace('/\s*\(.*$/', '', trim($type)));
        if (strpos($normalized, 'date') !== false || strpos($normalized, 'timestamp') !== false) {
            return PatternDetector::PATTERN_BIRTH_DATE;
        }

        return null;
    }
}
