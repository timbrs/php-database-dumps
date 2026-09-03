<?php

namespace Timbrs\DatabaseDumps\Service\Faker;

use Timbrs\DatabaseDumps\Contract\ConnectionRegistryInterface;
use Timbrs\DatabaseDumps\Platform\PlatformFactory;
use Timbrs\DatabaseDumps\Service\Db\SafeQueryPolicy;

/**
 * Определяет паттерны персональных данных в колонках таблицы по выборке случайных строк.
 *
 * Улучшения по сравнению с базовой версией:
 * - Phone regex принимает международные форматы (>= 7 цифр), не только русский мобильный 9XX.
 * - FIO regex дополнен поддержкой Latin-имён (для иностранных сотрудников),
 *   апострофов (О'Брайен), non-breaking spaces (нормализуются через preg_replace).
 * - Имена колонок (firstname/lastname/patronymic) могут быть определены БЕЗ наличия
 *   composite name column (важно для схем, где хранятся только компоненты).
 * - Gender map расширен: добавлены 1/0, true/false, Y/N, ISO 5218 1/2.
 * - Выборка строк не сортирует таблицу целиком: PG — TABLESAMPLE (BERNOULLI в пределах
 *   max_scan_rows, SYSTEM на больших), Oracle на больших — SAMPLE, MySQL на больших — голова
 *   таблицы; таблица неизвестного размера — голова.
 * - Маленькие таблицы (<10 строк): помечаем подозрительные колонки по имени
 *   (fail-safe для security — лучше лишние замены, чем пропуск PII).
 */
class PatternDetector
{
    public const PATTERN_FIO = 'fio';
    public const PATTERN_FIO_SHORT = 'fio_short';
    public const PATTERN_NAME = 'name';
    public const PATTERN_EMAIL = 'email';
    public const PATTERN_PHONE = 'phone';
    public const PATTERN_FIRSTNAME = 'firstname';
    public const PATTERN_LASTNAME = 'lastname';
    public const PATTERN_PATRONYMIC = 'patronymic';
    public const PATTERN_GENDER = 'gender';

    /**
     * Список всех допустимых паттернов (используется для валидации FakerConfig).
     */
    public const ALLOWED_PATTERNS = [
        self::PATTERN_FIO,
        self::PATTERN_FIO_SHORT,
        self::PATTERN_NAME,
        self::PATTERN_EMAIL,
        self::PATTERN_PHONE,
        self::PATTERN_FIRSTNAME,
        self::PATTERN_LASTNAME,
        self::PATTERN_PATRONYMIC,
        self::PATTERN_GENDER,
    ];

    public const SAMPLE_SIZE = 200;
    public const DETECTION_THRESHOLD = 0.80;

    /** Минимальная выборка ниже которой включаем column-name fallback (fail-safe). */
    private const MIN_VALUES_FOR_REGEX = 10;

    private const REGEX_EMAIL = '/^[a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,}$/u';
    /** Международный телефонный формат: 7..15 цифр (после удаления non-digit) */
    private const REGEX_PHONE_INTL = '/^\d{7,15}$/';

    /** 3 слова: кириллица или латиница (с дефисами, апострофами) */
    private const REGEX_FIO = '/^[А-ЯЁа-яёA-Za-z\']+(?:[\-\s][А-ЯЁа-яёA-Za-z\']+)?\s+[А-ЯЁа-яёA-Za-z\']+(?:[\-\s][А-ЯЁа-яёA-Za-z\']+)?\s+[А-ЯЁа-яёA-Za-z\']+(?:[\-\s][А-ЯЁа-яёA-Za-z\']+)?$/u';
    /** Фамилия + 2 инициала с точками */
    private const REGEX_FIO_SHORT = '/^[А-ЯЁа-яёA-Za-z\']+(?:\-[А-ЯЁа-яёA-Za-z\']+)?\s+[А-ЯЁA-Z]\.\s?[А-ЯЁA-Z]\.$/u';
    /** 2 слова */
    private const REGEX_NAME = '/^[А-ЯЁа-яёA-Za-z\']+(?:[\-\s][А-ЯЁа-яёA-Za-z\']+)?\s+[А-ЯЁа-яёA-Za-z\']+(?:[\-\s][А-ЯЁа-яёA-Za-z\']+)?$/u';
    /** Одно слово (кириллица/латиница, дефис, апостроф) */
    private const REGEX_SINGLE_WORD = '/^[А-ЯЁа-яёA-Za-z\']+(?:\-[А-ЯЁа-яёA-Za-z\']+)?$/u';
    /** Суффиксы отчеств */
    private const REGEX_PATRONYMIC_SUFFIX = '/(ович|евич|ьич|овна|евна|ична|инична)$/u';
    /** Суффиксы фамилий */
    private const REGEX_LASTNAME_SUFFIX = '/(ов|ова|ев|ева|ёв|ёва|ин|ина|ын|ына|ский|ская|цкий|цкая|ых|их)$/u';

