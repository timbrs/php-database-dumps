<?php

namespace Timbrs\DatabaseDumps\Service\Analysis\Decision;

use Timbrs\DatabaseDumps\Service\Analysis\Decision\Rule\R01SplitHugeFullExport;
use Timbrs\DatabaseDumps\Service\Analysis\Decision\Rule\R02PromoteToFullExport;
use Timbrs\DatabaseDumps\Service\Analysis\Decision\Rule\R03CoverCategoricalColumns;
use Timbrs\DatabaseDumps\Service\Analysis\Decision\Rule\R04MaskPersonalData;
use Timbrs\DatabaseDumps\Service\Analysis\Decision\Rule\R05LinkTables;
use Timbrs\DatabaseDumps\Service\Analysis\Decision\Rule\R06VersionedSlices;
use Timbrs\DatabaseDumps\Service\Analysis\Decision\Rule\R07DropPhantomTables;
use Timbrs\DatabaseDumps\Service\Analysis\Decision\Rule\R08HighCardinality;
use Timbrs\DatabaseDumps\Service\Analysis\Decision\Rule\R09EnumVersusDatabase;
use Timbrs\DatabaseDumps\Service\Analysis\Decision\Rule\R10QuotaFitsLimit;
use Timbrs\DatabaseDumps\Service\Analysis\Decision\Rule\R11FixFakerPattern;

/**
 * Правила, превращающие досье в предложения по конфигу.
 *
 * Тулза сама пишет только механическое (`auto`): убрать ссылку на несуществующее, поставить
 * faker на колонку с персональными данными, добавить связь, подтверждённую внешним ключом.
 * Всё, что меняет состав выборки, уходит человеку или агенту по коду — с формулировкой почему
 * и со ссылками на доказательства.
 *
 * PHP 7.2-совместимо.
 */
class DecisionEngine
{
    /** Порог строк, выше которого полная выгрузка становится частичной (R1). */
    public const DEFAULT_FULL_EXPORT_THRESHOLD = 10000;

    /** Ниже этого размера таблицу проще выгрузить целиком (R2). */
    public const DEFAULT_SMALL_TABLE_ROWS = 500;

    /** @var array<int, DecisionRuleInterface> */
    private $rules;

    /**
     * @param array<int, DecisionRuleInterface>|null $rules
     */
    public function __construct(?array $rules = null)
    {
        $this->rules = $rules !== null ? $rules : [
            new R01SplitHugeFullExport(),
            new R02PromoteToFullExport(),
            new R03CoverCategoricalColumns(),
            new R04MaskPersonalData(),
            new R05LinkTables(),
            new R06VersionedSlices(),
            new R07DropPhantomTables(),
            new R08HighCardinality(),
            new R09EnumVersusDatabase(),
            new R10QuotaFitsLimit(),
            new R11FixFakerPattern(),
        ];
    }

    /**
     * @param array<string, mixed> $dossier  досье схемы (DossierBuilder::build)
     * @param array<string, mixed> $settings пороги и параметры
     *
     * @return array<string, mixed> {generated_at, schema, summary, decisions[]}
     */
    public function decide(array $dossier, array $settings = []): array
    {
        $schema = isset($dossier['schema']) ? (string) $dossier['schema'] : '';
        $tables = isset($dossier['tables']) && is_array($dossier['tables']) ? $dossier['tables'] : [];

        $decisions = [];
        foreach ($tables as $name => $table) {
            if (!is_array($table)) {
                continue;
            }
            $context = [
                'schema' => $schema,
                'table_name' => (string) $name,
                'full_name' => $schema . '.' . $name,
                'settings' => $settings,
                'dossier' => $dossier,
            ];
            foreach ($this->rules as $rule) {
                // Сбой одного правила не должен лишать предложений остальные.
                try {
                    foreach ($rule->apply($table, $context) as $decision) {
                        $decisions[$decision->getId()] = $decision;
                    }
                } catch (\Throwable $e) {
                    // Тихо пропускаем: решения — предложения, а не проверки; о поломке скажет
                    // отсутствие правила в summary.rules.
                    continue;
                }
            }
        }

        $list = [];
        $auto = 0;
        $byRule = [];
        $byKind = [];
        foreach ($decisions as $decision) {
            $entry = $decision->toArray();
            $list[] = $entry;
            if ($decision->isAuto()) {
                $auto++;
            }
            $byRule[$entry['rule']] = (isset($byRule[$entry['rule']]) ? $byRule[$entry['rule']] : 0) + 1;
            $byKind[$entry['kind']] = (isset($byKind[$entry['kind']]) ? $byKind[$entry['kind']] : 0) + 1;
        }
        usort($list, function (array $a, array $b): int {
            $byTable = strcmp($a['table'], $b['table']);
            if ($byTable !== 0) {
                return $byTable;
            }

            return strcmp($a['rule'], $b['rule']);
        });
        ksort($byRule);
        ksort($byKind);

        return [
            'generated_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'schema' => $schema,
            'summary' => [
                'total' => count($list),
                'auto' => $auto,
                'needs_review' => count($list) - $auto,
                'by_rule' => $byRule,
                'by_kind' => $byKind,
            ],
            'decisions' => $list,
        ];
    }

    /**
     * @return array<int, string>
     */
    public function ruleCodes(): array
    {
        $codes = [];
        foreach ($this->rules as $rule) {
            $codes[] = $rule->code();
        }

        return $codes;
    }
}
