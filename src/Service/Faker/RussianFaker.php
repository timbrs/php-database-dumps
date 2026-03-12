<?php

namespace Timbrs\DatabaseDumps\Service\Faker;

use Timbrs\DatabaseDumps\Contract\FakerInterface;

/**
 * Заменяет персональные данные на сгенерированные русские ФИО, email, телефоны.
 * Детерминирован: seed по хешу ФИО (если есть колонка fio), иначе по комбинации всех faker-значений.
 */
class RussianFaker implements FakerInterface
{
    /** @var array<string> */
    private const LAST_NAMES_MALE = [
        'Иванов', 'Петров', 'Сидоров', 'Козлов', 'Новиков',
        'Морозов', 'Волков', 'Лебедев', 'Семёнов', 'Егоров',
        'Павлов', 'Козлов', 'Степанов', 'Николаев', 'Орлов',
        'Андреев', 'Макаров', 'Никитин', 'Захаров', 'Зайцев',
        'Соловьёв', 'Борисов', 'Яковлев', 'Григорьев', 'Романов',
        'Воробьёв', 'Сергеев', 'Кузнецов', 'Фролов', 'Александров',
        'Дмитриев', 'Королёв', 'Гусев', 'Киселёв', 'Ильин',
        'Максимов', 'Поляков', 'Сорокин', 'Виноградов', 'Ковалёв',
        'Белов', 'Медведев', 'Антонов', 'Тарасов', 'Жуков',
        'Баранов', 'Филиппов', 'Комаров', 'Давыдов', 'Беляев',
        'Герасимов', 'Богданов', 'Осипов', 'Сидоров', 'Матвеев',
        'Титов', 'Марков', 'Миронов', 'Крылов', 'Куликов',
        'Карпов', 'Власов', 'Мельников', 'Денисов', 'Гаврилов',
        'Тихонов', 'Казаков', 'Афанасьев', 'Данилов', 'Пономарёв',
        'Калинин', 'Кириллов', 'Клименко', 'Ефимов', 'Лазарев',
        'Суворов', 'Чернов', 'Рябов', 'Поликарпов', 'Субботин',
        'Шилов', 'Устинов', 'Большаков', 'Савин', 'Панов',
        'Рыбаков', 'Суханов', 'Широков', 'Кудрявцев', 'Прохоров',
        'Наумов', 'Потапов', 'Журавлёв', 'Овчинников', 'Трофимов',
        'Леонов', 'Соболев', 'Ермаков', 'Колесников', 'Гончаров',
        // Татарские и башкирские фамилии
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
    private const LAST_NAMES_FEMALE = [
        'Иванова', 'Петрова', 'Сидорова', 'Козлова', 'Новикова',
        'Морозова', 'Волкова', 'Лебедева', 'Семёнова', 'Егорова',
        'Павлова', 'Козлова', 'Степанова', 'Николаева', 'Орлова',
        'Андреева', 'Макарова', 'Никитина', 'Захарова', 'Зайцева',
        'Соловьёва', 'Борисова', 'Яковлева', 'Григорьева', 'Романова',
        'Воробьёва', 'Сергеева', 'Кузнецова', 'Фролова', 'Александрова',
        'Дмитриева', 'Королёва', 'Гусева', 'Киселёва', 'Ильина',
        'Максимова', 'Полякова', 'Сорокина', 'Виноградова', 'Ковалёва',
        'Белова', 'Медведева', 'Антонова', 'Тарасова', 'Жукова',
        'Баранова', 'Филиппова', 'Комарова', 'Давыдова', 'Беляева',
        'Герасимова', 'Богданова', 'Осипова', 'Сидорова', 'Матвеева',
        'Титова', 'Маркова', 'Миронова', 'Крылова', 'Куликова',
        'Карпова', 'Власова', 'Мельникова', 'Денисова', 'Гаврилова',
        'Тихонова', 'Казакова', 'Афанасьева', 'Данилова', 'Пономарёва',
        'Калинина', 'Кириллова', 'Клименко', 'Ефимова', 'Лазарева',
        'Суворова', 'Чернова', 'Рябова', 'Поликарпова', 'Субботина',
        'Шилова', 'Устинова', 'Большакова', 'Савина', 'Панова',
        'Рыбакова', 'Суханова', 'Широкова', 'Кудрявцева', 'Прохорова',
        'Наумова', 'Потапова', 'Журавлёва', 'Овчинникова', 'Трофимова',
        'Леонова', 'Соболева', 'Ермакова', 'Колесникова', 'Гончарова',
        // Татарские и башкирские фамилии
        'Хакимова', 'Сафиуллина', 'Хуснуллина', 'Шарипова', 'Нуриева',
        'Ахметова', 'Галимова', 'Фахрутдинова', 'Мухаметова', 'Валиева',
        'Хасанова', 'Рахимова', 'Закирова', 'Шайхутдинова', 'Сабирова',
        'Гильманова', 'Низамова', 'Ибрагимова', 'Юнусова', 'Загретдинова',
        'Мингазова', 'Насырова', 'Фаттахова', 'Нигматуллина', 'Гайнуллина',
        'Миннуллина', 'Шакирова', 'Камалова', 'Зиганшина', 'Ахмадуллина',
        'Гарифуллина', 'Мустафина', 'Латыпова', 'Бикбаева', 'Сулейманова',
        'Абдуллина', 'Якупова', 'Газизова', 'Тимергалиева', 'Юсупова',
    ];

    /** @var array<string> */
    private const FIRST_NAMES_MALE = [
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
        'Демид', 'Прохор', 'Мирон', 'Назар', 'Елисей',
        'Вениамин', 'Всеволод', 'Герман', 'Давид', 'Добрыня',
        'Емельян', 'Ефим', 'Лука', 'Потап', 'Радомир',
        'Святослав', 'Тихон', 'Трофим', 'Харитон', 'Ростислав',
        // Татарские и башкирские имена
        'Рустам', 'Ринат', 'Рафаэль', 'Ильдар', 'Ильнур',
        'Айрат', 'Айдар', 'Булат', 'Марат', 'Рамиль',
        'Радик', 'Ильгиз', 'Азат', 'Наиль', 'Фарит',
        'Рашит', 'Равиль', 'Салават', 'Тагир', 'Фанис',
        'Ильшат', 'Альберт', 'Дамир', 'Камиль', 'Рифат',
        'Зуфар', 'Шамиль', 'Ирек', 'Нияз', 'Фаниль',
        'Ильяс', 'Фарид', 'Рафик', 'Газинур', 'Ильфат',
        'Нурислам', 'Раиль', 'Ильсур', 'Байрас', 'Тимур',
    ];

    /** @var array<string> */
    private const FIRST_NAMES_FEMALE = [
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
        'Мирослава', 'Яна', 'Регина', 'Элина', 'Ангелина',
        'Таисия', 'Стефания', 'Серафима', 'Майя', 'Эмилия',
        'Каролина', 'Амелия', 'Аделина', 'Снежана', 'Влада',
        'Альбина', 'Пелагея', 'Лилия', 'Марта', 'Нелли',
        'Римма', 'Роза', 'Эльвира', 'Фаина', 'Аза',
        'Берта', 'Виолетта', 'Изабелла', 'Клара', 'Флора',
        // Татарские и башкирские имена
        'Гульнара', 'Альфия', 'Алия', 'Айгуль', 'Гузель',
        'Зульфия', 'Фарида', 'Венера', 'Нурия', 'Разиля',
        'Миляуша', 'Ландыш', 'Зилия', 'Наиля', 'Рамиля',
        'Ильсия', 'Гульназ', 'Чулпан', 'Зухра', 'Алсу',
        'Эльмира', 'Диляра', 'Лейла', 'Фирдаус', 'Ильмира',
        'Розалия', 'Айсылу', 'Рания', 'Динара', 'Резеда',
        'Лениза', 'Фания', 'Гулия', 'Рушания', 'Илюза',
        'Зарина', 'Зарема', 'Сирена', 'Лиана', 'Гульшат',
    ];

    /** @var array<string> */
    private const PATRONYMICS_MALE = [
        'Иванович', 'Петрович', 'Александрович', 'Дмитриевич', 'Сергеевич',
        'Андреевич', 'Алексеевич', 'Максимович', 'Михайлович', 'Николаевич',
        'Владимирович', 'Евгеньевич', 'Викторович', 'Олегович', 'Артёмович',
        'Романович', 'Даниилович', 'Кириллович', 'Денисович', 'Игоревич',
        'Антонович', 'Вадимович', 'Юрьевич', 'Павлович', 'Васильевич',
        'Борисович', 'Григорьевич', 'Тимурович', 'Русланович', 'Константинович',
        'Фёдорович', 'Степанович', 'Геннадьевич', 'Леонидович', 'Валерьевич',
        'Анатольевич', 'Витальевич', 'Аркадьевич', 'Семёнович', 'Маркович',
        'Глебович', 'Тимофеевич', 'Матвеевич', 'Львович', 'Егорович',
        'Ярославович', 'Станиславович', 'Вячеславович', 'Филиппович', 'Эдуардович',
        // Татарские и башкирские отчества
        'Рустамович', 'Ринатович', 'Ильдарович', 'Айратович', 'Булатович',
        'Маратович', 'Рамилевич', 'Азатович', 'Равилевич', 'Салаватович',
        'Фаритович', 'Рашитович', 'Наилевич', 'Фанисович', 'Ильшатович',
        'Дамирович', 'Камилевич', 'Шамилевич', 'Зуфарович', 'Рифатович',
        'Ильгизович', 'Ильнурович', 'Ильясович', 'Фаридович', 'Тимурович',
    ];

    /** @var array<string> */
    private const PATRONYMICS_FEMALE = [
        'Ивановна', 'Петровна', 'Александровна', 'Дмитриевна', 'Сергеевна',
        'Андреевна', 'Алексеевна', 'Максимовна', 'Михайловна', 'Николаевна',
        'Владимировна', 'Евгеньевна', 'Викторовна', 'Олеговна', 'Артёмовна',
        'Романовна', 'Данииловна', 'Кирилловна', 'Денисовна', 'Игоревна',
        'Антоновна', 'Вадимовна', 'Юрьевна', 'Павловна', 'Васильевна',
        'Борисовна', 'Григорьевна', 'Тимуровна', 'Руслановна', 'Константиновна',
        'Фёдоровна', 'Степановна', 'Геннадьевна', 'Леонидовна', 'Валерьевна',
        'Анатольевна', 'Витальевна', 'Аркадьевна', 'Семёновна', 'Марковна',
        'Глебовна', 'Тимофеевна', 'Матвеевна', 'Львовна', 'Егоровна',
        'Ярославовна', 'Станиславовна', 'Вячеславовна', 'Филипповна', 'Эдуардовна',
        // Татарские и башкирские отчества
        'Рустамовна', 'Ринатовна', 'Ильдаровна', 'Айратовна', 'Булатовна',
        'Маратовна', 'Рамилевна', 'Азатовна', 'Равилевна', 'Салаватовна',
        'Фаритовна', 'Рашитовна', 'Наилевна', 'Фанисовна', 'Ильшатовна',
        'Дамировна', 'Камилевна', 'Шамилевна', 'Зуфаровна', 'Рифатовна',
        'Ильгизовна', 'Ильнуровна', 'Ильясовна', 'Фаридовна', 'Тимуровна',
    ];

    /** @var array<string> */
    private const EMAIL_DOMAINS = ['example.com', 'test.ru', 'mail.test', 'demo.org'];

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
    ];

