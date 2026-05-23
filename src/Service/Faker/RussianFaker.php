<?php

namespace Timbrs\DatabaseDumps\Service\Faker;

use Timbrs\DatabaseDumps\Contract\FakerInterface;

/**
 * Заменяет персональные данные на сгенерированные русские ФИО, email, телефоны, gender.
 *
 * Особенности безопасности и корректности:
 * - PRNG локальный (mt_srand НЕ вызывается, чтобы не ломать глобальный PRNG процесса).
 *   Внутренний детерминированный генератор seedRng/nextInt не зависит от глобального состояния.
 * - Для уникальности email/телефонов используется не только seed ФИО, но и хеш всех ключевых
 *   значений строки (включая PK, если он есть) — снижает риск UNIQUE-коллизий при дубликатах ФИО.
 * - Пустые строки и NULL — НЕ маскируются (нет смысла маскировать «нет данных»).
 * - email генерируется с числовым суффиксом из 6 цифр (а не из 3), что снижает вероятность
 *   коллизий в ~1000 раз.
 */
class RussianFaker implements FakerInterface
{
    /** @var array<string> Паттерны-якоря (определяют «человека» в группе) */
    private const ANCHOR_PATTERNS = [
        PatternDetector::PATTERN_FIO,
        PatternDetector::PATTERN_NAME,
    ];

    /** @var array<string> RFC 2606 зарезервированные домены — не пересекаются с реальными */
    private const EMAIL_DOMAINS = ['example.com', 'example.org', 'example.net', 'example.test'];

    /** @var array<string, string> */
    private const TRANSLIT_MAP = [
        'а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'g', 'д' => 'd', 'е' => 'e', 'ё' => 'yo',
        'ж' => 'zh', 'з' => 'z', 'и' => 'i', 'й' => 'y', 'к' => 'k', 'л' => 'l', 'м' => 'm',
        'н' => 'n', 'о' => 'o', 'п' => 'p', 'р' => 'r', 'с' => 's', 'т' => 't', 'у' => 'u',
        'ф' => 'f', 'х' => 'kh', 'ц' => 'ts', 'ч' => 'ch', 'ш' => 'sh', 'щ' => 'shch',
        'ъ' => '', 'ы' => 'y', 'ь' => '', 'э' => 'e', 'ю' => 'yu', 'я' => 'ya',
    ];

    /** @var array<string, array<string>> Карта форматов гендера: lowercase → [male_variant, female_variant] */
    private const GENDER_MAP = [
        'male' => ['male', 'female'],
        'female' => ['male', 'female'],
        'm' => ['m', 'f'],
        'f' => ['m', 'f'],
        'м' => ['м', 'ж'],
        'ж' => ['м', 'ж'],
        'мужской' => ['мужской', 'женский'],
        'женский' => ['мужской', 'женский'],
        'муж' => ['муж', 'жен'],
        'жен' => ['муж', 'жен'],
        'мужчина' => ['мужчина', 'женщина'],
        'женщина' => ['мужчина', 'женщина'],
        '1' => ['1', '0'],
        '0' => ['1', '0'],
        '2' => ['1', '2'], // ISO 5218: 1=male, 2=female
        'true' => ['true', 'false'],
        'false' => ['true', 'false'],
        'y' => ['y', 'n'],
        'n' => ['y', 'n'],
        'man' => ['man', 'woman'],
        'woman' => ['man', 'woman'],
    ];