    private const COLUMN_HINTS_FIRSTNAME = ['/first_?name/i', '/fname/i', '/given/i', '/имя/ui'];
    private const COLUMN_HINTS_LASTNAME = ['/last_?name/i', '/lname/i', '/surname/i', '/family/i', '/фамилия/ui'];
    private const COLUMN_HINTS_PATRONYMIC = ['/patronym/i', '/middle_?name/i', '/отчество/ui'];
    private const COLUMN_HINTS_GENDER = ['/gender/i', '/^gen$/i', '/^sex$/i', '/^пол$/ui'];
    private const COLUMN_HINTS_EMAIL = ['/email/i', '/e_?mail/i', '/почта/ui'];
    private const COLUMN_HINTS_PHONE = ['/phone/i', '/mobile/i', '/телефон/ui', '/^тел$/ui'];
    private const COLUMN_HINTS_FIO = [
        '/^fio$/i',
        '/^full_?name$/i',
        '/^name$/i',
        '/^display_name$/i',
        '/^person_name$/i',
        '/^author_name$/i',
        '/^client_name$/i',
        '/^contact_name$/i',
        '/фио/ui',
    ];

    /** @var array<string> Допустимые значения пола (lowercase) */
    private const GENDER_VALUES = [
        'male', 'female', 'm', 'f',
        'м', 'ж',
        'мужской', 'женский',
        'муж', 'жен',
        'мужчина', 'женщина',
        '1', '0', '2',
        'true', 'false',
        'y', 'n',
        'man', 'woman',
    ];

    /** @var ConnectionRegistryInterface */
    private $registry;

    /** @var int */
    private $sampleSize;

    /** @var SafeQueryPolicy|null */
    private $policy;

    /**
     * @param int $sampleSize
     */
    public function __construct(ConnectionRegistryInterface $registry, $sampleSize = self::SAMPLE_SIZE, SafeQueryPolicy $policy = null)
    {
        $this->registry = $registry;
        $this->sampleSize = (int) $sampleSize;
        $this->policy = $policy;
    }

