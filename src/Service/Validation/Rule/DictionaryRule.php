<?php

namespace Timbrs\DatabaseDumps\Service\Validation\Rule;

use Timbrs\DatabaseDumps\Config\TableConfig;
use Timbrs\DatabaseDumps\Service\Dumper\SampleQueryBuilder;
use Timbrs\DatabaseDumps\Service\Validation\AuditContext;
use Timbrs\DatabaseDumps\Service\Validation\Finding;

/**
 * Справочники, обрезанные лимитом (D-1).
 *
 * Классический промах конфига: словарь `*_dict` попадает в partial_export с общим
 * `limit: 500` или, хуже, с парой критериев по 10 строк — а UI ищет по нему расшифровку
 * значения. На дампе экран показывает пустоту, хотя формально таблица «выгружена».
 *
 * Эвристика, а не факт, поэтому severity note: считается верхняя граница выборки
 * (limit, а при sample — сумма квот) и сравнивается с row_count из слепка.
 */
class DictionaryRule implements RuleInterface
{
    /** Ниже этого числа строк словарь дешевле выгрузить целиком, чем объяснять обрезку. */
    private const SMALL_TABLE_ROWS = 50000;

    public function name(): string
    {
        return 'справочники';
    }

    public function apply(AuditContext $context): array
    {
        $findings = [];
        $inventory = $context->inventory();

        foreach ($context->scopedSchemas() as $schema) {
            foreach ($context->config()->getPartialExport($schema) as $table => $_conf) {
                $table = (string) $table;
                if (!$this->looksLikeDictionary($table)) {
                    continue;
                }
                $config = $context->tableConfig($schema, $table);
                if ($config === null) {
                    continue;
                }
                $rowCount = $inventory->rowCount($schema, $table);
                if ($rowCount === null || $rowCount === 0) {
                    continue;
                }

                $cap = $this->effectiveCap($context, $config, $schema, $table);
                if ($cap === null || $cap >= $rowCount) {
                    continue;
                }

                $findings[] = Finding::note(
                    'D-1',
                    sprintf(
                        'справочник выгружается не целиком: в выборку попадёт не более %d строк из %d — '
                        . 'экран, который ищет по нему расшифровку значения, покажет пустоту%s',
                        $cap,
                        $rowCount,
                        $this->singleValueHint($context, $config, $schema, $table)
                    ),
                    $schema,
                    $table,
                    null,
                    false,
                    [
                        'cap' => $cap,
                        'row_count' => $rowCount,
                        'hint' => $rowCount <= self::SMALL_TABLE_ROWS
                            ? 'перевести в full_export'
                            : 'поднять limit или связать выборку с таблицей-потребителем',
                    ]
                );
            }
        }

        return $findings;
    }

    private function looksLikeDictionary(string $table): bool
    {
        return (bool) preg_match('/_dict$/i', $table);
    }

    /**
     * Верхняя граница числа строк, которое реально попадёт в дамп. null — ограничения нет.
     */
    private function effectiveCap(AuditContext $context, TableConfig $config, string $schema, string $table): ?int
    {
        $limit = $config->getLimit();
        $sample = $config->getSample();

        if ($sample === null) {
            return $limit;
        }

        $quota = 0;
        $criteria = isset($sample[TableConfig::SAMPLE_KEY_CRITERIA]) && is_array($sample[TableConfig::SAMPLE_KEY_CRITERIA])
            ? $sample[TableConfig::SAMPLE_KEY_CRITERIA]
            : [];
        foreach ($criteria as $criterion) {
            if (is_array($criterion) && isset($criterion[TableConfig::CRITERION_KEY_LIMIT])) {
                $quota += (int) $criterion[TableConfig::CRITERION_KEY_LIMIT];
            }
        }

        $stratifyBy = isset($sample[TableConfig::SAMPLE_KEY_STRATIFY_BY])
            ? $sample[TableConfig::SAMPLE_KEY_STRATIFY_BY]
            : null;
        if (is_string($stratifyBy) && $stratifyBy !== '') {
            $perValue = isset($sample[TableConfig::SAMPLE_KEY_PER_VALUE])
                ? (int) $sample[TableConfig::SAMPLE_KEY_PER_VALUE]
                : TableConfig::DEFAULT_PER_VALUE;
            $profile = $context->inventory()->profile($schema, $table, $stratifyBy);
            $buckets = SampleQueryBuilder::MAX_STRATIFY_BUCKETS;
            if ($profile !== null && isset($profile['distinct_count']) && $profile['distinct_count'] !== null
                && empty($profile['distinct_capped'])) {
                $distinct = (int) $profile['distinct_count'];
                if ($distinct > 0 && $distinct < $buckets) {
                    $buckets = $distinct;
                }
            }
            $quota += $buckets * $perValue;
        }

        if ($quota === 0) {
            return $limit;
        }
        return $limit === null ? $quota : min($limit, $quota);
    }

    /**
     * Если критерии делят словарь по колонке, у которой в слепке ровно одно значение,
     * половина корзин заведомо пуста — стоит сказать об этом прямо.
     */
    private function singleValueHint(AuditContext $context, TableConfig $config, string $schema, string $table): string
    {
        $sample = $config->getSample();
        if ($sample === null || !isset($sample[TableConfig::SAMPLE_KEY_CRITERIA])
            || !is_array($sample[TableConfig::SAMPLE_KEY_CRITERIA])) {
            return '';
        }

        foreach ($context->inventory()->columns($schema, $table) as $column) {
            $profile = $context->inventory()->profile($schema, $table, $column);
            if ($profile === null || !isset($profile['distinct_count']) || (int) $profile['distinct_count'] !== 1) {
                continue;
            }
            foreach ($sample[TableConfig::SAMPLE_KEY_CRITERIA] as $criterion) {
                if (!is_array($criterion) || !isset($criterion[TableConfig::CRITERION_KEY_WHERE])) {
                    continue;
                }
                $where = (string) $criterion[TableConfig::CRITERION_KEY_WHERE];
                if (stripos($where, $column) !== false) {
                    return sprintf(
                        '; критерии делят выборку по «%s», а в слепке у неё одно-единственное значение — '
                        . 'часть корзин заведомо пуста',
                        $column
                    );
                }
            }
        }

        return '';
    }
}