    /** @var array<string> */
    private static $LAST_NAMES_MALE_BASE = [
        'Иванов', 'Петров', 'Сидоров', 'Козлов', 'Новиков',
        'Морозов', 'Волков', 'Лебедев', 'Семёнов', 'Егоров',
        'Павлов', 'Степанов', 'Николаев', 'Орлов',
        'Андреев', 'Макаров', 'Никитин', 'Захаров', 'Зайцев',
        'Соловьёв', 'Борисов', 'Яковлев', 'Григорьев', 'Романов',
        'Воробьёв', 'Сергеев', 'Кузнецов', 'Фролов', 'Александров',
        'Дмитриев', 'Королёв', 'Гусев', 'Киселёв', 'Ильин',
        'Максимов', 'Поляков', 'Сорокин', 'Виноградов', 'Ковалёв',
        'Белов', 'Медведев', 'Антонов', 'Тарасов', 'Жуков',
        'Баранов', 'Филиппов', 'Комаров', 'Давыдов', 'Беляев',
        'Герасимов', 'Богданов', 'Осипов', 'Матвеев',
        'Титов', 'Марков', 'Миронов', 'Крылов', 'Куликов',
        'Карпов', 'Власов', 'Мельников', 'Денисов', 'Гаврилов',
        'Тихонов', 'Казаков', 'Афанасьев', 'Данилов', 'Пономарёв',
        'Калинин', 'Кириллов', 'Клименко', 'Ефимов', 'Лазарев',
        'Суворов', 'Чернов', 'Рябов', 'Поликарпов', 'Субботин',
        'Шилов', 'Устинов', 'Большаков', 'Савин', 'Панов',
        'Рыбаков', 'Суханов', 'Широков', 'Кудрявцев', 'Прохоров',
        'Наумов', 'Потапов', 'Журавлёв', 'Овчинников', 'Трофимов',
        'Леонов', 'Соболев', 'Ермаков', 'Колесников', 'Гончаров',
        'Хакимов', 'Сафиуллин', 'Хуснуллин', 'Шарипов', 'Нуриев',
        'Ахметов', 'Галимов', 'Фахрутдинов', 'Мухаметов', 'Валиев',
        'Хасанов', 'Рахимов', 'Закиров', 'Шайхутдинов', 'Сабиров',
        'Гильманов', 'Низамов', 'Ибрагимов', 'Юнусов', 'Загретдинов',
        'Мингазов', 'Насыров', 'Фаттахов', 'Нигматуллин', 'Гайнуллин',
        'Миннуллин', 'Шакиров', 'Камалов', 'Зиганшин', 'Ахмадуллин',
        'Гарифуллин', 'Мустафин', 'Латыпов', 'Бикбаев', 'Сулейманов',
        'Абдуллин', 'Якупов', 'Газизов', 'Тимергалиев', 'Юсупов',
    ];

    /** @var array<string> */
    private static $FIRST_NAMES_MALE = [
        'Иван', 'Пётр', 'Александр', 'Дмитрий', 'Сергей',
        'Андрей', 'Алексей', 'Максим', 'Михаил', 'Николай',
        'Владимир', 'Евгений', 'Виктор', 'Олег', 'Артём',
        'Роман', 'Даниил', 'Кирилл', 'Денис', 'Игорь',
        'Антон', 'Вадим', 'Юрий', 'Павел', 'Василий',
        'Борис', 'Григорий', 'Тимур', 'Руслан', 'Константин',
        'Фёдор', 'Степан', 'Геннадий', 'Леонид', 'Валерий',
        'Анатолий', 'Виталий', 'Аркадий', 'Семён', 'Марк',
        'Глеб', 'Тимофей', 'Матвей', 'Лев', 'Егор',
        'Ярослав', 'Станислав', 'Вячеслав', 'Филипп', 'Эдуард',
        'Георгий', 'Владислав', 'Захар', 'Богдан', 'Арсений',
        'Илья', 'Никита', 'Савелий', 'Платон', 'Макар',
        'Рустам', 'Ринат', 'Рафаэль', 'Ильдар', 'Ильнур',
        'Айрат', 'Айдар', 'Булат', 'Марат', 'Рамиль',
        'Радик', 'Ильгиз', 'Азат', 'Наиль', 'Фарит',
        'Рашит', 'Равиль', 'Салават', 'Тагир', 'Фанис',
    ];

    /** @var array<string> */
    private static $FIRST_NAMES_FEMALE = [
        'Анна', 'Мария', 'Елена', 'Ольга', 'Наталья',
        'Ирина', 'Татьяна', 'Светлана', 'Екатерина', 'Юлия',
        'Марина', 'Валентина', 'Галина', 'Людмила', 'Надежда',
        'Вера', 'Любовь', 'Алина', 'Дарья', 'Виктория',
        'Полина', 'Софья', 'Ксения', 'Кристина', 'Диана',
        'Алёна', 'Оксана', 'Жанна', 'Лариса', 'Тамара',
        'Нина', 'Инна', 'Раиса', 'Зинаида', 'Клавдия',
        'Лидия', 'Антонина', 'Маргарита', 'Евгения', 'Валерия',
        'Милана', 'Варвара', 'Василиса', 'Ева', 'Агата',
        'Злата', 'Вероника', 'Камилла', 'Арина', 'Ульяна',
        'Гульнара', 'Альфия', 'Алия', 'Айгуль', 'Гузель',
        'Зульфия', 'Фарида', 'Венера', 'Нурия', 'Разиля',
        'Миляуша', 'Ландыш', 'Зилия', 'Наиля', 'Рамиля',
        'Ильсия', 'Гульназ', 'Чулпан', 'Зухра', 'Алсу',
    ];

