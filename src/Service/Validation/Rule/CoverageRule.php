<?php

namespace Timbrs\DatabaseDumps\Service\Validation\Rule;

use Timbrs\DatabaseDumps\Service\Validation\AuditContext;
use Timbrs\DatabaseDumps\Service\Validation\Finding;

/**
 * Покрытие: сходятся ли список таблиц в конфиге и список таблиц в слепке схемы.
 *
 *  C-1 — таблица есть в слепке, но не выгружается (её просто не будет в дампе);
 *  C-2 — таблица есть в конфиге, но её нет в слепке (слепок устарел либо таблицу
 *        отфильтровал ServiceTableFilter — так из анализа выпала tasks.jobs);
 *  C-3 — схема есть на одной стороне и отсутствует на другой.
 *
 * Всё это warning, а не error: невыгружаемая системная таблица (ag_catalog) — осознанное
 * решение, и порог `error` не должен на неё срабатывать.
 */
class CoverageRule implements RuleInterface
{
    public function name(): string
    {
        return 'покрытие';
    }

    public function apply(AuditContext $context): array
    {
        $findings = [];
        $config = $context->config();
        $inventory = $context->inventory();

        foreach ($context->scopedSchemas() as $schema) {
            $inInventory = $inventory->hasSchema($schema);
            $modes = $config->getTableModes($schema);
            $inConfig = !empty($modes) || array_key_exists($schema, $config->getIncludes());

            if ($inInventory && !$inConfig) {
                $findings[] = Finding::warning(
                    'C-3',
                    $config->isSplit()
                        ? 'схема есть в слепке, но её нет в includes: — ни одна её таблица не выгружается'
                        : 'схема есть в слепке, но её нет в конфиге — ни одна её таблица не выгружается',
                    $schema,
                    null,
                    null,
                    false,
                    ['hint' => 'добавить файл настроек схемы и запись в includes:']
                );
            } elseif (!$inInventory && $inConfig) {
                $findings[] = Finding::warning(
                    'C-3',
                    'схема есть в конфиге, но её нет в слепке — либо слепок устарел, либо схему удалили из БД',
                    $schema,
                    null,
                    null,
                    false,
                    ['hint' => 'пересобрать слепок (prepare-analysis) или убрать схему из конфига']
                );
            }

            foreach ($inventory->tables($schema) as $table) {
                if (isset($modes[$table])) {
                    continue;
                }
                $rowCount = $inventory->rowCount($schema, $table);
                $findings[] = Finding::warning(
                    'C-1',
                    sprintf(
                        'таблица есть в слепке (%s), но не выгружается — в дампе её не будет',
                        $rowCount === null ? 'строк неизвестно' : $rowCount . ' строк'
                    ),
                    $schema,
                    $table,
                    null,
                    false,
                    [
                        'hint' => 'добавить в full_export (справочник) или в partial_export с limit',
                        'row_count' => $rowCount,
                    ]
                );
            }

            if (!$inInventory) {
                // Схемы нет в слепке целиком — про каждую её таблицу докладывать не о чем,
                // C-3 выше уже это сказал.
                continue;
            }

            foreach ($modes as $table => $mode) {
                if ($inventory->hasTable($schema, (string) $table)) {
                    continue;
                }
                $findings[] = Finding::warning(
                    'C-2',
                    'таблица есть в конфиге, но её нет в слепке — проверить по коду и миграциям, '
                    . 'что она существует в БД (иначе экспорт упадёт на ней)',
                    $schema,
                    (string) $table,
                    null,
                    false,
                    ['hint' => 'сверить с миграциями и сущностями; при необходимости пересобрать слепок']
                );
            }
        }

        return $findings;
    }
}
