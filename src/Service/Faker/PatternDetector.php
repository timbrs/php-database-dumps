<?php

namespace Timbrs\DatabaseDumps\Service\Faker;

use Timbrs\DatabaseDumps\Contract\ConnectionRegistryInterface;
use Timbrs\DatabaseDumps\Platform\PlatformFactory;

/**
 * Определяет паттерны персональных данных в колонках таблицы
 * по выборке случайных строк (порог совпадения 80%).
 */
class PatternDetector
{
    /** @var string Фамилия Имя Отчество (3 кириллических слова) */
    public const PATTERN_FIO = 'fio';
    /** @var string Фамилия И.О. (сокращённое ФИО с инициалами) */
    public const PATTERN_FIO_SHORT = 'fio_short';
    /** @var string Фамилия Имя (2 кириллических слова) */
    public const PATTERN_NAME = 'name';
    /** @var string Email-адрес */
    public const PATTERN_EMAIL = 'email';
    /** @var string Телефонный номер */
    public const PATTERN_PHONE = 'phone';
    /** @var string Имя (одиночное, определяется кросс-корреляцией) */
    public const PATTERN_FIRSTNAME = 'firstname';
    /** @var string Фамилия (одиночная, определяется кросс-корреляцией) */
    public const PATTERN_LASTNAME = 'lastname';
    /** @var string Отчество (одиночное, определяется кросс-корреляцией) */
    public const PATTERN_PATRONYMIC = 'patronymic';
    /** @var string Пол/гендер (определяется по имени колонки + значениям) */
    public const PATTERN_GENDER = 'gender';

    /** @var int Размер выборки для анализа */
    public const SAMPLE_SIZE = 200;
    /** @var float Минимальная доля совпадений для детекции (80%) */
    public const DETECTION_THRESHOLD = 0.80;

    /** @var string */
    private const REGEX_EMAIL = '/^[a-zA-Z0-9._%+\\-]+@[a-zA-Z0-9.\\-]+\\.[a-zA-Z]{2,}$/u';
    /** @var string */
    private const REGEX_PHONE = '/^(?:\\+?[78])?[9]\\d{9}$/';
    /** @var string 3 кириллических слова (с поддержкой дефиса) */
    private const REGEX_FIO = '/^[А-ЯЁа-яё]+(?:\\-[А-ЯЁа-яё]+)?\\s+[А-ЯЁа-яё]+(?:\\-[А-ЯЁа-яё]+)?\\s+[А-ЯЁа-яё]+(?:\\-[А-ЯЁа-яё]+)?$/u';
    /** @var string Фамилия + 2 инициала с точками */
    private const REGEX_FIO_SHORT = '/^[А-ЯЁа-яё]+(?:\\-[А-ЯЁа-яё]+)?\\s+[А-ЯЁ]\\.\\s?[А-ЯЁ]\\.$/u';
    /** @var string 2 кириллических слова (с поддержкой дефиса) */
    private const REGEX_NAME = '/^[А-ЯЁа-яё]+(?:\\-[А-ЯЁа-яё]+)?\\s+[А-ЯЁа-яё]+(?:\\-[А-ЯЁа-яё]+)?$/u';
    /** @var string Одно кириллическое слово (с поддержкой дефиса) */
    private const REGEX_SINGLE_CYRILLIC_WORD = '/^[А-ЯЁа-яё]+(?:\\-[А-ЯЁа-яё]+)?$/u';
    /** @var string Суффиксы отчеств */
    private const REGEX_PATRONYMIC_SUFFIX = '/(ович|евич|ьич|овна|евна|ична|инична)$/u';
    /** @var string Суффиксы фамилий (без -ин/-ина из-за совпадений с женскими именами) */
    private const REGEX_LASTNAME_SUFFIX = '/(ов|ова|ев|ева|ёв|ёва|ский|ская|цкий|цкая|ых|их)$/u';

