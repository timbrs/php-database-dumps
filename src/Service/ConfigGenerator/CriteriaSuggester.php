<?php

namespace Timbrs\DatabaseDumps\Service\ConfigGenerator;

use Timbrs\DatabaseDumps\Contract\ConnectionRegistryInterface;

/**
 * Строит авто-`sample.criteria` из профилей колонок.
 *
 * Для каждой категориальной колонки — по корзине на топ-значение
 * (name = "<col>_<value>", where = "<col> = '<value>'", квота). Булевы/lifecycle
 * (status/state/active) — частный случай: их 2-3 значения дают пары корзин.
 *
 * Код-сегменты (Eloquent scopes, методы репозиториев) добавляет ветка OPENCODE
 * (ConfigEnricher), не этот класс — здесь только то, что видно из ДАННЫХ.
 */
class CriteriaSuggester
{
    public const DEFAULT_QUOTA = 10;
    public const MIN_QUOTA = 10;
    public const MAX_QUOTA = 100;

    /** Максимум колонок, по которым раскладываем корзины (приоритет — меньшая кардинальность). */
    private const MAX_COLUMNS = 5;

    /** Максимум корзин на одну колонку. */
    private const MAX_BUCKETS_PER_COLUMN = 10;

    /** Максимум критериев на таблицу (защита от взрыва). */
    private const MAX_TOTAL_CRITERIA = 30;

    /** @var ConnectionRegistryInterface */
    private $registry;

    public function __construct(ConnectionRegistryInterface $registry)
    {
        $this->registry = $registry;
    }

    /**
     * Предложить критерии выборки по профилям колонок.
     *
     * @param array<int, ColumnProfile> $profiles
     * @param int $quota Квота строк на корзину (clamp MIN_QUOTA..MAX_QUOTA)
     * @return array<int, array{name: string, where: string, limit: int, source: string, confidence: int, column: string, value: string}>
     */
    public function suggest(array $profiles, ?string $connectionName = null, int $quota = self::DEFAULT_QUOTA): array
    {
        $quota = max(self::MIN_QUOTA, min(self::MAX_QUOTA, $quota));

        $connection = $this->registry->getConnection($connectionName);
        $platform = $this->registry->getPlatform($connectionName);

        // Отбираем категориальные колонки, приоритет — меньшая кардинальность (status-like).
        $categorical = [];
        foreach ($profiles as $profile) {
            if ($profile->isCategorical() && !empty($profile->getTopValues())) {
                $categorical[] = $profile;
            }
        }
        usort($categorical, function (ColumnProfile $a, ColumnProfile $b) {
            return $a->getDistinctCount() <=> $b->getDistinctCount();
        });
        $categorical = array_slice($categorical, 0, self::MAX_COLUMNS);

        $criteria = [];
        $seenNames = [];

        foreach ($categorical as $profile) {
            $column = $profile->getColumn();
            $quotedColumn = $platform->quoteIdentifier($column);

            $buckets = array_slice($profile->getTopValues(), 0, self::MAX_BUCKETS_PER_COLUMN);
            foreach ($buckets as $bucket) {
                if (count($criteria) >= self::MAX_TOTAL_CRITERIA) {
                    return $criteria;
                }
                $value = $bucket['value'];
                $where = $quotedColumn . ' = ' . $connection->quote($value);

                // Значение может содержать символы, ломающие WHERE и не проходящие
                // TableConfig-валидацию (';', SQL-комментарии, дисбаланс кавычек/скобок).
                // Такую корзину пропускаем — мангление сломало бы соответствие значению.
                if (!$this->isWhereClauseValid($where)) {
                    continue;
                }

                $name = $this->uniqueName($column, $value, $seenNames);

                $criteria[] = [
                    'name' => $name,
                    'where' => $where,
                    'limit' => $quota,
                    'source' => 'data',
                    'confidence' => 100,
                    'column' => $column,
                    'value' => $value,
                ];
            }
        }

        return $criteria;
    }

    /**
     * Преобразовать предложенные критерии в секцию sample для YAML.
     *
     * @param array<int, array{name: string, where: string, limit: int}> $criteria
     * @return array{criteria: array<int, array{name: string, where: string, limit: int}>}
     */
    public function toSampleConfig(array $criteria): array
    {
        $clean = [];
        foreach ($criteria as $c) {
            $clean[] = [
                'name' => $c['name'],
                'where' => $c['where'],
                'limit' => $c['limit'],
            ];
        }
        return ['criteria' => $clean];
    }

    /**
     * Проверить, что сгенерированный WHERE пройдёт TableConfig-валидацию.
     *
     * Зеркалит TableConfig::validateClause: запрет ';', SQL-комментариев,
     * баланс одинарных кавычек и скобок. Если значение корзины содержит такие
     * символы — клаузу не строим (см. вызов в suggest()).
     */
    private function isWhereClauseValid(string $where): bool
    {
        if (strpos($where, ';') !== false) {
            return false;
        }
        if (strpos($where, '--') !== false || strpos($where, '/*') !== false) {
            return false;
        }
        if (substr_count($where, "'") % 2 !== 0) {
            return false;
        }

        return substr_count($where, '(') === substr_count($where, ')');
    }

    /**
     * Сформировать валидный уникальный идентификатор имени корзины.
     *
     * Гарантии: результат всегда матчит TableConfig IDENTIFIER_REGEX
     * (^[A-Za-z_][A-Za-z0-9_$]*$) и уникален в рамках $seenNames.
     *
     * @param array<string, true> $seenNames
     */
    private function uniqueName(string $column, string $value, array &$seenNames): string
    {
        $slug = $this->slugify($column . '_' . $value);
        if ($slug === '' || preg_match('/^[A-Za-z_]/', $slug) !== 1) {
            // Ведущий символ невалиден (цифра/пусто) — префиксуем буквой.
            $base = $slug === '' ? $this->slugify($column) : $slug;
            $slug = $this->slugify('c_' . $base);
            if ($slug === '') {
                $slug = 'c';
            }
        }
        if (strlen($slug) > 60) {
            $slug = rtrim(substr($slug, 0, 60), '_');
            if ($slug === '') {
                $slug = 'c';
            }
        }

        $candidate = $slug;
        $i = 1;
        while (isset($seenNames[$candidate])) {
            $candidate = $slug . '_' . $i;
            $i++;
        }
        $seenNames[$candidate] = true;

        return $candidate;
    }

    /**
     * Привести строку к слагу из [A-Za-z0-9_] без ведущих/хвостовых '_'.
     */
    private function slugify(string $value): string
    {
        $slug = preg_replace('/[^A-Za-z0-9_]+/', '_', $value);
        if (!is_string($slug)) {
            return '';
        }

        return trim($slug, '_');
    }
}
