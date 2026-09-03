<?php

namespace Timbrs\DatabaseDumps\Service\Validation\Rule;

use Timbrs\DatabaseDumps\Service\Faker\PatternDetector;
use Timbrs\DatabaseDumps\Service\Validation\AuditContext;
use Timbrs\DatabaseDumps\Service\Validation\Finding;

/**
 * Faker против типа колонки — тот класс багов, из-за которого импорт дампа падал целиком.
 *
 * RussianFaker всегда отдаёт СТРОКУ (generatePhone для нетелефонной длины возвращает
 * '7' + 10 цифр), поэтому маппинг на дату, timestamp, bytea, uuid, json или boolean
 * гарантированно ломает INSERT.
 *
 *  F-1 — паттерн на нетекстовой колонке. Для типов, куда строка заведомо не вставится, это
 *        error и правится автоматически. Для числовых типов — warning без автоправки:
 *        значение вставится, но подменит идентификатор; при этом бывает и наоборот —
 *        телефон, хранящийся в bigint, маскировать НУЖНО, и снимать маппинг нельзя без
 *        решения человека.
 *  F-2 — паттерн вне PatternDetector::ALLOWED_PATTERNS: FakerConfig такой конфиг вообще
 *        не примет, экспорт упадёт на загрузке.
 */
class FakerTypeRule implements RuleInterface
{
    /**
     * Текстовые типы, для которых маскирование безопасно (сравнение по нормализованному
     * имени типа из слепка).
     *
     * @var array<int, string>
     */
    private const TEXT_TYPES = [
        'character varying', 'character', 'varchar', 'char', 'bpchar', 'text', 'name', 'citext',
        'nvarchar', 'nvarchar2', 'varchar2', 'clob', 'nclob', 'longtext', 'mediumtext', 'tinytext',
        'string',
    ];

    /**
     * Числовые типы: строка от фейкера вставится (СУБД приведёт), но значение станет мусором.
     *
     * @var array<int, string>
     */
    private const NUMERIC_TYPES = [
        'smallint', 'integer', 'bigint', 'int', 'int2', 'int4', 'int8', 'tinyint', 'mediumint',
        'numeric', 'decimal', 'real', 'double precision', 'double', 'float', 'float4', 'float8',
        'money', 'number',
    ];

    public function name(): string
    {
        return 'faker';
    }

    public function apply(AuditContext $context): array
    {
        $findings = [];
        $inventory = $context->inventory();
        $allowed = PatternDetector::ALLOWED_PATTERNS;

        foreach ($context->scopedSchemas() as $schema) {
            foreach ($context->config()->getFaker($schema) as $table => $map) {
                $table = (string) $table;
                foreach ($map as $column => $pattern) {
                    $column = (string) $column;

                    if (!in_array($pattern, $allowed, true)) {
                        $findings[] = Finding::error(
                            'F-2',
                            sprintf(
                                'неизвестный паттерн faker «%s» — FakerConfig отвергнет конфиг целиком '
                                . '(допустимы: %s)',
                                $pattern,
                                implode(', ', $allowed)
                            ),
                            $schema,
                            $table,
                            $column,
                            true,
                            [
                                'fix' => 'remove_faker_column',
                                'table' => $table,
                                'column' => $column,
                                'pattern' => $pattern,
                                'hint' => 'убрать маппинг или заменить паттерн допустимым',
                            ]
                        );
                        continue;
                    }

                    $type = $inventory->columnType($schema, $table, $column);
                    if ($type === null || $type === '') {
                        // Колонки нет в слепке (об этом скажет L-6) или тип неизвестен — судить не о чем.
                        continue;
                    }

                    $class = $this->classify($type);
                    if ($class === 'text') {
                        continue;
                    }

                    // Идентификаторы и даты пишутся не только в текст: ИНН и КПП живут в
                    // числовых колонках, дата рождения — в date/timestamp. Эти паттерны
                    // сохраняют форму значения, поэтому тут они уместны, а не поломка.
                    if ($this->fitsNonTextType($pattern, $class, $type)) {
                        continue;
                    }

                    if ($class === 'numeric') {
                        $findings[] = Finding::warning(
                            'F-1',
                            sprintf(
                                'паттерн «%s» на числовой колонке (%s): значение вставится, но подменит '
                                . 'идентификатор и порвёт связи внутри дампа. Если колонка хранит ПД '
                                . '(телефон в bigint) — маппинг нужен; решение за человеком',
                                $pattern,
                                $type
                            ),
                            $schema,
                            $table,
                            $column,
                            false,
                            ['pattern' => $pattern, 'type' => $type, 'hint' => 'решить, ПД это или ключ связи']
                        );
                        continue;
                    }

                    $findings[] = Finding::error(
                        'F-1',
                        sprintf(
                            'паттерн «%s» на колонке типа %s — RussianFaker вернёт строку, INSERT дампа '
                            . 'упадёт на несовпадении типа',
                            $pattern,
                            $type
                        ),
                        $schema,
                        $table,
                        $column,
                        true,
                        [
                            'fix' => 'remove_faker_column',
                            'table' => $table,
                            'column' => $column,
                            'pattern' => $pattern,
                            'type' => $type,
                            'hint' => 'снять маппинг с нетекстовой колонки',
                        ]
                    );
                }
            }
        }

        return $findings;
    }

    /**
     * 'text' — маскировать безопасно, 'numeric' — вставится, но испортит значение,
     * 'breaking' — INSERT упадёт.
     */
    private function classify(string $type): string
    {
        $normalized = strtolower(trim($type));
        // «character varying(255)» / «numeric(18,2)» — точность для нас не важна.
        $normalized = (string) preg_replace('/\s*\(.*$/', '', $normalized);

        if (in_array($normalized, self::TEXT_TYPES, true)) {
            return 'text';
        }
        if (in_array($normalized, self::NUMERIC_TYPES, true)) {
            return 'numeric';
        }
        return 'breaking';
    }

    /**
     * Паттерн, которому нетекстовый тип не помеха: цифровые идентификаторы остаются цифрами,
     * дата рождения — датой того же формата.
     */
    private function fitsNonTextType(string $pattern, string $class, string $type): bool
    {
        $digitPatterns = [
            PatternDetector::PATTERN_INN,
            PatternDetector::PATTERN_OGRN,
            PatternDetector::PATTERN_DIGITS,
            PatternDetector::PATTERN_DOC_NUMBER,
            PatternDetector::PATTERN_DOC_SERIES,
        ];
        if ($class === 'numeric' && in_array($pattern, $digitPatterns, true)) {
            return true;
        }
        if ($pattern === PatternDetector::PATTERN_BIRTH_DATE) {
            $normalized = strtolower(trim((string) preg_replace('/\s*\(.*$/', '', $type)));

            return strpos($normalized, 'date') !== false || strpos($normalized, 'timestamp') !== false;
        }

        return false;
    }
}