    /** @var array<string> */
    private static $PATRONYMICS_MALE = [
        'Иванович', 'Петрович', 'Александрович', 'Дмитриевич', 'Сергеевич',
        'Андреевич', 'Алексеевич', 'Максимович', 'Михайлович', 'Николаевич',
        'Владимирович', 'Евгеньевич', 'Викторович', 'Олегович', 'Артёмович',
        'Романович', 'Даниилович', 'Кириллович', 'Денисович', 'Игоревич',
        'Антонович', 'Вадимович', 'Юрьевич', 'Павлович', 'Васильевич',
        'Борисович', 'Григорьевич', 'Тимурович', 'Русланович', 'Константинович',
        'Фёдорович', 'Степанович', 'Геннадьевич', 'Леонидович', 'Валерьевич',
        'Анатольевич', 'Витальевич', 'Аркадьевич', 'Семёнович', 'Маркович',
        'Рустамович', 'Ринатович', 'Ильдарович', 'Айратович', 'Булатович',
        'Маратович', 'Рамилевич', 'Азатович', 'Равилевич', 'Салаватович',
    ];

    /**
     * @inheritDoc
     */
    public function apply(string $schema, string $table, array $fakerConfig, array $rows): array
    {
        if (empty($rows)) {
            return $rows;
        }

        $groups = $this->buildColumnGroups($fakerConfig);

        foreach ($rows as &$row) {
            foreach ($groups as $group) {
                $this->applyGroup($group, $row);
            }
        }
        unset($row);

        return $rows;
    }

    /**
     * Создать женскую фамилию из мужской.
     *
     * Правила (упрощённо):
     *  - Оканчивающиеся на -ых, -их (Седых, Долгих) — несклоняемые.
     *  - Оканчивающиеся на согласный + ь (Кушнир, Лебедь) — несклоняемые (фактически
     *    в разговорной практике могут менять окончание, но безопаснее не трогать).
     *  - -ский → -ская, -ой → -ая.
     *  - -ов/-ев/-ёв/-ин/-ын → +а.
     *  - Остальные русские — оставляем как есть (без -а), чтобы не сломать
     *    "не русские" фамилии типа Бегин, Гогенцоллерн и т.п.
     */
    private function feminizeLastName(string $male): string
    {
        if ($male === '') {
            return $male;
        }
        $len = mb_strlen($male);
        $lastChar = mb_substr($male, -1);

        // Несклоняемые: -ых, -их
        if (in_array(mb_substr($male, -2), ['ых', 'их'], true)) {
            return $male;
        }
        // Уже женская: -а, -я
        if (in_array($lastChar, ['а', 'я'], true)) {
            return $male;
        }
        // Несклоняемые: мягкий знак (Лебедь, Кушнир и т.п.)
        if ($lastChar === 'ь') {
            return $male;
        }
        // -ский → -ская, -цкий → -цкая
        if (mb_substr($male, -3) === 'кий') {
            return mb_substr($male, 0, $len - 2) . 'ая';
        }
        // -ой → -ая
        if (mb_substr($male, -2) === 'ой') {
            return mb_substr($male, 0, $len - 2) . 'ая';
        }
        // -ов/-ев/-ёв/-ин/-ын → +а
        if (in_array(mb_substr($male, -2), ['ов', 'ев', 'ёв', 'ин', 'ын'], true)) {
            return $male . 'а';
        }
        // Остальные — оставляем без изменений (безопасно)
        return $male;
    }

    /**
     * Создать женское отчество из мужского (-ович → -овна, -евич → -евна, -ич → -на).
     */
    private function feminizePatronymic(string $male): string
    {
        if (mb_substr($male, -3) === 'вич') {
            return mb_substr($male, 0, mb_strlen($male) - 2) . 'на';
        }
        if (mb_substr($male, -2) === 'ич') {
            return mb_substr($male, 0, mb_strlen($male) - 1) . 'чна';
        }
        return $male . 'на';
    }

