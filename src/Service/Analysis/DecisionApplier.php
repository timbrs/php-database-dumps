<?php

namespace Timbrs\DatabaseDumps\Service\Analysis;

use Timbrs\DatabaseDumps\Config\DumpConfig;
use Timbrs\DatabaseDumps\Config\TableConfig;
use Timbrs\DatabaseDumps\Service\Analysis\Decision\Decision;

/**
 * Применяет одно решение к массиву конфига выгрузки.
 *
 * Разнесено с ConfigEnricher намеренно: там правила «как класть cascade_from и criteria»,
 * здесь — таблица «вид изменения → место в YAML». Каждый вид знает только про свой ключ,
 * поэтому новый вид добавляется одним case'ом, а не правкой общего цикла.
 *
 * Три инварианта:
 *  - существующее значение побеждает без `override` — конфиг правят руками, и затирать
 *    ручную настройку молча нельзя;
 *  - конфиг мутируется только после валидации предложения через TableConfig::fromArray();
 *  - решение, чей `current` разошёлся с тем, что сейчас в конфиге, считается устаревшим
 *    (`stale`) и не применяется: анализ видел другой конфиг.
 *
 * PHP 7.2-совместимо.
 */
class DecisionApplier
{
    public const STATUS_APPLIED = 'applied';
    public const STATUS_SKIPPED_EXISTS = 'skipped_exists';
    public const STATUS_SKIPPED_SAME = 'skipped_same';
    public const STATUS_SKIPPED_NOT_ACCEPTED = 'skipped_not_accepted';
    public const STATUS_STALE = 'stale';
    public const STATUS_INVALID = 'invalid';
    public const STATUS_UNSUPPORTED = 'unsupported';

    /** Идентификатор схемы/таблицы: те же правила, что в ConfigEnricher (ключи конфига → пути файлов). */
    private const IDENTIFIER_REGEX = '/^[\p{L}_][\p{L}\p{N}_$]*$/u';

    /**
     * @param array<string, mixed> &$config плоский конфиг (includes уже развёрнуты)
     * @param array<string, mixed> $decision запись из decisions.<schema>.json
     *
     * @return array{status: string, reason?: string}
     */
    public function apply(array &$config, array $decision): array
    {
        if (!$this->isAccepted($decision)) {
            return ['status' => self::STATUS_SKIPPED_NOT_ACCEPTED];
        }

        $address = $this->address($decision);
        if ($address === null) {
            return ['status' => self::STATUS_INVALID, 'reason' => 'некорректное имя схемы или таблицы'];
        }
        list($schema, $table) = $address;

        $kind = isset($decision['kind']) ? (string) $decision['kind'] : '';
        if (!in_array($kind, Decision::KINDS, true)) {
            return ['status' => self::STATUS_UNSUPPORTED, 'reason' => 'неизвестный вид изменения: ' . $kind];
        }

        if ($kind === Decision::KIND_REMOVE_TABLE) {
            return $this->removeTable($config, $schema, $table);
        }
        if ($kind === Decision::KIND_MODE) {
            return $this->applyMode($config, $schema, $table, $decision);
        }
        if ($kind === Decision::KIND_FAKER) {
            return $this->applyFaker($config, $schema, $table, $decision);
        }

        return $this->applyTableKey($config, $schema, $table, $kind, $decision);
    }

    /**
     * Механическое (`auto`) применяется само; остальное — только с отметкой человека
     * или агента (`accepted`).
     *
     * @param array<string, mixed> $decision
     */
    private function isAccepted(array $decision): bool
    {
        if (!empty($decision['accepted'])) {
            return true;
        }

        return !empty($decision['auto']) && !isset($decision['accepted']);
    }

