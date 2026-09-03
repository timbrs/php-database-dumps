<?php

namespace Timbrs\DatabaseDumps\Service\Analysis;

use Timbrs\DatabaseDumps\Service\Analysis\Decision\Decision;

/**
 * Переходник со старого контракта агента (`output_schema.json`: relationships / criteria /
 * columns) на решения (`decisions.<schema>.json`).
 *
 * Нужен, пока живы прогоны старого агента `dbdump-mapper`: их вывод уже лежит в
 * `{data_dir}/analysis/out/*.json`, и терять его при переходе на решения нельзя. Связи и
 * критерии становятся решениями с `rule: legacy`, а `columns[]` — аннотациями к досье
 * (`agent_note`): раньше их выбрасывали, потому что в конфиг выгрузки им хода нет.
 *
 * Ни одно legacy-решение не бывает `auto`: старый контракт не различает механическое
 * изменение и изменение состава выборки, а `confidence` там ставила модель.
 *
 * PHP 7.2-совместимо.
 */
class LegacyOutputAdapter
{
    public const RULE = 'legacy';

    /**
     * Решения из нормализованного вывода AnalysisIngestor.
     *
     * @param array<string, mixed> $ingested результат AnalysisIngestor::ingest()
     *
     * @return array<int, Decision>
     */
    public function toDecisions(array $ingested): array
    {
        $decisions = [];

        $cascade = isset($ingested['cascade_from']) && is_array($ingested['cascade_from'])
            ? $ingested['cascade_from']
            : [];
        foreach ($cascade as $edge) {
            if (!is_array($edge)) {
                continue;
            }
            $decision = $this->cascadeDecision($edge);
            if ($decision !== null) {
                $decisions[] = $decision;
            }
        }

        $criteria = isset($ingested['sample_criteria']) && is_array($ingested['sample_criteria'])
            ? $ingested['sample_criteria']
            : [];
        foreach ($criteria as $criterion) {
            if (!is_array($criterion)) {
                continue;
            }
            $decision = $this->criterionDecision($criterion);
            if ($decision !== null) {
                $decisions[] = $decision;
            }
        }

        return $decisions;
    }

    /**
     * Тот же конверт, что отдаёт DecisionEngine::decide() — чтобы `apply` читал оба файла
     * одним кодом.
     *
     * @param array<string, mixed> $ingested
     *
     * @return array<string, mixed>
     */
    public function toPackage(array $ingested, string $schema = ''): array
    {
        $list = [];
        $byKind = [];
        foreach ($this->toDecisions($ingested) as $decision) {
            $entry = $decision->toArray();
            $list[] = $entry;
            $byKind[$entry['kind']] = (isset($byKind[$entry['kind']]) ? $byKind[$entry['kind']] : 0) + 1;
        }
        ksort($byKind);

        return [
            'generated_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'schema' => $schema,
            'summary' => [
                'total' => count($list),
                'auto' => 0,
                'needs_review' => count($list),
                'by_rule' => $list === [] ? [] : [self::RULE => count($list)],
                'by_kind' => $byKind,
            ],
            'decisions' => $list,
        ];
    }

    /**
     * Заметки агента по колонкам — для досье. В конфиг выгрузки они не идут: `usages`/`is_key`
     * ничего в YAML не задают, но подсказывают, какие колонки стоит разложить по корзинам.
     *
     * @param array<string, mixed> $ingested
     *
     * @return array<string, array<string, array<string, mixed>>> table => column => заметка
     */
    public function toAnnotations(array $ingested): array
    {
        $columns = isset($ingested['columns']) && is_array($ingested['columns']) ? $ingested['columns'] : [];

        $out = [];
        foreach ($columns as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $table = $this->str($entry, 'table');
            $column = $this->str($entry, 'column');
            if ($table === '' || $column === '') {
                continue;
            }

            $note = ['source' => Decision::SOURCE_AGENT];
            if (isset($entry['usages']) && is_array($entry['usages'])) {
                $usages = [];
                foreach ($entry['usages'] as $usage) {
                    if (is_string($usage) && $usage !== '') {
                        $usages[] = $usage;
                    }
                }
                if ($usages !== []) {
                    $note['usages'] = array_values(array_unique($usages));
                }
            }
            if (isset($entry['is_key'])) {
                $note['is_key'] = (bool) $entry['is_key'];
            }
            $text = $this->str($entry, 'note');
            if ($text !== '') {
                $note['agent_note'] = $text;
            }

            $out[$table][$column] = $note;
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $edge
     */
    private function cascadeDecision(array $edge): ?Decision
    {
        $schema = $this->str($edge, 'schema');
        $table = $this->str($edge, 'table');
        $parent = $this->str($edge, 'parent');
        $fkColumn = $this->str($edge, 'fk_column');
        $parentColumn = $this->str($edge, 'parent_column');

        if ($schema === '' || $table === '' || $parent === '' || $fkColumn === '' || $parentColumn === '') {
            return null;
        }

        $proposed = [
            'parent' => $parent,
            'fk_column' => $fkColumn,
            'parent_column' => $parentColumn,
        ];

        return new Decision(
            $schema . '.' . $table,
            $fkColumn,
            Decision::KIND_CASCADE_FROM,
            null,
            $proposed,
            self::RULE,
            sprintf('Агент нашёл в коде связь %s → %s (старый формат вывода)', $fkColumn, $parent),
            $this->evidence($edge),
            $this->confidence($edge),
            false
        );
    }

    /**
     * @param array<string, mixed> $criterion
     */
    private function criterionDecision(array $criterion): ?Decision
    {
        $schema = $this->str($criterion, 'schema');
        $table = $this->str($criterion, 'table');
        $name = $this->str($criterion, 'name');
        $where = $this->str($criterion, 'where');

        if ($schema === '' || $table === '' || $name === '' || $where === '') {
            return null;
        }

        $proposed = ['name' => $name, 'where' => $where];
        if (isset($criterion['limit']) && is_int($criterion['limit'])) {
            $proposed['limit'] = $criterion['limit'];
        }

        return new Decision(
            $schema . '.' . $table,
            null,
            Decision::KIND_CRITERIA,
            null,
            $proposed,
            self::RULE,
            sprintf('Агент выделил бизнес-сегмент «%s» (старый формат вывода)', $name),
            $this->evidence($criterion),
            $this->confidence($criterion),
            false
        );
    }

    /**
     * Старый контракт знал только `source: code` и не давал файла со строкой.
     *
     * @param array<string, mixed> $entry
     *
     * @return array<int, array<string, mixed>>
     */
    private function evidence(array $entry): array
    {
        $note = 'вывод агента dbdump-mapper';
        if ($this->str($entry, 'kind') !== '') {
            $note .= ', kind: ' . $this->str($entry, 'kind');
        }

        return [['source' => Decision::SOURCE_AGENT, 'note' => $note]];
    }

    /**
     * Числовой confidence старого контракта (0..100) → шкала решений.
     *
     * @param array<string, mixed> $entry
     */
    private function confidence(array $entry): string
    {
        if (!isset($entry['confidence']) || !is_int($entry['confidence'])) {
            return 'low';
        }
        if ($entry['confidence'] >= 80) {
            return 'high';
        }
        if ($entry['confidence'] >= 50) {
            return 'med';
        }

        return 'low';
    }

    /**
     * @param array<string, mixed> $entry
     */
    private function str(array $entry, string $key): string
    {
        return isset($entry[$key]) && is_string($entry[$key]) ? trim($entry[$key]) : '';
    }
}