    /** @var array<string> Подсказки по имени колонки: имя */
    private const COLUMN_HINTS_FIRSTNAME = ['/first_?name/i', '/fname/i', '/given/i', '/имя/ui'];
    /** @var array<string> Подсказки по имени колонки: фамилия */
    private const COLUMN_HINTS_LASTNAME = ['/last_?name/i', '/lname/i', '/surname/i', '/family/i', '/фамилия/ui'];
    /** @var array<string> Подсказки по имени колонки: отчество */
    private const COLUMN_HINTS_PATRONYMIC = ['/patronym/i', '/middle_?name/i', '/отчество/ui'];
    /** @var array<string> Подсказки по имени колонки: пол */
    private const COLUMN_HINTS_GENDER = ['/gender/i', '/^gen$/i', '/^sex$/i', '/^пол$/ui'];

    /** @var array<string> Допустимые значения пола (lowercase) */
    private const GENDER_VALUES = [
        'male', 'female', 'm', 'f',
        'м', 'ж',
        'мужской', 'женский',
        'муж', 'жен',
        'мужчина', 'женщина',
    ];

    /** @var ConnectionRegistryInterface */
    private $registry;

    /** @var int */
    private $sampleSize;

    /**
     * @param ConnectionRegistryInterface $registry
     * @param int $sampleSize
     */
    public function __construct(ConnectionRegistryInterface $registry, $sampleSize = self::SAMPLE_SIZE)
    {
        $this->registry = $registry;
        $this->sampleSize = (int) $sampleSize;
    }

    /**
     * Анализирует колонки таблицы и возвращает обнаруженные паттерны ПД.
     *
     * @return array<string, string> column_name => pattern_type
     */
    public function detect(string $schema, string $table, ?string $connectionName = null): array
    {
        $connection = $this->registry->getConnection($connectionName);
        $platform = $this->registry->getPlatform($connectionName);

        $fullTable = $platform->getFullTableName($schema, $table);
        $randomFunc = $platform->getRandomFunctionSql();
        $platformName = $connection->getPlatformName();
        if ($platformName === PlatformFactory::ORACLE || $platformName === PlatformFactory::OCI) {
            $sql = "SELECT * FROM {$fullTable} ORDER BY {$randomFunc} FETCH FIRST " . $this->sampleSize . " ROWS ONLY";
        } else {
            $sql = "SELECT * FROM {$fullTable} ORDER BY {$randomFunc} LIMIT " . $this->sampleSize;
        }

        $rows = $connection->fetchAllAssociative($sql);

        if (empty($rows)) {
            return [];
        }

        $detected = [];
        $columns = array_keys($rows[0]);

        foreach ($columns as $column) {
            $values = [];
            foreach ($rows as $row) {
                if ($row[$column] !== null && $row[$column] !== '') {
                    $values[] = (string) $row[$column];
                }
            }

            if (count($values) < 10) {
                continue;
            }

            $pattern = $this->detectColumnPattern($values);
            if ($pattern !== null) {
                $detected[$column] = $pattern;
            }
        }

        $detected = $this->detectLinkedColumns($rows, $detected);
        $detected = $this->detectGenderColumns($rows, $detected);

        return $detected;
    }

    /**
     * Определяет паттерн ПД по массиву значений колонки.
     * Возвращает первый паттерн, превысивший порог совпадения.
     *
     * @param array<string> $values непустые значения колонки
     */
    private function detectColumnPattern(array $values): ?string
    {
        $total = count($values);
        $patterns = [
            self::PATTERN_EMAIL => self::REGEX_EMAIL,
            self::PATTERN_PHONE => null,  // special handling
            self::PATTERN_FIO => self::REGEX_FIO,
            self::PATTERN_FIO_SHORT => self::REGEX_FIO_SHORT,
            self::PATTERN_NAME => self::REGEX_NAME,
        ];

        foreach ($patterns as $patternName => $regex) {
            $matches = 0;

            foreach ($values as $value) {
                if ($patternName === self::PATTERN_PHONE) {
                    // Strip non-digits before matching
                    $cleaned = preg_replace('/[^\\d]/', '', $value);
                    if ($cleaned !== null && preg_match(self::REGEX_PHONE, $cleaned)) {
                        $matches++;
                    }
                } else {
                    $trimmed = trim($value);
                    if ($regex !== null && preg_match($regex, $trimmed)) {
                        $matches++;
                    }
                }
            }

            if ($total > 0 && ($matches / $total) >= self::DETECTION_THRESHOLD) {
                return $patternName;
            }
        }

        return null;
    }