    /** @var array<string, string> */
    private const TRANSLIT_MAP = [
        'а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'g', 'д' => 'd', 'е' => 'e', 'ё' => 'yo',
        'ж' => 'zh', 'з' => 'z', 'и' => 'i', 'й' => 'y', 'к' => 'k', 'л' => 'l', 'м' => 'm',
        'н' => 'n', 'о' => 'o', 'п' => 'p', 'р' => 'r', 'с' => 's', 'т' => 't', 'у' => 'u',
        'ф' => 'f', 'х' => 'kh', 'ц' => 'ts', 'ч' => 'ch', 'ш' => 'sh', 'щ' => 'shch',
        'ъ' => '', 'ы' => 'y', 'ь' => '', 'э' => 'e', 'ю' => 'yu', 'я' => 'ya',
    ];

    /** @var array<string> Паттерны-якоря, содержащие составное ФИО (определяют «человека» в группе) */
    private const ANCHOR_PATTERNS = [
        PatternDetector::PATTERN_FIO,
        PatternDetector::PATTERN_NAME,
    ];

    /**
     * Заменяет ПД в строках данных согласно fakerConfig.
     * Колонки группируются по общему префиксу с якорем (fio/name).
     * Seed каждой группы: хеш от значения якоря, или от всех faker-значений группы.
     *
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
     * Сгруппировать колонки fakerConfig по общему префиксу с якорями (fio/name).
     *
     * Якорь — колонка с паттерном fio или name.
     * Остальные колонки привязываются к якорю, если имеют общий префикс (>0 символов).
     * Колонки без якоря и без общего префикса попадают в одну группу без якоря.
     *
     * @param array<string, string> $fakerConfig column => patternType
     * @return array<array{anchor: string|null, columns: array<string, string>}>
     */
    private function buildColumnGroups(array $fakerConfig): array
    {
        // Разделить на якоря и остальные
        /** @var array<string, string> $anchors column => patternType */
        $anchors = [];
        /** @var array<string, string> $others column => patternType */
        $others = [];

        foreach ($fakerConfig as $column => $patternType) {
            if (in_array($patternType, self::ANCHOR_PATTERNS, true)) {
                $anchors[$column] = $patternType;
            } else {
                $others[$column] = $patternType;
            }
        }

        // При <= 1 якоре — одна группа (BC-safe)
        if (count($anchors) <= 1) {
            $anchorColumn = !empty($anchors) ? key($anchors) : null;
            return [
                ['anchor' => $anchorColumn, 'columns' => $fakerConfig],
            ];
        }

        // Несколько якорей — группируем по commonPrefix
        /** @var array<string, array{anchor: string, columns: array<string, string>}> $groups */
        $groups = [];
        foreach ($anchors as $anchorCol => $anchorPattern) {
            $groups[$anchorCol] = [
                'anchor' => $anchorCol,
                'columns' => [$anchorCol => $anchorPattern],
            ];
        }

        // Привязать остальные колонки к якорю с наибольшим общим префиксом
        /** @var array<string, string> $ungrouped */
        $ungrouped = [];
        foreach ($others as $column => $patternType) {
            $bestAnchor = null;
            $bestPrefixLen = 0;

            foreach ($anchors as $anchorCol => $anchorPattern) {
                $prefix = $this->commonPrefix($column, $anchorCol);
                $prefixLen = strlen($prefix);
                if ($prefixLen > $bestPrefixLen) {
                    $bestPrefixLen = $prefixLen;
                    $bestAnchor = $anchorCol;
                }
            }

            if ($bestAnchor !== null && $bestPrefixLen > 0) {
                $groups[$bestAnchor]['columns'][$column] = $patternType;
            } else {
                $ungrouped[$column] = $patternType;
            }
        }

        $result = array_values($groups);

        // Негруппированные колонки — в отдельную группу без якоря
        if (!empty($ungrouped)) {
            $result[] = ['anchor' => null, 'columns' => $ungrouped];
        }

        return $result;
    }

