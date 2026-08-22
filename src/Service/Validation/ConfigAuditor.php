<?php

namespace Timbrs\DatabaseDumps\Service\Validation;

use Timbrs\DatabaseDumps\Contract\FileSystemInterface;
use Timbrs\DatabaseDumps\Service\Validation\Rule\CascadeGraphRule;
use Timbrs\DatabaseDumps\Service\Validation\Rule\ColumnExistenceRule;
use Timbrs\DatabaseDumps\Service\Validation\Rule\CoverageRule;
use Timbrs\DatabaseDumps\Service\Validation\Rule\CriteriaRule;
use Timbrs\DatabaseDumps\Service\Validation\Rule\DictionaryRule;
use Timbrs\DatabaseDumps\Service\Validation\Rule\FakerTypeRule;
use Timbrs\DatabaseDumps\Service\Validation\Rule\HistoryRule;
use Timbrs\DatabaseDumps\Service\Validation\Rule\RuleInterface;
use Timbrs\DatabaseDumps\Service\Validation\Rule\StructureRule;

/**
 * Аудит конфигурации выгрузки БЕЗ подключения к базе.
 *
 * Единственный источник знаний о схеме — замороженный слепок (`schema_inventory*.json`),
 * поэтому команду можно гонять там, где живой БД нет и не будет: в CI, в открытом контуре,
 * до подъёма стенда. Правила детерминированы: один и тот же конфиг со слепком всегда дают
 * один и тот же список находок.
 */
class ConfigAuditor
{
    /** @var FileSystemInterface */
    private $fileSystem;

    /** @var array<int, RuleInterface> */
    private $rules;

    /**
     * @param array<int, RuleInterface>|null $rules набор правил (по умолчанию — все)
     */
    public function __construct(FileSystemInterface $fileSystem, ?array $rules = null)
    {
        $this->fileSystem = $fileSystem;
        $this->rules = $rules ?? self::defaultRules();
    }

    /**
     * Полный набор правил в порядке вывода: структура → покрытие → колонки → критерии →
     * faker → каскады → эвристики.
     *
     * @return array<int, RuleInterface>
     */
    public static function defaultRules(): array
    {
        return [
            new StructureRule(),
            new CoverageRule(),
            new ColumnExistenceRule(),
            new CriteriaRule(),
            new FakerTypeRule(),
            new CascadeGraphRule(),
            new DictionaryRule(),
            new HistoryRule(),
        ];
    }

    /**
     * @param array<int, string> $schemaFilter только эти схемы (пусто — все)
     */
    public function audit(string $configPath, InventoryReader $inventory, array $schemaFilter = []): AuditResult
    {
        $config = ConfigDocument::load($this->fileSystem, $configPath);
        $context = new AuditContext($config, $inventory, $schemaFilter);

        $findings = [];
        foreach ($this->rules as $rule) {
            // Сбой одного правила не должен лишать отчёта остальных: превращаем его в находку.
            try {
                foreach ($rule->apply($context) as $finding) {
                    $findings[] = $finding;
                }
            } catch (\Throwable $e) {
                $findings[] = Finding::error(
                    'X-1',
                    sprintf('правило «%s» не отработало: %s', $rule->name(), $e->getMessage())
                );
            }
        }

        $findings = $this->sortFindings($findings);

        return new AuditResult(
            $findings,
            $this->buildCoverage($context),
            [
                'config_path' => $configPath,
                'inventory_path' => $inventory->getPath(),
                'inventory_generated_at' => $inventory->generatedAt(),
                'inventory_present' => $inventory->exists(),
                'schemas_checked' => $context->scopedSchemas(),
                'schema_filter' => array_values($schemaFilter),
                'rules' => $this->ruleNames(),
            ]
        );
    }

    /**
     * @return array<int, string>
     */
    private function ruleNames(): array
    {
        $names = [];
        foreach ($this->rules as $rule) {
            $names[] = $rule->name();
        }
        return $names;
    }

    /**
     * Порядок вывода: сначала важность, затем код, затем адрес — чтобы отчёты двух прогонов
     * отличались только содержанием, а не перестановкой строк.
     *
     * @param array<int, Finding> $findings
     * @return array<int, Finding>
     */
    private function sortFindings(array $findings): array
    {
        usort($findings, function (Finding $a, Finding $b) {
            $bySeverity = $a->severityRank() <=> $b->severityRank();
            if ($bySeverity !== 0) {
                return $bySeverity;
            }
            $byCode = strcmp($a->getCode(), $b->getCode());
            if ($byCode !== 0) {
                return $byCode;
            }
            return strcmp($a->getTarget(), $b->getTarget());
        });

        return $findings;
    }

    /**
     * Сводка покрытия по схемам: сколько таблиц в слепке, сколько из них выгружается,
     * сколько таблиц вообще в конфиге, сколько под full_export и под sample.
     *
     * @return array{schemas: array<string, array<string, int>>, totals: array<string, int>}
     */
    private function buildCoverage(AuditContext $context): array
    {
        $config = $context->config();
        $inventory = $context->inventory();

        $schemas = [];
        $totals = [
            'inventory' => 0,
            'config' => 0,
            'covered' => 0,
            'uncovered' => 0,
            'unknown' => 0,
            'full_export' => 0,
            'partial' => 0,
            'sample' => 0,
        ];

        foreach ($context->scopedSchemas() as $schema) {
            $modes = $config->getTableModes($schema);
            $inventoryTables = $inventory->tables($schema);

            $covered = 0;
            foreach ($inventoryTables as $table) {
                if (isset($modes[$table])) {
                    $covered++;
                }
            }

            $unknown = 0;
            $full = 0;
            $partial = 0;
            $sample = 0;
            foreach ($modes as $table => $mode) {
                $table = (string) $table;
                if (!$inventory->hasTable($schema, $table)) {
                    $unknown++;
                }
                if ($mode === 'full') {
                    $full++;
                } else {
                    $partial++;
                }
                $tableConfig = $context->tableConfig($schema, $table);
                if ($tableConfig !== null && $tableConfig->hasSample()) {
                    $sample++;
                }
            }

            $row = [
                'inventory' => count($inventoryTables),
                'config' => count($modes),
                'covered' => $covered,
                'uncovered' => count($inventoryTables) - $covered,
                'unknown' => $unknown,
                'full_export' => $full,
                'partial' => $partial,
                'sample' => $sample,
            ];
            $schemas[$schema] = $row;

            foreach ($row as $key => $value) {
                $totals[$key] += $value;
            }
        }

        return ['schemas' => $schemas, 'totals' => $totals];
    }
}