    /**
     * Намекает ли имя колонки на персональные данные (ФИО, телефон, e-mail, пол и их части).
     * Единый список подсказок для всех, кто решает, можно ли показывать значения колонки.
     */
    public static function hintsPii(string $column): bool
    {
        foreach ([
            self::COLUMN_HINTS_FIO,
            self::COLUMN_HINTS_EMAIL,
            self::COLUMN_HINTS_PHONE,
            self::COLUMN_HINTS_FIRSTNAME,
            self::COLUMN_HINTS_LASTNAME,
            self::COLUMN_HINTS_PATRONYMIC,
            self::COLUMN_HINTS_GENDER,
        ] as $hints) {
            foreach ($hints as $regex) {
                if (preg_match($regex, $column) === 1) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Анализирует колонки таблицы и возвращает обнаруженные паттерны ПД.
     *
     * @param int|null $rowCount известное или оценённое число строк — выбирает способ выборки
     * @return array<string, string> column_name => pattern_type
     */
    public function detect(string $schema, string $table, ?string $connectionName = null, ?int $rowCount = null): array
    {
        $connection = $this->registry->getConnection($connectionName);
        $platform = $this->registry->getPlatform($connectionName);

        $sql = $this->buildSampleSql($platform, $connection, $schema, $table, $rowCount);

        $rows = $connection->fetchAllAssociative($sql);

        if (empty($rows) && strpos($sql, 'SAMPLE') !== false) {
            // Блочная выборка могла не попасть ни в один блок — голова таблицы.
            $rows = $connection->fetchAllAssociative(
                'SELECT * FROM ' . $platform->getFullTableName($schema, $table) . ' ' . $platform->getLimitSql($this->sampleSize)
            );
        }

        if (empty($rows)) {
            return [];
        }

        $detected = [];
        $columns = array_keys($rows[0]);

        foreach ($columns as $column) {
            $values = $this->collectColumnValues($rows, $column);

            // Если значений мало — пытаемся определить по имени колонки (fail-safe для PII).
            if (count($values) < self::MIN_VALUES_FOR_REGEX) {
                $byName = $this->detectByColumnName($column);
                if ($byName !== null) {
                    $detected[$column] = $byName;
                }
                continue;
            }

            $pattern = $this->detectColumnPattern($column, $values);
            if ($pattern !== null) {
                $detected[$column] = $pattern;
            }
        }

        $detected = $this->detectLinkedColumns($rows, $detected);
        $detected = $this->detectGenderColumns($rows, $detected);

        return $detected;
    }

    /**
     * SQL выборки строк без сортировки всей таблицы.
     *
     * PostgreSQL: TABLESAMPLE BERNOULLI(p) в пределах max_scan_rows, SYSTEM(p) на больших;
     * Oracle на больших — SAMPLE(p); MySQL на больших — голова таблицы. На небольших
     * MySQL/Oracle — прежняя случайная выборка, там она дёшева. Размер неизвестен — голова:
     * сортировать таблицу неизвестного размера на боевой базе нельзя.
     *
     * @param mixed $platform
     * @param mixed $connection
     */
    private function buildSampleSql($platform, $connection, string $schema, string $table, ?int $rowCount): string
    {
        $fullTable = $platform->getFullTableName($schema, $table);
        $platformName = PlatformFactory::canonicalize($connection->getPlatformName());
        $limitSql = $platform->getLimitSql($this->sampleSize);

        if ($rowCount === null) {
            return "SELECT * FROM {$fullTable} {$limitSql}";
        }

        $maxScan = $this->policy !== null ? $this->policy->getMaxScanRows() : SafeQueryPolicy::DEFAULT_MAX_SCAN_ROWS;
        $large = $rowCount > $maxScan;

        if ($platformName === PlatformFactory::POSTGRESQL) {
            $method = $large ? 'SYSTEM' : 'BERNOULLI';
            $percent = $this->samplePercent($rowCount, $large ? 99.9 : 100.0);

            return "SELECT * FROM {$fullTable} TABLESAMPLE {$method} ({$percent}) {$limitSql}";
        }

        if ($large) {
            if ($platformName === PlatformFactory::ORACLE) {
                return "SELECT * FROM {$fullTable} SAMPLE (" . $this->samplePercent($rowCount, 99.9) . ") {$limitSql}";
            }

            return "SELECT * FROM {$fullTable} {$limitSql}";
        }

        return "SELECT * FROM {$fullTable} ORDER BY " . $platform->getRandomFunctionSql() . " {$limitSql}";
    }

    /**
     * Процент выборки: ~3×sampleSize строк из rowCount, зажатый в (0.01 .. $max), без научной
     * нотации — уходит в SQL как литерал.
     */
    private function samplePercent(int $rowCount, float $max): string
    {
        $percent = 100.0 * 3 * $this->sampleSize / max(1, $rowCount);
        $percent = max(0.01, min($max, $percent));
        $s = rtrim(rtrim(sprintf('%.6f', $percent), '0'), '.');

        return $s === '' ? '0' : $s;
    }

    /**
     * Fallback: определить паттерн по имени колонки.
     */
    private function detectByColumnName(string $column): ?string
    {
        if ($this->columnMatchesAny($column, self::COLUMN_HINTS_FIO)) {
            return self::PATTERN_FIO;
        }
        if ($this->columnMatchesAny($column, self::COLUMN_HINTS_EMAIL)) {
            return self::PATTERN_EMAIL;
        }
        if ($this->columnMatchesAny($column, self::COLUMN_HINTS_PHONE)) {
            return self::PATTERN_PHONE;
        }
        if ($this->columnMatchesAny($column, self::COLUMN_HINTS_FIRSTNAME)) {
            return self::PATTERN_FIRSTNAME;
        }
        if ($this->columnMatchesAny($column, self::COLUMN_HINTS_LASTNAME)) {
            return self::PATTERN_LASTNAME;
        }
        if ($this->columnMatchesAny($column, self::COLUMN_HINTS_PATRONYMIC)) {
            return self::PATTERN_PATRONYMIC;
        }
        if ($this->columnMatchesAny($column, self::COLUMN_HINTS_GENDER)) {
            return self::PATTERN_GENDER;
        }
        return null;
    }

    /**
     * @param array<int, string> $regexes
     */
    private function columnMatchesAny(string $column, array $regexes): bool
    {
        foreach ($regexes as $regex) {
            if (preg_match($regex, $column)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, string>
     */
    private function collectColumnValues(array $rows, string $column): array
    {
        $values = [];
        foreach ($rows as $row) {
            if (!array_key_exists($column, $row)) {
                continue;
            }
            $v = $row[$column];
            if ($v === null || $v === '') {
                continue;
            }
            $s = (string) $v;
            // Нормализуем non-breaking spaces, чтобы FIO regex срабатывал
            $s = str_replace(["\xc2\xa0"], ' ', $s);
            $s = preg_replace('/\s+/u', ' ', $s);
            $values[] = $s !== null ? $s : '';
        }
        return $values;
    }

    /**
     * Определить паттерн по значениям + подсказке имени колонки.
     *
     * @param array<int, string> $values
     */
    private function detectColumnPattern(string $column, array $values): ?string
    {
        $total = count($values);
        if ($total === 0) {
            return null;
        }

        $tests = [
            self::PATTERN_EMAIL => function (string $v) {
                return (bool) preg_match(self::REGEX_EMAIL, trim($v));
            },
            self::PATTERN_PHONE => function (string $v) {
                $cleaned = preg_replace('/[^\d]/', '', $v);
                return $cleaned !== null && $cleaned !== '' && preg_match(self::REGEX_PHONE_INTL, $cleaned);
            },
            self::PATTERN_FIO => function (string $v) {
                return (bool) preg_match(self::REGEX_FIO, trim($v));
            },
            self::PATTERN_FIO_SHORT => function (string $v) {
                return (bool) preg_match(self::REGEX_FIO_SHORT, trim($v));
            },
            self::PATTERN_NAME => function (string $v) {
                return (bool) preg_match(self::REGEX_NAME, trim($v));
            },
        ];

        $hasNameHint = $this->columnMatchesAny($column, self::COLUMN_HINTS_FIO);

        foreach ($tests as $patternName => $test) {
            $matches = 0;
            foreach ($values as $value) {
                if ($test($value)) {
                    $matches++;
                }
            }
            $ratio = $matches / $total;
            if ($ratio >= self::DETECTION_THRESHOLD) {
                // PATTERN_NAME требует подсказку имени колонки — иначе слишком много FP
                // (города типа "Нижний Новгород", названия товаров и т.д.)
                if ($patternName === self::PATTERN_NAME && !$hasNameHint) {
                    continue;
                }
                return $patternName;
            }
        }

        return null;
    }

    /**
     * Обнаруживает колонки-компоненты ФИО.
     *
     * Логика: для каждого нераспознанного столбца, если >80% значений — одиночные слова:
     *   а) если есть composite (fio/name) — коррелируем с ним;
     *   б) иначе — определяем роль ТОЛЬКО по имени колонки (firstname/lastname/patronymic).
     *
     * @param array<int, array<string, mixed>> $rows
     * @param array<string, string> $detected
     * @return array<string, string>
     */
    private function detectLinkedColumns(array $rows, array $detected): array
    {
        if (empty($rows)) {
            return $detected;
        }

        $compositeColumns = [];
        foreach ($detected as $column => $pattern) {
            if ($pattern === self::PATTERN_NAME || $pattern === self::PATTERN_FIO) {
                $compositeColumns[] = $column;
            }
        }

        $columns = array_keys($rows[0]);

        foreach ($columns as $column) {
            if (isset($detected[$column])) {
                continue;
            }

            $values = $this->collectColumnValues($rows, $column);
            if (count($values) < self::MIN_VALUES_FOR_REGEX) {
                continue;
            }

            // Проверка: >80% значений — одиночные слова (имена)
            $singleWordCount = 0;
            foreach ($values as $value) {
                if (preg_match(self::REGEX_SINGLE_WORD, trim($value))) {
                    $singleWordCount++;
                }
            }
            $ratio = $singleWordCount / count($values);
            if ($ratio < self::DETECTION_THRESHOLD) {
                continue;
            }

            // (а) Коррелируем с composite columns, если есть
            $matchedViaCorrelation = false;
            foreach ($compositeColumns as $compositeColumn) {
                $matchCount = 0;
                $comparedCount = 0;

                foreach ($rows as $row) {
                    $cellValue = isset($row[$column]) ? $row[$column] : null;
                    $compValue = isset($row[$compositeColumn]) ? $row[$compositeColumn] : null;
                    if ($cellValue === null || $cellValue === '' || $compValue === null || $compValue === '') {
                        continue;
                    }
                    $comparedCount++;
                    $value = trim((string) $cellValue);
                    $words = preg_split('/\s+/u', (string) $compValue) ?: [];
                    if (in_array($value, $words, true)) {
                        $matchCount++;
                    }
                }

                if ($comparedCount >= self::MIN_VALUES_FOR_REGEX
                    && ($matchCount / $comparedCount) >= self::DETECTION_THRESHOLD) {
                    $detected[$column] = $this->classifyNameRole($column, $values);
                    $matchedViaCorrelation = true;
                    break;
                }
            }

            // (б) Если correlation не сработала, но имя колонки явно намекает на роль — назначаем по имени.
            if (!$matchedViaCorrelation) {
                $role = $this->classifyByColumnNameOnly($column);
                if ($role !== null) {
                    $detected[$column] = $role;
                }
            }
        }

        return $detected;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @param array<string, string> $detected
     * @return array<string, string>
     */
    private function detectGenderColumns(array $rows, array $detected): array
    {
        if (empty($rows)) {
            return $detected;
        }

        $columns = array_keys($rows[0]);
        foreach ($columns as $column) {
            if (isset($detected[$column])) {
                continue;
            }
            if (!$this->columnMatchesAny($column, self::COLUMN_HINTS_GENDER)) {
                continue;
            }

            $values = $this->collectColumnValues($rows, $column);
            if (count($values) < self::MIN_VALUES_FOR_REGEX) {
                // Имя колонки совпадает, но мало данных — назначаем gender по имени (fail-safe)
                $detected[$column] = self::PATTERN_GENDER;
                continue;
            }

            $matchCount = 0;
            foreach ($values as $value) {
                $normalized = mb_strtolower(trim($value));
                if (in_array($normalized, self::GENDER_VALUES, true)) {
                    $matchCount++;
                }
            }
            if (($matchCount / count($values)) >= self::DETECTION_THRESHOLD) {
                $detected[$column] = self::PATTERN_GENDER;
            }
        }

        return $detected;
    }

    /**
     * @param array<int, string> $values
     */
    private function classifyNameRole(string $column, array $values): string
    {
        $byName = $this->classifyByColumnNameOnly($column);
        if ($byName !== null) {
            return $byName;
        }

        $patronymicCount = 0;
        $lastnameCount = 0;
        $total = count($values);

        foreach ($values as $value) {
            $trimmed = trim($value);
            if (preg_match(self::REGEX_PATRONYMIC_SUFFIX, $trimmed)) {
                $patronymicCount++;
            } elseif (preg_match(self::REGEX_LASTNAME_SUFFIX, $trimmed)) {
                $lastnameCount++;
            }
        }

        if ($total > 0 && ($patronymicCount / $total) > 0.50) {
            return self::PATTERN_PATRONYMIC;
        }
        if ($total > 0 && ($lastnameCount / $total) > 0.50) {
            return self::PATTERN_LASTNAME;
        }

        return self::PATTERN_FIRSTNAME;
    }

    private function classifyByColumnNameOnly(string $column): ?string
    {
        if ($this->columnMatchesAny($column, self::COLUMN_HINTS_PATRONYMIC)) {
            return self::PATTERN_PATRONYMIC;
        }
        if ($this->columnMatchesAny($column, self::COLUMN_HINTS_LASTNAME)) {
            return self::PATTERN_LASTNAME;
        }
        if ($this->columnMatchesAny($column, self::COLUMN_HINTS_FIRSTNAME)) {
            return self::PATTERN_FIRSTNAME;
        }
        return null;
    }
}
