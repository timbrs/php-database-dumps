<?php

namespace Timbrs\DatabaseDumps\Service\Analysis\Decision\Rule;

use Timbrs\DatabaseDumps\Service\Analysis\Decision\Decision;
use Timbrs\DatabaseDumps\Service\Analysis\Decision\DecisionRuleInterface;
use Timbrs\DatabaseDumps\Service\Faker\PatternDetector;

/**
 * R4: колонка с персональными данными без faker.
 *
 * Единственное правило, которое можно применять без человека: замена ПД ничего не ломает и
 * не меняет состав выборки, а её отсутствие делает дамп непередаваемым. Паттерн выбирается
 * по имени колонки и по типу — ИНН в числовой колонке остаётся числом, дата рождения датой.
 */
class R04MaskPersonalData implements DecisionRuleInterface
{
    use RuleHelperTrait;

    /**
     * Имя колонки → паттерн. Порядок важен: более узкие шаблоны идут первыми.
     *
     * @var array<string, string>
     */
    private static $BY_NAME = [
        '/(^|_)inn($|_)|\binn\b/i' => PatternDetector::PATTERN_INN,
        '/ogrn/i' => PatternDetector::PATTERN_OGRN,
        '/kpp|okpo|okato|oktmo|bik|snils/i' => PatternDetector::PATTERN_DIGITS,
        '/(birth|born)_?(date|day)?|дата_?рожд/iu' => PatternDetector::PATTERN_BIRTH_DATE,
        '/doc(ument)?_?(num|number|no)|passport_?num/i' => PatternDetector::PATTERN_DOC_NUMBER,
        '/doc(ument)?_?ser|passport_?ser/i' => PatternDetector::PATTERN_DOC_SERIES,
        '/email|e_?mail|почта/iu' => PatternDetector::PATTERN_EMAIL,
        '/phone|mobile|телефон/iu' => PatternDetector::PATTERN_PHONE,
        '/patronym|middle_?name|отчество/iu' => PatternDetector::PATTERN_PATRONYMIC,
        '/last_?name|surname|фамилия/iu' => PatternDetector::PATTERN_LASTNAME,
        '/first_?name|fname|имя/iu' => PatternDetector::PATTERN_FIRSTNAME,
        '/(^|_)fio($|_)|full_?name|фио/iu' => PatternDetector::PATTERN_FIO,
    ];

    public function code(): string
    {
        return 'R4';
    }

    public function apply(array $table, array $context): array
    {
        if ($this->mode($table) === 'not_exported') {
            return [];
        }

        $decisions = [];
        foreach ($this->columns($table) as $column => $meta) {
            if (!empty($meta['pii']['faker'])) {
                continue;
            }
            $pattern = $this->patternFor((string) $column);
            if ($pattern === null) {
                continue;
            }
            $type = isset($meta['type']) ? (string) $meta['type'] : '';
            $pattern = $this->adjustForType($pattern, $type);
            if ($pattern === null) {
                continue;
            }

            $decisions[] = new Decision(
                $context['full_name'],
                (string) $column,
                Decision::KIND_FAKER,
                null,
                $pattern,
                $this->code(),
                sprintf('колонка «%s» хранит персональные данные, а замены нет — такой дамп нельзя отдавать', $column),
                [['source' => Decision::SOURCE_DB, 'note' => 'type=' . $type]],
                'high',
                // Единственное авто-решение: замена ПД не меняет состав выборки.
                true
            );
        }

        return $decisions;
    }

    private function patternFor(string $column): ?string
    {
        foreach (self::$BY_NAME as $regex => $pattern) {
            if (preg_match($regex, $column) === 1) {
                return $pattern;
            }
        }

        return null;
    }

    /**
     * Паттерн против типа колонки: текстовые ПД в числовой колонке не маскируем (F-1 —
     * решение человека), а цифровые и даты подходят своим типам.
     */
    private function adjustForType(string $pattern, string $type): ?string
    {
        $normalized = strtolower((string) preg_replace('/\s*\(.*$/', '', trim($type)));
        $isNumeric = in_array($normalized, ['smallint', 'integer', 'bigint', 'int', 'int2', 'int4', 'int8', 'numeric', 'decimal', 'number'], true);
        $isDate = strpos($normalized, 'date') !== false || strpos($normalized, 'timestamp') !== false;

        if ($pattern === PatternDetector::PATTERN_BIRTH_DATE) {
            return $isDate || $normalized === '' || !$isNumeric ? $pattern : null;
        }
        if (in_array($pattern, PatternDetector::VALUE_SEEDED_PATTERNS, true)) {
            return $pattern;
        }
        if ($isNumeric || $isDate) {
            // ФИО или телефон в числовой колонке — случай, где ошибиться дороже, чем промолчать.
            return null;
        }

        return $pattern;
    }
}