    /**
     * `schema.table` → пара, с проверкой обеих частей.
     *
     * @param array<string, mixed> $decision
     * @return array<int, string>|null
     */
    private function address(array $decision): ?array
    {
        $full = isset($decision['table']) && is_string($decision['table']) ? $decision['table'] : '';
        $parts = explode('.', $full);
        if (count($parts) !== 2) {
            return null;
        }
        if (!$this->isIdentifier($parts[0]) || !$this->isIdentifier($parts[1])) {
            return null;
        }

        return [$parts[0], $parts[1]];
    }

    /**
     * limit / where / order_by / cascade_from / criteria / stratify / stratify_via / per_value.
     *
     * @param array<string, mixed> &$config
     * @param array<string, mixed> $decision
     *
     * @return array{status: string, reason?: string}
     */
    private function applyTableKey(array &$config, string $schema, string $table, string $kind, array $decision): array
    {
        $sampleKey = $this->sampleKey($kind);
        $tableKey = $sampleKey === null ? $this->tableKey($kind) : null;
        if ($sampleKey === null && $tableKey === null) {
            return ['status' => self::STATUS_UNSUPPORTED, 'reason' => 'вид без апплаера: ' . $kind];
        }

        $existing = $this->tableConfig($config, $schema, $table);
        $current = $sampleKey !== null
            ? $this->sampleValue($existing, $sampleKey)
            : (isset($existing[$tableKey]) ? $existing[$tableKey] : null);

        $stale = $this->staleness($decision, $current);
        if ($stale !== null) {
            return ['status' => self::STATUS_STALE, 'reason' => $stale];
        }

        $proposed = isset($decision['proposed']) ? $decision['proposed'] : null;
        if ($proposed === null) {
            return ['status' => self::STATUS_INVALID, 'reason' => 'пустое предложение'];
        }

        // criteria и cascade_from накапливаются: новое ребро или сегмент дописывается
        // к существующим, а не заменяет их. Остальные ключи скалярные — замена.
        if ($kind === Decision::KIND_CRITERIA || $kind === Decision::KIND_CASCADE_FROM) {
            $merged = $this->mergeList(is_array($current) ? $current : [], $proposed, $kind);
            if ($merged === null) {
                return ['status' => self::STATUS_SKIPPED_EXISTS];
            }
            $proposed = $merged;
        } elseif ($current !== null && $current !== [] && empty($decision['override'])) {
            return $current === $proposed
                ? ['status' => self::STATUS_SKIPPED_SAME]
                : ['status' => self::STATUS_SKIPPED_EXISTS];
        } elseif ($current === $proposed) {
            return ['status' => self::STATUS_SKIPPED_SAME];
        }

        $candidate = $existing;
        if ($sampleKey !== null) {
            $sample = isset($candidate[TableConfig::KEY_SAMPLE]) && is_array($candidate[TableConfig::KEY_SAMPLE])
                ? $candidate[TableConfig::KEY_SAMPLE]
                : [];
            $sample[$sampleKey] = $proposed;
            $candidate[TableConfig::KEY_SAMPLE] = $sample;
        } else {
            $candidate[$tableKey] = $proposed;
        }

        $error = $this->validate($schema, $table, $candidate);
        if ($error !== null) {
            return ['status' => self::STATUS_INVALID, 'reason' => $error];
        }

        $this->ensureTablePresent($config, $schema, $table);
        $config[DumpConfig::KEY_PARTIAL_EXPORT][$schema][$table] = $candidate;

        return ['status' => self::STATUS_APPLIED];
    }