    /**
     * Наибольший общий префикс двух строк.
     *
     * @return string
     */
    private function commonPrefix(string $a, string $b): string
    {
        $minLen = min(strlen($a), strlen($b));
        $prefix = '';
        for ($i = 0; $i < $minLen; $i++) {
            if ($a[$i] !== $b[$i]) {
                break;
            }
            $prefix .= $a[$i];
        }
        return $prefix;
    }

    /**
     * Применить faker к одной группе колонок в строке.
     *
     * @param array{anchor: string|null, columns: array<string, string>} $group
     * @param array<string, mixed> &$row
     */
    private function applyGroup(array $group, array &$row): void
    {
        $anchorColumn = $group['anchor'];
        $columns = $group['columns'];

        // Найти fio-колонку внутри группы для приоритетного сидирования
        $fioColumn = null;
        foreach ($columns as $column => $patternType) {
            if ($patternType === PatternDetector::PATTERN_FIO) {
                $fioColumn = $column;
                break;
            }
        }

        // Seed: приоритет — ФИО (3 слова), иначе хеш всех faker-значений группы
        if ($fioColumn !== null && isset($row[$fioColumn])) {
            mt_srand(crc32((string) $row[$fioColumn]));
        } else {
            $seedParts = [];
            foreach ($columns as $column => $patternType) {
                $seedParts[] = isset($row[$column]) ? (string) $row[$column] : '';
            }
            mt_srand(crc32(implode("\0", $seedParts)));
        }

        // Один «человек» на группу
        $gender = mt_rand(0, 1); // 0=male, 1=female

        $lastNameList = $gender ? self::LAST_NAMES_FEMALE : self::LAST_NAMES_MALE;
        $firstNameList = $gender ? self::FIRST_NAMES_FEMALE : self::FIRST_NAMES_MALE;
        $patronymicList = $gender ? self::PATRONYMICS_FEMALE : self::PATRONYMICS_MALE;

        $lastName = $lastNameList[mt_rand(0, count($lastNameList) - 1)];
        $firstName = $firstNameList[mt_rand(0, count($firstNameList) - 1)];
        $patronymic = $patronymicList[mt_rand(0, count($patronymicList) - 1)];

        foreach ($columns as $column => $patternType) {
            if (!isset($row[$column])) {
                continue;
            }

            switch ($patternType) {
                case PatternDetector::PATTERN_FIO:
                    $row[$column] = $lastName . ' ' . $firstName . ' ' . $patronymic;
                    break;
                case PatternDetector::PATTERN_FIO_SHORT:
                    $row[$column] = $lastName . ' ' . mb_substr($firstName, 0, 1) . '.' . mb_substr($patronymic, 0, 1) . '.';
                    break;
                case PatternDetector::PATTERN_NAME:
                    $row[$column] = $lastName . ' ' . $firstName;
                    break;
                case PatternDetector::PATTERN_EMAIL:
                    $row[$column] = $this->generateEmail($firstName, $lastName);
                    break;
                case PatternDetector::PATTERN_PHONE:
                    $row[$column] = $this->generatePhone((string) $row[$column]);
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
                    $row[$column] = $this->generateGender($gender, (string) $row[$column]);
                    break;
            }
        }
    }

