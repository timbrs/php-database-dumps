<?php

namespace Timbrs\DatabaseDumps\Service\Incremental;

use Timbrs\DatabaseDumps\Config\DumpConfig;
use Timbrs\DatabaseDumps\Service\Validation\InventoryReader;

/**
 * Что перепроверять после прошлого удачного прогона.
 *
 * Четыре сенсора, каждый ловит своё:
 *  - **миграции** новее отметки — колонка в этом проекте появляется миграцией;
 *  - **конфиг** — хеш настроек таблицы: правку руками не заметит никто другой;
 *  - **слепок** — хеши колонок, кодов и внешних ключей: новое значение `status_id`
 *    появляется без всякой миграции, и только diff слепков это видит;
 *  - **git** — файлы, изменившиеся между коммитами отметки и HEAD. На схлопнутой истории
 *    (`git log` без предков) сенсор честно докладывает, что отключён, а не молчит.
 *
 * Нет отметки — грязное всё: это первый прогон, и делать надо полный цикл.
 *
 * PHP 7.2-совместимо.
 */
class DirtySetBuilder
{
    public const FILE = 'dirty.json';

    public const SENSOR_MIGRATION = 'migration';
    public const SENSOR_CONFIG = 'config';
    public const SENSOR_INVENTORY = 'inventory';
    public const SENSOR_GIT = 'git';

    /** @var MigrationDiffParser */
    private $migrations;

    /**
     * @var callable|null fn(string $fromCommit): array<int, string>|null — изменённые файлы;
     *                    null означает «истории нет, сенсор выключен»
     */
    private $gitDiff;

    /**
     * @param callable|null $gitDiff
     */
    public function __construct(MigrationDiffParser $migrations, $gitDiff = null)
    {
        $this->migrations = $migrations;
        $this->gitDiff = $gitDiff;
    }

    /**
     * @param array<int, string> $schemas ограничить схемами (пусто — все из конфига)
     *
     * @return array<string, mixed> содержимое dirty.json
     */
    public function build(
        ?Checkpoint $checkpoint,
        DumpConfig $dumpConfig,
        InventoryReader $inventory,
        array $schemas = []
    ): array {
        $configured = $this->configuredTables($dumpConfig, $schemas);

        if ($checkpoint === null) {
            return $this->fullRebuild($configured, 'отметки нет — нужен полный цикл');
        }

        $reasons = [];
        $sensors = [];

        $sensors[self::SENSOR_MIGRATION] = $this->migrationSensor($checkpoint, $configured, $reasons);
        $sensors[self::SENSOR_CONFIG] = $this->configSensor($checkpoint, $dumpConfig, $configured, $reasons);
        $sensors[self::SENSOR_INVENTORY] = $this->inventorySensor($checkpoint, $inventory, $configured, $reasons);
        $sensors[self::SENSOR_GIT] = $this->gitSensor($checkpoint, $configured, $reasons);

        ksort($reasons);

        $bySensor = [];
        foreach ($reasons as $entry) {
            foreach ($entry['reasons'] as $reason) {
                $bySensor[$reason['sensor']] = (isset($bySensor[$reason['sensor']]) ? $bySensor[$reason['sensor']] : 0) + 1;
            }
        }
        ksort($bySensor);

        return [
            'generated_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'full' => false,
            'checkpoint' => [
                'created_at' => $checkpoint->createdAt(),
                'newest_migration' => $checkpoint->newestMigration(),
                'inventory_generated_at' => $checkpoint->inventoryGeneratedAt(),
                'head_commit' => $checkpoint->headCommit(),
            ],
            'sensors' => $sensors,
            'summary' => [
                'dirty' => count($reasons),
                'configured' => count($configured),
                'by_sensor' => $bySensor,
            ],
            'tables' => array_keys($reasons),
            'details' => $reasons,
        ];
    }