    /**
     * Перевод таблицы между full_export и partial_export.
     *
     * @param array<string, mixed> &$config
     * @param array<string, mixed> $decision
     *
     * @return array{status: string, reason?: string}
     */
    private function applyMode(array &$config, string $schema, string $table, array $decision): array
    {
        $proposed = isset($decision['proposed']) ? (string) $decision['proposed'] : '';
        $current = $this->currentMode($config, $schema, $table);

        $stale = $this->staleness($decision, $current);
        if ($stale !== null) {
            return ['status' => self::STATUS_STALE, 'reason' => $stale];
        }
        if ($proposed === $current) {
            return ['status' => self::STATUS_SKIPPED_SAME];
        }

        if ($proposed === DumpConfig::KEY_FULL_EXPORT) {
            // Настройки выборки теряют смысл при полной выгрузке — но молча выбрасывать
            // limit/where/sample нельзя, поэтому без override не трогаем.
            $existing = $this->tableConfig($config, $schema, $table);
            if ($existing !== [] && empty($decision['override'])) {
                return ['status' => self::STATUS_SKIPPED_EXISTS, 'reason' => 'у таблицы есть настройки выборки'];
            }
            unset($config[DumpConfig::KEY_PARTIAL_EXPORT][$schema][$table]);
            $this->pruneSchema($config, DumpConfig::KEY_PARTIAL_EXPORT, $schema);
            if (!isset($config[DumpConfig::KEY_FULL_EXPORT][$schema])
                || !is_array($config[DumpConfig::KEY_FULL_EXPORT][$schema])
            ) {
                $config[DumpConfig::KEY_FULL_EXPORT][$schema] = [];
            }
            if (!in_array($table, $config[DumpConfig::KEY_FULL_EXPORT][$schema], true)) {
                $config[DumpConfig::KEY_FULL_EXPORT][$schema][] = $table;
            }

            return ['status' => self::STATUS_APPLIED];
        }

        if ($proposed === DumpConfig::KEY_PARTIAL_EXPORT) {
            $this->ensureTablePresent($config, $schema, $table);

            return ['status' => self::STATUS_APPLIED];
        }

        return ['status' => self::STATUS_UNSUPPORTED, 'reason' => 'неизвестный режим: ' . $proposed];
    }

    /**
     * Маскирование колонки. `proposed: null` снимает маппинг.
     *
     * @param array<string, mixed> &$config
     * @param array<string, mixed> $decision
     *
     * @return array{status: string, reason?: string}
     */
    private function applyFaker(array &$config, string $schema, string $table, array $decision): array
    {
        $column = isset($decision['column']) && is_string($decision['column']) ? $decision['column'] : '';
        if (!$this->isIdentifier($column)) {
            return ['status' => self::STATUS_INVALID, 'reason' => 'faker без имени колонки'];
        }

        $current = isset($config[DumpConfig::KEY_FAKER][$schema][$table][$column])
            ? (string) $config[DumpConfig::KEY_FAKER][$schema][$table][$column]
            : null;

        $stale = $this->staleness($decision, $current);
        if ($stale !== null) {
            return ['status' => self::STATUS_STALE, 'reason' => $stale];
        }

        $proposed = isset($decision['proposed']) && is_string($decision['proposed'])
            ? $decision['proposed']
            : null;

        if ($proposed === null) {
            if ($current === null) {
                return ['status' => self::STATUS_SKIPPED_SAME];
            }
            unset($config[DumpConfig::KEY_FAKER][$schema][$table][$column]);
            if (empty($config[DumpConfig::KEY_FAKER][$schema][$table])) {
                unset($config[DumpConfig::KEY_FAKER][$schema][$table]);
            }
            $this->pruneSchema($config, DumpConfig::KEY_FAKER, $schema);

            return ['status' => self::STATUS_APPLIED];
        }

        if ($current === $proposed) {
            return ['status' => self::STATUS_SKIPPED_SAME];
        }
        if ($current !== null && empty($decision['override'])) {
            return ['status' => self::STATUS_SKIPPED_EXISTS];
        }

        $config[DumpConfig::KEY_FAKER][$schema][$table][$column] = $proposed;

        return ['status' => self::STATUS_APPLIED];
    }

