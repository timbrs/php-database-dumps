<?php

namespace Timbrs\DatabaseDumps\Service\Analysis\Decision\Rule;

use Timbrs\DatabaseDumps\Service\Analysis\Decision\Decision;
use Timbrs\DatabaseDumps\Service\Analysis\Decision\DecisionEngine;
use Timbrs\DatabaseDumps\Service\Analysis\Decision\DecisionRuleInterface;

/**
 * R2: справочник, который режут лимитом.
 *
 * Урезанный словарь — худший вид поломки дампа: приложение стартует, страницы открываются,
 * а половина значений «неизвестный тип». Признаки того, что таблицу надо брать целиком:
 * имя `*_dict`, малый размер, много входящих ссылок или наполнение прямо из миграции —
 * последнее означает, что строки считаются частью схемы, а не данными.
 */
class R02PromoteToFullExport implements DecisionRuleInterface
{
    use RuleHelperTrait;

    /** Столько входящих ссылок — таблица уже инфраструктурная. */
    private const IN_DEGREE_THRESHOLD = 3;

    public function code(): string
    {
        return 'R2';
    }

    public function apply(array $table, array $context): array
    {
        if ($this->mode($table) !== 'partial_export') {
            return [];
        }

        $traits = $this->traits($table);
        $rows = $this->rows($table);
        $small = (int) $this->setting($context, 'small_table_rows', DecisionEngine::DEFAULT_SMALL_TABLE_ROWS);
        $dmlRows = isset($table['migrations']['dml_rows']) ? (int) $table['migrations']['dml_rows'] : 0;
        $inDegree = isset($traits['in_degree']) ? (int) $traits['in_degree'] : 0;

        $reasons = [];
        $evidence = [];
        if (!empty($traits['dict'])) {
            $reasons[] = 'имя таблицы говорит о справочнике';
        }
        if ($rows !== null && $rows <= $small) {
            $reasons[] = sprintf('всего %d строк', $rows);
            $evidence[] = ['source' => Decision::SOURCE_DB, 'note' => 'row_count=' . $rows];
        }
        if ($inDegree >= self::IN_DEGREE_THRESHOLD) {
            $reasons[] = sprintf('на неё ссылаются %d таблиц', $inDegree);
        }
        if ($dmlRows > 0) {
            $reasons[] = sprintf('наполняется миграциями (%d строк)', $dmlRows);
            $evidence[] = [
                'source' => Decision::SOURCE_MIGRATION,
                'ref' => isset($table['migrations']['last_changed_in']) ? $table['migrations']['last_changed_in'] : null,
                'note' => 'INSERT в миграции',
            ];
        }
        if ($reasons === []) {
            return [];
        }

        // Большая таблица словарём не бывает: не поднимаем целиком то, что придётся резать.
        $threshold = (int) $this->setting($context, 'full_export_threshold', DecisionEngine::DEFAULT_FULL_EXPORT_THRESHOLD);
        if ($rows !== null && $rows > $threshold) {
            return [];
        }

        return [new Decision(
            $context['full_name'],
            null,
            Decision::KIND_MODE,
            'partial_export',
            'full_export',
            $this->code(),
            'выгружать целиком: ' . implode('; ', $reasons) . ' — урезанный справочник ломает потребителей дампа',
            $evidence,
            $dmlRows > 0 || !empty($traits['dict']) ? 'high' : 'med',
            false
        )];
    }
}
