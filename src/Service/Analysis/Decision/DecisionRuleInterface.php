<?php

namespace Timbrs\DatabaseDumps\Service\Analysis\Decision;

/**
 * Правило, читающее досье таблицы и предлагающее изменения конфига.
 *
 * Правило ничего не пишет: оно возвращает решения, а применяет их apply — и только те,
 * что помечены auto или приняты человеком либо агентом.
 */
interface DecisionRuleInterface
{
    /** Код правила: R1…R11 (или agent/legacy у внешних источников). */
    public function code(): string;

    /**
     * @param array<string, mixed> $table   запись таблицы из досье
     * @param array<string, mixed> $context {schema, table_name, settings, dossier}
     *
     * @return array<int, Decision>
     */
    public function apply(array $table, array $context): array;
}