    /**
     * Убрать таблицу из всех секций конфига (R7 — таблицы в конфиге нет в БД).
     *
     * @param array<string, mixed> &$config
     *
     * @return array{status: string, reason?: string}
     */
    private function removeTable(array &$config, string $schema, string $table): array
    {
        $removed = false;

        if (isset($config[DumpConfig::KEY_FULL_EXPORT][$schema])
            && is_array($config[DumpConfig::KEY_FULL_EXPORT][$schema])
        ) {
            $idx = array_search($table, $config[DumpConfig::KEY_FULL_EXPORT][$schema], true);
            if ($idx !== false) {
                array_splice($config[DumpConfig::KEY_FULL_EXPORT][$schema], (int) $idx, 1);
                $removed = true;
            }
            $this->pruneSchema($config, DumpConfig::KEY_FULL_EXPORT, $schema);
        }

        foreach ([DumpConfig::KEY_PARTIAL_EXPORT, DumpConfig::KEY_FAKER] as $section) {
            if (isset($config[$section][$schema][$table])) {
                unset($config[$section][$schema][$table]);
                $removed = true;
            }
            $this->pruneSchema($config, $section, $schema);
        }

        return $removed
            ? ['status' => self::STATUS_APPLIED]
            : ['status' => self::STATUS_SKIPPED_SAME, 'reason' => 'таблицы в конфиге уже нет'];
    }

    /**
     * Дописать ребро или сегмент к списку. Совпадение по адресу — существующее побеждает
     * (null — «применять нечего»).
     *
     * @param array<int, mixed> $current
     * @param mixed             $proposed
     *
     * @return array<int, mixed>|null
     */
    private function mergeList(array $current, $proposed, string $kind): ?array
    {
        if (!is_array($proposed)) {
            return null;
        }
        // Допускаем и одну запись, и список записей.
        $incoming = isset($proposed[0]) && is_array($proposed[0]) ? $proposed : [$proposed];

        $result = array_values($current);
        $added = false;
        foreach ($incoming as $entry) {
            if (!is_array($entry) || $this->hasEntry($result, $entry, $kind)) {
                continue;
            }
            $result[] = $entry;
            $added = true;
        }

        return $added ? $result : null;
    }

    /**
     * @param array<int, mixed>    $list
     * @param array<string, mixed> $entry
     */
    private function hasEntry(array $list, array $entry, string $kind): bool
    {
        $keys = $kind === Decision::KIND_CRITERIA ? ['name'] : ['parent', 'fk_column'];
        foreach ($list as $existing) {
            if (!is_array($existing)) {
                continue;
            }
            $same = true;
            foreach ($keys as $key) {
                $a = isset($existing[$key]) ? $existing[$key] : null;
                $b = isset($entry[$key]) ? $entry[$key] : null;
                if ($a !== $b) {
                    $same = false;
                    break;
                }
            }
            if ($same) {
                return true;
            }
        }

        return false;
    }