    /**
     * Хеши для новой отметки — считаются тем же кодом, что и сравниваются, иначе
     * сенсор начнёт врать при первом расхождении формул.
     *
     * @param array<int, string> $schemas
     *
     * @return array<string, array<string, mixed>>
     */
    public function snapshotHashes(DumpConfig $dumpConfig, InventoryReader $inventory, array $schemas = []): array
    {
        $out = [];
        foreach ($this->configuredTables($dumpConfig, $schemas) as $key) {
            list($schema, $table) = explode('.', $key, 2);
            $out[$key] = [
                'config_sha256' => $this->configHash($dumpConfig, $schema, $table),
                'columns_sha256' => $this->columnsHash($inventory, $schema, $table),
                'codes_sha256' => $this->codesHash($inventory, $schema, $table),
                'foreign_keys_sha256' => $this->foreignKeysHash($inventory, $schema, $table),
                'verified_at' => gmdate('Y-m-d\TH:i:s\Z'),
            ];
        }

        return $out;
    }

    /**
     * Плоский список для `--tables-from`.
     *
     * @param array<string, mixed> $dirty
     *
     * @return array<int, string>
     */
    public static function tableList(array $dirty): array
    {
        return isset($dirty['tables']) && is_array($dirty['tables']) ? array_values($dirty['tables']) : [];
    }

    /**
     * @param array<int, string> $configured
     *
     * @return array<string, mixed>
     */
    private function fullRebuild(array $configured, string $why): array
    {
        $details = [];
        foreach ($configured as $key) {
            $details[$key] = ['reasons' => [['sensor' => 'none', 'why' => $why]]];
        }

        return [
            'generated_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'full' => true,
            'checkpoint' => null,
            'sensors' => [],
            'summary' => [
                'dirty' => count($configured),
                'configured' => count($configured),
                'by_sensor' => [],
            ],
            'tables' => $configured,
            'details' => $details,
        ];
    }

    /**
     * @param array<int, string>                        $configured
     * @param array<string, array<string, mixed>>       $reasons
     *
     * @return array<string, mixed>
     */
    private function migrationSensor(Checkpoint $checkpoint, array $configured, array &$reasons): array
    {
        $since = $checkpoint->newestMigration();
        $newest = $this->migrations->newestVersion();
        $versions = $this->migrations->versionsSince($since);
        $changed = $this->migrations->tablesChangedSince($since);

        $known = array_flip($configured);
        $unknown = [];
        foreach ($changed as $key => $facts) {
            $why = sprintf('миграции после %s: %s', $since === null ? 'начала' : $since, implode(', ', $facts['versions']));
            if ($facts['dml_rows'] > 0) {
                $why .= sprintf(' (наполнение: %d строк)', $facts['dml_rows']);
            }
            if (!isset($known[$key])) {
                // Таблицу завели миграцией, а в конфиг она не попала: перепроверять нечего,
                // но и молчать нельзя — это повод дозапустить prepare-config.
                $unknown[] = $key;
                continue;
            }
            $this->addReason($reasons, $key, self::SENSOR_MIGRATION, $why, $facts['jira']);
        }

        return [
            'enabled' => true,
            'since' => $since,
            'newest' => $newest,
            'new_versions' => count($versions),
            'tables_touched' => count($changed),
            'not_in_config' => $unknown,
        ];
    }

    /**
     * @param array<int, string>                  $configured
     * @param array<string, array<string, mixed>> $reasons
     *
     * @return array<string, mixed>
     */
    private function configSensor(
        Checkpoint $checkpoint,
        DumpConfig $dumpConfig,
        array $configured,
        array &$reasons
    ): array {
        $changed = 0;
        $added = 0;
        foreach ($configured as $key) {
            list($schema, $table) = explode('.', $key, 2);
            $now = $this->configHash($dumpConfig, $schema, $table);
            $was = $checkpoint->hash($key, 'config_sha256');

            if ($was === null) {
                if ($checkpoint->table($key) === null) {
                    $this->addReason($reasons, $key, self::SENSOR_CONFIG, 'таблицы не было в отметке');
                    $added++;
                }
                continue;
            }
            if ($was !== $now) {
                $this->addReason($reasons, $key, self::SENSOR_CONFIG, 'настройки таблицы изменились');
                $changed++;
            }
        }

        // Таблица была в отметке и исчезла из конфига: перепроверять нечего, но в отчёте
        // это должно быть видно — иначе непонятно, почему её дамп больше не обновляется.
        $removed = [];
        $known = array_flip($configured);
        foreach (array_keys($checkpoint->tables()) as $key) {
            if (!isset($known[$key])) {
                $removed[] = $key;
            }
        }

        return ['enabled' => true, 'changed' => $changed, 'added' => $added, 'removed' => $removed];
    }