    /**
     * @param array<string, string> $fakerConfig
     * @return array<array{anchor: string|null, columns: array<string, string>}>
     */
    private function buildColumnGroups(array $fakerConfig): array
    {
        $anchors = [];
        $others = [];

        foreach ($fakerConfig as $column => $patternType) {
            if (in_array($patternType, self::ANCHOR_PATTERNS, true)) {
                $anchors[$column] = $patternType;
            } else {
                $others[$column] = $patternType;
            }
        }

        if (count($anchors) <= 1) {
            $anchorColumn = !empty($anchors) ? key($anchors) : null;
            return [
                ['anchor' => $anchorColumn, 'columns' => $fakerConfig],
            ];
        }

        // Группируем по общим токенам префикса (split по '_'),
        // что устойчивее, чем по символам.
        $groups = [];
        foreach ($anchors as $anchorCol => $anchorPattern) {
            $groups[$anchorCol] = [
                'anchor' => $anchorCol,
                'columns' => [$anchorCol => $anchorPattern],
            ];
        }

        $ungrouped = [];
        foreach ($others as $column => $patternType) {
            $bestAnchor = null;
            $bestTokens = 0;

            foreach ($anchors as $anchorCol => $_) {
                $tokens = $this->commonTokenPrefix($column, $anchorCol);
                if ($tokens > $bestTokens) {
                    $bestTokens = $tokens;
                    $bestAnchor = $anchorCol;
                }
            }

            if ($bestAnchor !== null && $bestTokens > 0) {
                $groups[$bestAnchor]['columns'][$column] = $patternType;
            } else {
                $ungrouped[$column] = $patternType;
            }
        }

        $result = array_values($groups);

        if (!empty($ungrouped)) {
            $result[] = ['anchor' => null, 'columns' => $ungrouped];
        }

        return $result;
    }

    /**
     * Кол-во общих токенов начальной части (по '_').
     */
    private function commonTokenPrefix(string $a, string $b): int
    {
        $ta = explode('_', $a);
        $tb = explode('_', $b);
        $count = 0;
        $max = min(count($ta), count($tb));
        for ($i = 0; $i < $max; $i++) {
            if ($ta[$i] !== $tb[$i]) {
                break;
            }
            $count++;
        }
        return $count;
    }

    /**
     * Применить faker к одной группе колонок в строке.
     *
     * Используются ДВА seed'а:
     *  - $nameState — детерминирован от ФИО (одинаковое ФИО → одинаковая замена ФИО).
     *  - $unique State — детерминирован от ФИО + PK (одинаковое ФИО, разный PK →
     *    разные email/phone, защита от UNIQUE-коллизий).
     *
     * @param array{anchor: string|null, columns: array<string, string>} $group
     * @param array<string, mixed> &$row
     */
    private function applyGroup(array $group, array &$row): void
    {
        $columns = $group['columns'];

        $fioColumn = null;
        foreach ($columns as $column => $patternType) {
            if ($patternType === PatternDetector::PATTERN_FIO) {
                $fioColumn = $column;
                break;
            }
        }

        // Базовый seed: ФИО (если есть) или все faker-значения. Не зависит от PK.
        $nameSeedParts = [];
        if ($fioColumn !== null && isset($row[$fioColumn])) {
            $nameSeedParts[] = (string) $row[$fioColumn];
        } else {
            foreach ($columns as $column => $_) {
                $nameSeedParts[] = isset($row[$column]) ? (string) $row[$column] : '';
            }
        }
        $nameState = crc32(implode("\0", $nameSeedParts));

        // Уникальный seed для email/phone: добавляем PK (первое поле строки)
        $uniqueSeedParts = $nameSeedParts;
        if (!empty($row)) {
            $firstKey = array_key_first($row);
            $uniqueSeedParts[] = (string) ($row[$firstKey] ?? '');
        }
        $uniqueState = crc32(implode("\0", $uniqueSeedParts));

        $gender = $this->nextInt($nameState, 0, 1);

        $lastMaleBase = self::$LAST_NAMES_MALE_BASE;
        $lastBase = $lastMaleBase[$this->nextInt($nameState, 0, count($lastMaleBase) - 1)];
        $lastName = $gender ? $this->feminizeLastName($lastBase) : $lastBase;

        $firstList = $gender ? self::$FIRST_NAMES_FEMALE : self::$FIRST_NAMES_MALE;
        $firstName = $firstList[$this->nextInt($nameState, 0, count($firstList) - 1)];

        $patronymicMale = self::$PATRONYMICS_MALE;
        $patrBase = $patronymicMale[$this->nextInt($nameState, 0, count($patronymicMale) - 1)];
        $patronymic = $gender ? $this->feminizePatronymic($patrBase) : $patrBase;

        foreach ($columns as $column => $patternType) {
            if (!isset($row[$column]) || $row[$column] === '' || $row[$column] === null) {
                continue;
            }

            $original = $row[$column];

            switch ($patternType) {
                case PatternDetector::PATTERN_FIO:
                    $row[$column] = $lastName . ' ' . $firstName . ' ' . $patronymic;
                    break;
                case PatternDetector::PATTERN_FIO_SHORT:
                    $row[$column] = $lastName . ' '
                        . mb_substr($firstName, 0, 1) . '.'
                        . mb_substr($patronymic, 0, 1) . '.';
                    break;
                case PatternDetector::PATTERN_NAME:
                    $row[$column] = $lastName . ' ' . $firstName;
                    break;
                case PatternDetector::PATTERN_EMAIL:
                    $row[$column] = $this->generateEmail($firstName, $lastName, $uniqueState);
                    break;
                case PatternDetector::PATTERN_PHONE:
                    $row[$column] = $this->generatePhone((string) $original, $uniqueState);
                    break;
                case PatternDetector::PATTERN_FIRSTNAME:
                    $row[$column] = $firstName;
                    break;
                case PatternDetector::PATTERN_LASTNAME:
                    $row[$column] = $lastName;
                    break;
                case PatternDetector::PATTERN_PATRONYMIC:
                    $row[$column] = $patronymic;
                    break;
                case PatternDetector::PATTERN_GENDER:
                    $row[$column] = $this->generateGender($gender, (string) $original);
                    break;
            }
        }
    }