    /**
     * Обнаруживает колонки-компоненты ФИО по кросс-корреляции с составными колонками.
     *
     * @param array<array<string, mixed>> $rows
     * @param array<string, string> $detected
     * @return array<string, string>
     */
    private function detectLinkedColumns(array $rows, array $detected): array
    {
        // Составные колонки (name или fio)
        $compositeColumns = [];
        foreach ($detected as $column => $pattern) {
            if ($pattern === self::PATTERN_NAME || $pattern === self::PATTERN_FIO) {
                $compositeColumns[] = $column;
            }
        }

        if (empty($compositeColumns) || empty($rows)) {
            return $detected;
        }

        $columns = array_keys($rows[0]);

        foreach ($columns as $column) {
            if (isset($detected[$column])) {
                continue;
            }

            // Собрать непустые значения
            $values = [];
            foreach ($rows as $row) {
                if ($row[$column] !== null && $row[$column] !== '') {
                    $values[] = (string) $row[$column];
                }
            }

            if (count($values) < 10) {
                continue;
            }

            // Проверить: >80% значений — одиночные кириллические слова
            $singleWordCount = 0;
            foreach ($values as $value) {
                if (preg_match(self::REGEX_SINGLE_CYRILLIC_WORD, trim($value))) {
                    $singleWordCount++;
                }
            }

            if (($singleWordCount / count($values)) < self::DETECTION_THRESHOLD) {
                continue;
            }

            // Кросс-корреляция с составными колонками
            foreach ($compositeColumns as $compositeColumn) {
                $matchCount = 0;
                $comparedCount = 0;

                foreach ($rows as $row) {
                    if ($row[$column] === null || $row[$column] === ''
                        || $row[$compositeColumn] === null || $row[$compositeColumn] === ''
                    ) {
                        continue;
                    }

                    $comparedCount++;
                    $value = trim((string) $row[$column]);
                    $compositeValue = (string) $row[$compositeColumn];

                    /** @var array<string> $words */
                    $words = preg_split('/\\s+/', $compositeValue);
                    if (in_array($value, $words, true)) {
                        $matchCount++;
                    }
                }

                if ($comparedCount >= 10 && ($matchCount / $comparedCount) >= self::DETECTION_THRESHOLD) {
                    $detected[$column] = $this->classifyNameRole($column, $values);
                    break;
                }
            }
        }

        return $detected;
    }

    /**
     * Обнаруживает колонки пола по совпадению имени колонки И значений.
     *
     * @param array<array<string, mixed>> $rows
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

            // Проверка имени колонки
            $columnMatches = false;
            foreach (self::COLUMN_HINTS_GENDER as $regex) {
                if (preg_match($regex, $column)) {
                    $columnMatches = true;
                    break;
                }
            }

            if (!$columnMatches) {
                continue;
            }

            // Собрать непустые значения
            $values = [];
            foreach ($rows as $row) {
                if ($row[$column] !== null && $row[$column] !== '') {
                    $values[] = (string) $row[$column];
                }
            }

            if (count($values) < 10) {
                continue;
            }

            // Проверка значений
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
     * Определяет роль колонки-компонента (firstname/lastname/patronymic).
     *
     * @param string $column имя колонки
     * @param array<string> $values непустые значения колонки
     */
    private function classifyNameRole(string $column, array $values): string
    {
        // Приоритет 1: эвристика по имени колонки
        foreach (self::COLUMN_HINTS_FIRSTNAME as $regex) {
            if (preg_match($regex, $column)) {
                return self::PATTERN_FIRSTNAME;
            }
        }
        foreach (self::COLUMN_HINTS_LASTNAME as $regex) {
            if (preg_match($regex, $column)) {
                return self::PATTERN_LASTNAME;
            }
        }
        foreach (self::COLUMN_HINTS_PATRONYMIC as $regex) {
            if (preg_match($regex, $column)) {
                return self::PATTERN_PATRONYMIC;
            }
        }

        // Приоритет 2: суффиксный анализ (порог >50%)
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

        // Приоритет 3: fallback
        return self::PATTERN_FIRSTNAME;
    }
}