    /**
     * @param array<int, string>                  $configured
     * @param array<string, array<string, mixed>> $reasons
     *
     * @return array<string, mixed>
     */
    private function inventorySensor(
        Checkpoint $checkpoint,
        InventoryReader $inventory,
        array $configured,
        array &$reasons
    ): array {
        if (!$inventory->exists()) {
            return ['enabled' => false, 'why_skipped' => 'слепка нет — выполните prepare-analysis'];
        }

        $fields = [
            'columns_sha256' => 'состав или типы колонок изменились',
            'codes_sha256' => 'набор значений-кодов в базе изменился',
            'foreign_keys_sha256' => 'внешние ключи таблицы изменились',
        ];
        $counters = ['columns_sha256' => 0, 'codes_sha256' => 0, 'foreign_keys_sha256' => 0];

        foreach ($configured as $key) {
            list($schema, $table) = explode('.', $key, 2);
            if (!$inventory->hasTable($schema, $table)) {
                continue;
            }
            $now = [
                'columns_sha256' => $this->columnsHash($inventory, $schema, $table),
                'codes_sha256' => $this->codesHash($inventory, $schema, $table),
                'foreign_keys_sha256' => $this->foreignKeysHash($inventory, $schema, $table),
            ];
            foreach ($fields as $field => $why) {
                $was = $checkpoint->hash($key, $field);
                if ($was !== null && $was !== $now[$field]) {
                    $this->addReason($reasons, $key, self::SENSOR_INVENTORY, $why);
                    $counters[$field]++;
                }
            }
        }

        return [
            'enabled' => true,
            'inventory_generated_at' => $inventory->generatedAt(),
            'checkpoint_inventory_at' => $checkpoint->inventoryGeneratedAt(),
            'columns_changed' => $counters['columns_sha256'],
            'codes_changed' => $counters['codes_sha256'],
            'foreign_keys_changed' => $counters['foreign_keys_sha256'],
        ];
    }

    /**
     * @param array<int, string>                  $configured
     * @param array<string, array<string, mixed>> $reasons
     *
     * @return array<string, mixed>
     */
    private function gitSensor(Checkpoint $checkpoint, array $configured, array &$reasons): array
    {
        $from = $checkpoint->headCommit();
        if ($this->gitDiff === null) {
            return ['enabled' => false, 'why_skipped' => 'git-сенсор не подключён'];
        }
        if ($from === null) {
            return ['enabled' => false, 'why_skipped' => 'в отметке нет коммита'];
        }

        $files = call_user_func($this->gitDiff, $from);
        if (!is_array($files)) {
            return [
                'enabled' => false,
                'why_skipped' => 'история недоступна (схлопнутый клон или коммита нет в истории)',
                'from' => $from,
            ];
        }

        $versions = $this->migrations->versionsOfFiles($files);
        $tables = $this->migrations->tablesOfVersions($versions);
        $known = array_flip($configured);
        $touched = 0;
        foreach ($tables as $key) {
            if (!isset($known[$key])) {
                continue;
            }
            $this->addReason($reasons, $key, self::SENSOR_GIT, 'миграция таблицы изменилась в git после отметки');
            $touched++;
        }

        return [
            'enabled' => true,
            'from' => $from,
            'files_changed' => count($files),
            'migrations_changed' => count($versions),
            'tables_touched' => $touched,
        ];
    }