    /**
     * Конфиг изменился с момента анализа? Сравниваем то, что решение считало текущим,
     * с тем, что в конфиге сейчас. Отсутствие ключа `current` — старый файл, не проверяем.
     *
     * @param array<string, mixed> $decision
     * @param mixed                $actual
     */
    private function staleness(array $decision, $actual): ?string
    {
        if (!array_key_exists('current', $decision)) {
            return null;
        }
        $expected = $decision['current'];
        if ($expected === null && ($actual === null || $actual === [])) {
            return null;
        }
        if ($expected === $actual) {
            return null;
        }

        return 'конфиг изменился после анализа: решение исходило из другого значения';
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private function tableConfig(array $config, string $schema, string $table): array
    {
        return isset($config[DumpConfig::KEY_PARTIAL_EXPORT][$schema][$table])
            && is_array($config[DumpConfig::KEY_PARTIAL_EXPORT][$schema][$table])
                ? $config[DumpConfig::KEY_PARTIAL_EXPORT][$schema][$table]
                : [];
    }

    /**
     * @param array<string, mixed> $tableConfig
     * @return mixed
     */
    private function sampleValue(array $tableConfig, string $sampleKey)
    {
        return isset($tableConfig[TableConfig::KEY_SAMPLE][$sampleKey])
            ? $tableConfig[TableConfig::KEY_SAMPLE][$sampleKey]
            : null;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function currentMode(array $config, string $schema, string $table): string
    {
        if (isset($config[DumpConfig::KEY_PARTIAL_EXPORT][$schema][$table])) {
            return DumpConfig::KEY_PARTIAL_EXPORT;
        }
        if (isset($config[DumpConfig::KEY_FULL_EXPORT][$schema])
            && is_array($config[DumpConfig::KEY_FULL_EXPORT][$schema])
            && in_array($table, $config[DumpConfig::KEY_FULL_EXPORT][$schema], true)
        ) {
            return DumpConfig::KEY_FULL_EXPORT;
        }

        return 'not_exported';
    }

    private function sampleKey(string $kind): ?string
    {
        $map = [
            Decision::KIND_CRITERIA => TableConfig::SAMPLE_KEY_CRITERIA,
            Decision::KIND_STRATIFY => TableConfig::SAMPLE_KEY_STRATIFY,
            Decision::KIND_STRATIFY_VIA => TableConfig::SAMPLE_KEY_STRATIFY_VIA,
            Decision::KIND_PER_VALUE => TableConfig::SAMPLE_KEY_PER_VALUE,
        ];

        return isset($map[$kind]) ? $map[$kind] : null;
    }

    private function tableKey(string $kind): ?string
    {
        $map = [
            Decision::KIND_LIMIT => TableConfig::KEY_LIMIT,
            Decision::KIND_WHERE => TableConfig::KEY_WHERE,
            Decision::KIND_ORDER_BY => TableConfig::KEY_ORDER_BY,
            Decision::KIND_CASCADE_FROM => TableConfig::KEY_CASCADE_FROM,
        ];

        return isset($map[$kind]) ? $map[$kind] : null;
    }

    /**
     * @param array<string, mixed> $candidate
     */
    private function validate(string $schema, string $table, array $candidate): ?string
    {
        try {
            TableConfig::fromArray($schema, $table, $candidate);
        } catch (\InvalidArgumentException $e) {
            return $e->getMessage();
        }

        return null;
    }

    /**
     * @param array<string, mixed> &$config
     */
    private function ensureTablePresent(array &$config, string $schema, string $table): void
    {
        if (isset($config[DumpConfig::KEY_FULL_EXPORT][$schema])
            && is_array($config[DumpConfig::KEY_FULL_EXPORT][$schema])
        ) {
            $idx = array_search($table, $config[DumpConfig::KEY_FULL_EXPORT][$schema], true);
            if ($idx !== false) {
                array_splice($config[DumpConfig::KEY_FULL_EXPORT][$schema], (int) $idx, 1);
                $this->pruneSchema($config, DumpConfig::KEY_FULL_EXPORT, $schema);
            }
        }

        if (!isset($config[DumpConfig::KEY_PARTIAL_EXPORT][$schema])
            || !is_array($config[DumpConfig::KEY_PARTIAL_EXPORT][$schema])
        ) {
            $config[DumpConfig::KEY_PARTIAL_EXPORT][$schema] = [];
        }
        if (!isset($config[DumpConfig::KEY_PARTIAL_EXPORT][$schema][$table])) {
            $config[DumpConfig::KEY_PARTIAL_EXPORT][$schema][$table] = [];
        }
    }

    /**
     * Убрать опустевшие схему и секцию — иначе в YAML остаются пустые узлы.
     *
     * @param array<string, mixed> &$config
     */
    private function pruneSchema(array &$config, string $section, string $schema): void
    {
        if (isset($config[$section][$schema]) && empty($config[$section][$schema])) {
            unset($config[$section][$schema]);
        }
        if (isset($config[$section]) && empty($config[$section])) {
            unset($config[$section]);
        }
    }

    /**
     * @param mixed $value
     */
    private function isIdentifier($value): bool
    {
        return is_string($value) && $value !== '' && (bool) preg_match(self::IDENTIFIER_REGEX, $value);
    }
}