    /** Генерирует email из транслитерированных имени и фамилии. */
    private function generateEmail(string $firstName, string $lastName): string
    {
        $translitFirst = $this->transliterate(mb_strtolower($firstName));
        $translitLast = $this->transliterate(mb_strtolower($lastName));
        $domain = self::EMAIL_DOMAINS[mt_rand(0, count(self::EMAIL_DOMAINS) - 1)];
        $num = mt_rand(1, 999);

        return $translitFirst . '.' . $translitLast . $num . '@' . $domain;
    }

    /** Генерирует российский мобильный номер, сохраняя формат оригинала. */
    private function generatePhone(string $originalPhone = ''): string
    {
        // 10 новых цифр: 9 + 9 случайных
        $newDigits = '9' . str_pad((string) mt_rand(0, 999999999), 9, '0', STR_PAD_LEFT);

        if ($originalPhone === '') {
            return '7' . $newDigits;
        }

        // Шаблон: каждая цифра → #
        /** @var string $template */
        $template = preg_replace('/\d/', '#', $originalPhone);
        $placeholderCount = substr_count($template, '#');

        if ($placeholderCount === 11) {
            // Есть цифра префикса (7 или 8) — сохраняем её
            preg_match('/\d/', $originalPhone, $m);
            $allDigits = (isset($m[0]) ? $m[0] : '7') . $newDigits;
        } elseif ($placeholderCount === 10) {
            $allDigits = $newDigits;
        } else {
            return '7' . $newDigits; // fallback
        }

        // Заполнить шаблон цифрами
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

    /** Генерирует замену значения пола, сохраняя формат и регистр оригинала. */
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

    /** Приводит регистр $value к регистру $reference. */
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

    /** Транслитерирует кириллический текст в латиницу. */
    private function transliterate(string $text): string
    {
        return strtr($text, self::TRANSLIT_MAP);
    }
}