    /**
     * @param array<string, array<string, mixed>> $reasons
     */
    private function addReason(array &$reasons, string $key, string $sensor, string $why, ?string $ref = null): void
    {
        if (!isset($reasons[$key])) {
            $reasons[$key] = ['reasons' => []];
        }
        $entry = ['sensor' => $sensor, 'why' => $why];
        if ($ref !== null) {
            $entry['ref'] = $ref;
        }
        $reasons[$key]['reasons'][] = $entry;
    }

    /**
     * @param array<int, string> $schemas
     *
     * @return array<int, string>
     */
    private function configuredTables(DumpConfig $dumpConfig, array $schemas): array
    {
        $wanted = $schemas === [] ? null : array_flip($schemas);

        $keys = [];
        foreach ($dumpConfig->getAllFullExportSchemas() as $schema) {
            if ($wanted !== null && !isset($wanted[$schema])) {
                continue;
            }
            foreach ($dumpConfig->getFullExportTables($schema) as $table) {
                $keys[$schema . '.' . $table] = true;
            }
        }
        foreach ($dumpConfig->getAllPartialExportSchemas() as $schema) {
            if ($wanted !== null && !isset($wanted[$schema])) {
                continue;
            }
            foreach (array_keys($dumpConfig->getPartialExportTables($schema)) as $table) {
                $keys[$schema . '.' . $table] = true;
            }
        }
        $list = array_keys($keys);
        sort($list);

        return $list;
    }

    /**
     * Хеш настроек таблицы. Ключи сортируются: переставленный YAML — не изменение.
     */
    private function configHash(DumpConfig $dumpConfig, string $schema, string $table): string
    {
        $config = $dumpConfig->getTableConfig($schema, $table);
        if ($config === null) {
            // full_export: настроек у таблицы нет, но режим — тоже состояние.
            $config = ['mode' => 'full_export'];
        }

        return $this->hash($config);
    }

    private function columnsHash(InventoryReader $inventory, string $schema, string $table): string
    {
        $columns = [];
        foreach ($inventory->columns($schema, $table) as $column) {
            $columns[$column] = $inventory->columnType($schema, $table, $column);
        }

        return $this->hash($columns);
    }

    /**
     * Хеш кодов колонок: только имена колонок и сами коды (они прошли PII-шлюз).
     * `distinct_count` входит сюда же — рост числа значений тоже повод перепроверить.
     */
    private function codesHash(InventoryReader $inventory, string $schema, string $table): string
    {
        $codes = [];
        foreach ($inventory->columns($schema, $table) as $column) {
            $profile = $inventory->profile($schema, $table, $column);
            if ($profile === null) {
                continue;
            }
            $entry = [];
            if (isset($profile['codes']) && is_array($profile['codes'])) {
                $values = array_map('strval', $profile['codes']);
                sort($values);
                $entry['codes'] = $values;
            }
            if (isset($profile['distinct_count'])) {
                $entry['distinct_count'] = $profile['distinct_count'];
            }
            if ($entry !== []) {
                $codes[$column] = $entry;
            }
        }

        return $this->hash($codes);
    }

    private function foreignKeysHash(InventoryReader $inventory, string $schema, string $table): string
    {
        $keys = [];
        foreach ($inventory->foreignKeys($schema, $table) as $fk) {
            $keys[] = $fk['column'] . '→' . $fk['references_table'] . '.' . $fk['references_column'];
        }
        sort($keys);

        return $this->hash($keys);
    }

    /**
     * @param mixed $value
     */
    private function hash($value): string
    {
        $normalized = $this->normalize($value);
        $json = json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return hash('sha256', $json === false ? '' : $json);
    }

    /**
     * Рекурсивно упорядочить ключи: порядок в YAML не должен считаться изменением.
     *
     * @param mixed $value
     * @return mixed
     */
    private function normalize($value)
    {
        if (!is_array($value)) {
            return $value;
        }
        $isList = array_keys($value) === range(0, count($value) - 1);
        $out = [];
        foreach ($value as $key => $item) {
            $out[$key] = $this->normalize($item);
        }
        if (!$isList) {
            ksort($out);
        }

        return $out;
    }
}