    private function generateEmail(string $firstName, string $lastName, int &$state): string
    {
        $translitFirst = $this->transliterate(mb_strtolower($firstName));
        $translitLast = $this->transliterate(mb_strtolower($lastName));
        $domain = self::EMAIL_DOMAINS[$this->nextInt($state, 0, count(self::EMAIL_DOMAINS) - 1)];
        // 6-значный суффикс снижает вероятность UNIQUE-коллизий
        $num = $this->nextInt($state, 100000, 999999);

        return $translitFirst . '.' . $translitLast . $num . '@' . $domain;
    }

    private function generatePhone(string $originalPhone, int &$state): string
    {
        // 10 цифр номера: 9 + 9 случайных
        $rand9 = str_pad((string) $this->nextInt($state, 0, 999999999), 9, '0', STR_PAD_LEFT);
        $newDigits = '9' . $rand9;

        if ($originalPhone === '') {
            return '7' . $newDigits;
        }

        $template = preg_replace('/\d/', '#', $originalPhone);
        $placeholderCount = substr_count($template, '#');

        if ($placeholderCount === 11) {
            preg_match('/\d/', $originalPhone, $m);
            $allDigits = (isset($m[0]) ? $m[0] : '7') . $newDigits;
        } elseif ($placeholderCount === 10) {
            $allDigits = $newDigits;
        } else {
            return '7' . $newDigits;
        }

        $result = '';
        $digitIndex = 0;
        for ($i = 0, $len = strlen($template); $i < $len; $i++) {
            if ($template[$i] === '#') {
                $result .= $allDigits[$digitIndex];
                $digitIndex++;
            } else {
                $result .= $template[$i];
            }
        }

        return $result;
    }

    private function generateGender(int $gender, string $originalValue): string
    {
        $normalized = mb_strtolower(trim($originalValue));

        if (!isset(self::GENDER_MAP[$normalized])) {
            return $originalValue;
        }

        $pair = self::GENDER_MAP[$normalized];
        $replacement = $pair[$gender];

        return $this->matchCase($replacement, trim($originalValue));
    }

    private function matchCase(string $value, string $reference): string
    {
        if (mb_strlen($reference) > 1 && mb_strtoupper($reference) === $reference) {
            return mb_strtoupper($value);
        }

        $firstChar = mb_substr($reference, 0, 1);
        if (mb_strtoupper($firstChar) === $firstChar) {
            return mb_strtoupper(mb_substr($value, 0, 1)) . mb_substr($value, 1);
        }

        return $value;
    }

    private function transliterate(string $text): string
    {
        return strtr($text, self::TRANSLIT_MAP);
    }

    /**
     * Локальный детерминированный PRNG (linear congruential, не криптографический).
     * Хранит state в передаваемой по ссылке переменной — не трогает глобальный mt_rand.
     */
    private function nextInt(int &$state, int $min, int $max): int
    {
        // Хорошие LCG-константы (Numerical Recipes)
        $state = (int) ((($state * 1103515245) + 12345) & 0x7fffffff);
        $range = $max - $min + 1;
        if ($range <= 0) {
            return $min;
        }
        return $min + ($state % $range);
    }
}
