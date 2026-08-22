<?php

namespace Timbrs\DatabaseDumps\Service\Validation\Rule;

use Timbrs\DatabaseDumps\Config\DumpConfig;
use Timbrs\DatabaseDumps\Service\Validation\AuditContext;
use Timbrs\DatabaseDumps\Service\Validation\Finding;

/**
 * Структура конфига: читается ли он вообще и не противоречит ли сам себе.
 *
 *  S-1 — YAML не разобрался или файл из `includes:` пропал;
 *  S-2 — TableConfig отверг таблицу (её настройки не доедут до экспорта);
 *  S-3 — таблица одновременно в full_export и partial_export;
 *  S-4 — пустая секция (остаётся после правок и сбивает с толку).
 */
class StructureRule implements RuleInterface
{
    /** Маркер «ключа нет» — отличаем от `key:` с пустым значением. */
    private const MISSING = "\x00missing";

    public function name(): string
    {
        return 'структура';
    }

    public function apply(AuditContext $context): array
    {
        $findings = [];

        foreach ($context->config()->getLoadErrors() as $error) {
            if (!$context->inScope($error['schema'])) {
                continue;
            }
            $findings[] = Finding::error(
                'S-1',
                sprintf('%s: %s', $error['file'], $error['message']),
                $error['schema'],
                null,
                null,
                false,
                ['file' => $error['file']]
            );
        }

        foreach ($context->tableConfigErrors() as $key => $message) {
            $parts = explode('.', $key, 2);
            $schema = $parts[0];
            $table = isset($parts[1]) ? $parts[1] : '';
            if (!$context->inScope($schema)) {
                continue;
            }
            $findings[] = Finding::error(
                'S-2',
                'TableConfig отверг настройки таблицы: ' . $message,
                $schema,
                $table
            );
        }

        foreach ($context->scopedSchemas() as $schema) {
            foreach ($context->config()->getTableModes($schema) as $table => $mode) {
                if ($mode !== 'both') {
                    continue;
                }
                $findings[] = Finding::error(
                    'S-3',
                    'таблица объявлена и в full_export, и в partial_export — '
                    . 'экспорт возьмёт настройки partial_export, запись в full_export ничего не делает',
                    $schema,
                    (string) $table,
                    null,
                    false,
                    ['hint' => 'убрать таблицу из одной из секций']
                );
            }

            foreach ($this->emptySections($context, $schema) as $finding) {
                $findings[] = $finding;
            }
        }

        return $findings;
    }

    /**
     * Пустые секции: `full_export: []`, `partial_export: {}`, `faker: {}` и пустая
     * карта колонок у отдельной таблицы внутри faker.
     *
     * @return array<int, Finding>
     */
    private function emptySections(AuditContext $context, string $schema): array
    {
        $config = $context->config();
        $raw = $config->getSchemaRaw($schema);
        $findings = [];

        $sections = [DumpConfig::KEY_FULL_EXPORT, DumpConfig::KEY_PARTIAL_EXPORT, DumpConfig::KEY_FAKER];

        foreach ($sections as $section) {
            $value = $this->sectionValue($context, $raw, $schema, $section);
            if ($value === self::MISSING) {
                continue;
            }
            if (is_array($value) && empty($value)) {
                $findings[] = Finding::warning(
                    'S-4',
                    sprintf('пустая секция %s — уберите ключ, иначе он вводит в заблуждение', $section),
                    $schema,
                    null,
                    null,
                    true,
                    ['fix' => 'remove_section', 'section' => $section, 'hint' => 'удалить пустой ключ ' . $section]
                );
            }
        }

        foreach ($config->getFaker($schema) as $table => $columns) {
            if (empty($columns)) {
                $findings[] = Finding::warning(
                    'S-4',
                    'пустая карта faker у таблицы — маскирование не настроено, ключ ничего не делает',
                    $schema,
                    (string) $table,
                    null,
                    true,
                    [
                        'fix' => 'remove_faker_table',
                        'table' => (string) $table,
                        'hint' => 'удалить пустую запись faker.' . $table,
                    ]
                );
            }
        }

        return $findings;
    }

    /**
     * @param array<string, mixed> $schemaRaw
     * @return mixed
     */
    private function sectionValue(AuditContext $context, array $schemaRaw, string $schema, string $section)
    {
        if (array_key_exists($section, $schemaRaw)) {
            return $schemaRaw[$section];
        }

        // Конфиг без разбиения: секция лежит в главном файле, схема — ключом внутри неё.
        $main = $context->config()->getMainRaw();
        if (isset($main[$section]) && is_array($main[$section]) && array_key_exists($schema, $main[$section])) {
            return $main[$section][$schema];
        }

        return self::MISSING;
    }
}
