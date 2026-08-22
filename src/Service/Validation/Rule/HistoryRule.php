<?php

namespace Timbrs\DatabaseDumps\Service\Validation\Rule;

use Timbrs\DatabaseDumps\Config\TableConfig;
use Timbrs\DatabaseDumps\Service\Validation\AuditContext;
use Timbrs\DatabaseDumps\Service\Validation\Finding;

/**
 * Версионные таблицы (H-1).
 *
 * Пара колонок `date_from` / `date_to` — признак SCD2: строка не обновляется, а закрывается
 * и дописывается новой версией. Если такая таблица выбирается через sample и все критерии
 * отбирают только действующие версии (`date_to = '2100-01-01'`, `active_flg = true`), в дамп
 * попадёт срез «на сегодня» без истории — и любой экран, показывающий изменения во времени,
 * окажется пустым.
 *
 * Эвристика, поэтому severity note.
 */
class HistoryRule implements RuleInterface
{
    /** Признаки критерия, отбирающего именно ЗАКРЫТЫЕ версии. */
    private const CLOSED_PATTERNS = [
        '/active_flg\s*=\s*false/i',
        '/active_flg\s+is\s+false/i',
        '/\bdate_to\s*(?:<|<=|<>|!=)/i',
        '/\bdate_to\s*=\s*(?!\s*\'2100)/i',
        '/\bdate_to\s+is\s+not\s+null/i',
    ];

    /** Имена критериев, которые сами по себе говорят «это про историю». */
    private const CLOSED_NAMES = [
        '/inactive/i', '/closed/i', '/histor/i', '/archiv/i', '/previous/i', '/old/i',
    ];

    public function name(): string
    {
        return 'история';
    }

    public function apply(AuditContext $context): array
    {
        $findings = [];
        $inventory = $context->inventory();

        foreach ($context->scopedSchemas() as $schema) {
            foreach ($context->config()->getPartialExport($schema) as $table => $_conf) {
                $table = (string) $table;
                $config = $context->tableConfig($schema, $table);
                if ($config === null || !$config->hasSample()) {
                    continue;
                }
                if (!$inventory->hasColumn($schema, $table, 'date_from')
                    || !$inventory->hasColumn($schema, $table, 'date_to')) {
                    continue;
                }

                $sample = $config->getSample();
                $criteria = ($sample !== null && isset($sample[TableConfig::SAMPLE_KEY_CRITERIA])
                    && is_array($sample[TableConfig::SAMPLE_KEY_CRITERIA]))
                    ? $sample[TableConfig::SAMPLE_KEY_CRITERIA]
                    : [];
                if (empty($criteria) || $this->selectsClosedVersions($criteria)) {
                    continue;
                }

                $findings[] = Finding::note(
                    'H-1',
                    'таблица версионная (date_from/date_to), но ни один критерий выборки не отбирает '
                    . 'закрытые версии — в дамп попадёт только срез «на сегодня», история потеряется',
                    $schema,
                    $table,
                    null,
                    false,
                    ['hint' => "добавить критерий вида { name: closed, where: \"active_flg = false\", limit: N }"]
                );
            }
        }

        return $findings;
    }

    /**
     * @param array<int|string, mixed> $criteria
     */
    private function selectsClosedVersions(array $criteria): bool
    {
        foreach ($criteria as $criterion) {
            if (!is_array($criterion)) {
                continue;
            }
            $where = isset($criterion[TableConfig::CRITERION_KEY_WHERE])
                ? (string) $criterion[TableConfig::CRITERION_KEY_WHERE]
                : '';
            foreach (self::CLOSED_PATTERNS as $pattern) {
                if (preg_match($pattern, $where) === 1) {
                    return true;
                }
            }
            $name = isset($criterion[TableConfig::CRITERION_KEY_NAME])
                ? (string) $criterion[TableConfig::CRITERION_KEY_NAME]
                : '';
            foreach (self::CLOSED_NAMES as $pattern) {
                if (preg_match($pattern, $name) === 1) {
                    return true;
                }
            }
        }

        return false;
    }
}
