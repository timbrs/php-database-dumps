<?php

namespace Timbrs\DatabaseDumps\Tests\Unit\Service\Faker;

use Timbrs\DatabaseDumps\Contract\ConnectionRegistryInterface;
use Timbrs\DatabaseDumps\Contract\DatabaseConnectionInterface;
use Timbrs\DatabaseDumps\Platform\PostgresPlatform;
use Timbrs\DatabaseDumps\Service\Faker\PatternDetector;
use PHPUnit\Framework\TestCase;

class PatternDetectorTest extends TestCase
{
    /** @var DatabaseConnectionInterface&\PHPUnit\Framework\MockObject\MockObject */
    private $connection;
    /** @var PatternDetector */
    private $detector;

    protected function setUp(): void
    {
        $this->connection = $this->createMock(DatabaseConnectionInterface::class);
        $this->connection->method('getPlatformName')->willReturn('postgresql');
        $registry = $this->createMock(ConnectionRegistryInterface::class);
        $registry->method('getConnection')->willReturn($this->connection);
        $registry->method('getPlatform')->willReturn(new PostgresPlatform());
        $this->detector = new PatternDetector($registry);
    }

    public function testDetectsEmailPattern(): void
    {
        $rows = [];
        for ($i = 0; $i < 20; $i++) {
            $rows[] = ['email' => "user{$i}@example.com", 'name' => "Name {$i}"];
        }
        $this->connection->method('fetchAllAssociative')->willReturn($rows);

        $result = $this->detector->detect('public', 'users');
        $this->assertArrayHasKey('email', $result);
        $this->assertEquals(PatternDetector::PATTERN_EMAIL, $result['email']);
    }

    public function testDetectsPhonePattern(): void
    {
        $rows = [];
        for ($i = 0; $i < 20; $i++) {
            $phone = '79' . str_pad((string)($i * 11111111 % 1000000000), 9, '0', STR_PAD_LEFT);
            $rows[] = ['phone' => '+' . substr($phone, 0, 1) . ' (' . substr($phone, 1, 3) . ') ' . substr($phone, 4, 3) . '-' . substr($phone, 7)];
        }
        $this->connection->method('fetchAllAssociative')->willReturn($rows);

        $result = $this->detector->detect('public', 'users');
        $this->assertArrayHasKey('phone', $result);
        $this->assertEquals(PatternDetector::PATTERN_PHONE, $result['phone']);
    }

    public function testDetectsFioPattern(): void
    {
        $rows = [];
        $fios = [
            'Иванов Иван Иванович', 'Петров Пётр Петрович', 'Сидоров Сидор Сидорович',
            'Козлов Андрей Сергеевич', 'Новиков Дмитрий Александрович', 'Морозов Алексей Николаевич',
            'Волков Сергей Владимирович', 'Лебедев Максим Олегович', 'Семёнов Артём Денисович',
            'Егоров Кирилл Игоревич', 'Павлов Роман Андреевич', 'Орлов Даниил Вадимович',
        ];
        foreach ($fios as $fio) {
            $rows[] = ['full_name' => $fio];
        }
        $this->connection->method('fetchAllAssociative')->willReturn($rows);

        $result = $this->detector->detect('public', 'users');
        $this->assertArrayHasKey('full_name', $result);
        $this->assertEquals(PatternDetector::PATTERN_FIO, $result['full_name']);
    }

    public function testDetectsFioShortPattern(): void
    {
        $rows = [];
        $shorts = [
            'Иванов И.И.', 'Петров П.П.', 'Сидоров С.С.', 'Козлов А.С.',
            'Новиков Д.А.', 'Морозов А.Н.', 'Волков С.В.', 'Лебедев М.О.',
            'Семёнов А.Д.', 'Егоров К.И.', 'Павлов Р.А.', 'Орлов Д.В.',
        ];
        foreach ($shorts as $short) {
            $rows[] = ['short_name' => $short];
        }
        $this->connection->method('fetchAllAssociative')->willReturn($rows);

        $result = $this->detector->detect('public', 'users');
        $this->assertArrayHasKey('short_name', $result);
        $this->assertEquals(PatternDetector::PATTERN_FIO_SHORT, $result['short_name']);
    }

    public function testFailSafeDetectsByColumnNameForSmallTable(): void
    {
        // На маленьких таблицах (<10 строк) детекция по имени колонки —
        // fail-safe для безопасности (лучше лишняя маскировка чем утечка PII).
        $rows = [];
        for ($i = 0; $i < 5; $i++) {
            $rows[] = ['email' => "user{$i}@example.com"];
        }
        $this->connection->method('fetchAllAssociative')->willReturn($rows);

        $result = $this->detector->detect('public', 'users');
        // 'email' имя — распознан по hint
        $this->assertSame(PatternDetector::PATTERN_EMAIL, $result['email'] ?? null);
    }

    public function testBelowThresholdNotDetected(): void
    {
        $rows = [];
        // 15 emails out of 20 = 75% < 80% threshold
        for ($i = 0; $i < 15; $i++) {
            $rows[] = ['email' => "user{$i}@example.com"];
        }
        for ($i = 0; $i < 5; $i++) {
            $rows[] = ['email' => "not-an-email-{$i}"];
        }
        $this->connection->method('fetchAllAssociative')->willReturn($rows);

        $result = $this->detector->detect('public', 'users');
        $this->assertArrayNotHasKey('email', $result);
    }

    public function testDetectsNamePattern(): void
    {
        $rows = [];
        $names = [
            'Иванов Иван', 'Петрова Мария', 'Сидоров Алексей', 'Козлова Елена',
            'Новиков Дмитрий', 'Морозова Ольга', 'Волков Сергей', 'Лебедева Анна',
            'Семёнов Артём', 'Егорова Наталья', 'Павлов Роман', 'Орлова Юлия',
            'Андреев Максим', 'Макарова Ирина', 'Никитин Кирилл', 'Захарова Татьяна',
            'Зайцев Денис', 'Борисова Светлана', 'Яковлев Олег', 'Григорьева Екатерина',
        ];
        foreach ($names as $name) {
            $rows[] = ['display_name' => $name];
        }
        $this->connection->method('fetchAllAssociative')->willReturn($rows);

        $result = $this->detector->detect('public', 'users');
        $this->assertArrayHasKey('display_name', $result);
        $this->assertEquals(PatternDetector::PATTERN_NAME, $result['display_name']);
    }

    public function testEmptyTable(): void
    {
        $this->connection->method('fetchAllAssociative')->willReturn([]);
        $result = $this->detector->detect('public', 'users');
        $this->assertEmpty($result);
    }

    public function testDetectsLinkedFirstname(): void
    {
        $rows = [];
        $data = [
            ['Иванов Иван', 'Иван'], ['Петрова Мария', 'Мария'],
            ['Сидоров Алексей', 'Алексей'], ['Козлова Елена', 'Елена'],
            ['Новиков Дмитрий', 'Дмитрий'], ['Морозова Ольга', 'Ольга'],
            ['Волков Сергей', 'Сергей'], ['Лебедева Анна', 'Анна'],
            ['Семёнов Артём', 'Артём'], ['Егорова Наталья', 'Наталья'],
            ['Павлов Роман', 'Роман'], ['Орлова Юлия', 'Юлия'],
        ];
        foreach ($data as $item) {
            $rows[] = ['display_name' => $item[0], 'first_name' => $item[1]];
        }
        $this->connection->method('fetchAllAssociative')->willReturn($rows);

        $result = $this->detector->detect('public', 'users');
        $this->assertEquals(PatternDetector::PATTERN_NAME, $result['display_name']);
        $this->assertArrayHasKey('first_name', $result);
        $this->assertEquals(PatternDetector::PATTERN_FIRSTNAME, $result['first_name']);
    }

    public function testDetectsLinkedLastname(): void
    {
        $rows = [];
        $data = [
            ['Иванов Иван', 'Иванов'], ['Петрова Мария', 'Петрова'],
            ['Сидоров Алексей', 'Сидоров'], ['Козлова Елена', 'Козлова'],
            ['Новиков Дмитрий', 'Новиков'], ['Морозова Ольга', 'Морозова'],
            ['Волков Сергей', 'Волков'], ['Лебедева Анна', 'Лебедева'],
            ['Семёнов Артём', 'Семёнов'], ['Егорова Наталья', 'Егорова'],
            ['Павлов Роман', 'Павлов'], ['Орлова Юлия', 'Орлова'],
        ];
        foreach ($data as $item) {
            $rows[] = ['display_name' => $item[0], 'surname' => $item[1]];
        }
        $this->connection->method('fetchAllAssociative')->willReturn($rows);

        $result = $this->detector->detect('public', 'users');
        $this->assertEquals(PatternDetector::PATTERN_NAME, $result['display_name']);
        $this->assertArrayHasKey('surname', $result);
        $this->assertEquals(PatternDetector::PATTERN_LASTNAME, $result['surname']);
    }

    public function testDetectsLinkedPatronymic(): void
    {
        $rows = [];
        $data = [
            ['Иванов Иван Иванович', 'Иванович'], ['Петров Пётр Петрович', 'Петрович'],
            ['Сидоров Сидор Сидорович', 'Сидорович'], ['Козлов Андрей Сергеевич', 'Сергеевич'],
            ['Новиков Дмитрий Александрович', 'Александрович'], ['Морозов Алексей Николаевич', 'Николаевич'],
            ['Волков Сергей Владимирович', 'Владимирович'], ['Лебедев Максим Олегович', 'Олегович'],
            ['Семёнов Артём Денисович', 'Денисович'], ['Егоров Кирилл Игоревич', 'Игоревич'],
            ['Павлов Роман Андреевич', 'Андреевич'], ['Орлов Даниил Вадимович', 'Вадимович'],
        ];
        foreach ($data as $item) {
            $rows[] = ['full_name' => $item[0], 'middle_name' => $item[1]];
        }
        $this->connection->method('fetchAllAssociative')->willReturn($rows);

        $result = $this->detector->detect('public', 'users');
        $this->assertEquals(PatternDetector::PATTERN_FIO, $result['full_name']);
        $this->assertArrayHasKey('middle_name', $result);
        $this->assertEquals(PatternDetector::PATTERN_PATRONYMIC, $result['middle_name']);
    }

    public function testLinkedColumnBelowThreshold(): void
    {
        $rows = [];
        $data = [
            ['Иванов Иван', 'Иван'], ['Петрова Мария', 'Мария'],
            ['Сидоров Алексей', 'Алексей'], ['Козлова Елена', 'Елена'],
            ['Новиков Дмитрий', 'Дмитрий'], ['Морозова Ольга', 'Ольга'],
            ['Волков Сергей', 'Сергей'],
            // Ниже — не совпадают со составной колонкой
            ['Лебедева Анна', 'Тимур'], ['Семёнов Артём', 'Борис'],
            ['Егорова Наталья', 'Кирилл'], ['Павлов Роман', 'Дарья'],
            ['Орлова Юлия', 'Вера'], ['Андреев Максим', 'Пётр'],
        ];
        foreach ($data as $item) {
            $rows[] = ['display_name' => $item[0], 'first_name' => $item[1]];
        }
        $this->connection->method('fetchAllAssociative')->willReturn($rows);

        $result = $this->detector->detect('public', 'users');
        $this->assertSame(PatternDetector::PATTERN_NAME, $result['display_name']);
        // first_name теперь распознан по имени колонки (fail-safe для PII)
        // даже когда корреляция со составной колонкой не сработала.
        $this->assertSame(PatternDetector::PATTERN_FIRSTNAME, $result['first_name']);
    }

    public function testNoLinkedColumnsWithoutComposite(): void
    {
        $rows = [];
        for ($i = 0; $i < 20; $i++) {
            $rows[] = ['email' => "user{$i}@example.com", 'some_field' => 'Иван'];
        }
        $this->connection->method('fetchAllAssociative')->willReturn($rows);

        $result = $this->detector->detect('public', 'users');
        $this->assertArrayHasKey('email', $result);
        $this->assertArrayNotHasKey('some_field', $result);
    }

    public function testDetectsGenderMaleFemale(): void
    {
        $rows = [];
        for ($i = 0; $i < 20; $i++) {
            $rows[] = ['gender' => $i % 2 === 0 ? 'Male' : 'Female', 'id' => $i];
        }
        $this->connection->method('fetchAllAssociative')->willReturn($rows);

        $result = $this->detector->detect('public', 'users');
        $this->assertArrayHasKey('gender', $result);
        $this->assertEquals(PatternDetector::PATTERN_GENDER, $result['gender']);
    }

    public function testDetectsGenderMF(): void
    {
        $rows = [];
        for ($i = 0; $i < 20; $i++) {
            $rows[] = ['sex' => $i % 2 === 0 ? 'M' : 'F', 'id' => $i];
        }
        $this->connection->method('fetchAllAssociative')->willReturn($rows);

        $result = $this->detector->detect('public', 'users');
        $this->assertArrayHasKey('sex', $result);
        $this->assertEquals(PatternDetector::PATTERN_GENDER, $result['sex']);
    }

    public function testDetectsGenderCyrillicMZh(): void
    {
        $rows = [];
        for ($i = 0; $i < 20; $i++) {
            $rows[] = ['пол' => $i % 2 === 0 ? 'м' : 'ж', 'id' => $i];
        }
        $this->connection->method('fetchAllAssociative')->willReturn($rows);

        $result = $this->detector->detect('public', 'users');
        $this->assertArrayHasKey('пол', $result);
        $this->assertEquals(PatternDetector::PATTERN_GENDER, $result['пол']);
    }

    public function testDetectsGenderRussianWords(): void
    {
        $rows = [];
        for ($i = 0; $i < 20; $i++) {
            $rows[] = ['gender' => $i % 2 === 0 ? 'мужчина' : 'женщина', 'id' => $i];
        }
        $this->connection->method('fetchAllAssociative')->willReturn($rows);

        $result = $this->detector->detect('public', 'users');
        $this->assertArrayHasKey('gender', $result);
        $this->assertEquals(PatternDetector::PATTERN_GENDER, $result['gender']);
    }

    public function testGenderNotDetectedWithoutColumnNameMatch(): void
    {
        $rows = [];
        for ($i = 0; $i < 20; $i++) {
            $rows[] = ['some_field' => $i % 2 === 0 ? 'Male' : 'Female', 'id' => $i];
        }
        $this->connection->method('fetchAllAssociative')->willReturn($rows);

        $result = $this->detector->detect('public', 'users');
        $this->assertArrayNotHasKey('some_field', $result);
    }

    public function testGenderNotDetectedWithWrongValues(): void
    {
        $rows = [];
        for ($i = 0; $i < 20; $i++) {
            $rows[] = ['gender' => "value{$i}", 'id' => $i];
        }
        $this->connection->method('fetchAllAssociative')->willReturn($rows);

        $result = $this->detector->detect('public', 'users');
        $this->assertArrayNotHasKey('gender', $result);
    }

    public function testGenderBelowThreshold(): void
    {
        $rows = [];
        // 15 из 20 = 75% < 80% порога
        for ($i = 0; $i < 15; $i++) {
            $rows[] = ['gender' => $i % 2 === 0 ? 'Male' : 'Female', 'id' => $i];
        }
        for ($i = 0; $i < 5; $i++) {
            $rows[] = ['gender' => "garbage{$i}", 'id' => 15 + $i];
        }
        $this->connection->method('fetchAllAssociative')->willReturn($rows);

        $result = $this->detector->detect('public', 'users');
        $this->assertArrayNotHasKey('gender', $result);
    }

    public function testDetectsLinkedByColumnNameHeuristic(): void
    {
        $rows = [];
        // Значения с суффиксами отчеств, но колонка названа lname → приоритет имени колонки
        $data = [
            ['Иванов Иван Иванович', 'Иванович'], ['Петров Пётр Петрович', 'Петрович'],
            ['Сидоров Сидор Сидорович', 'Сидорович'], ['Козлов Андрей Сергеевич', 'Сергеевич'],
            ['Новиков Дмитрий Александрович', 'Александрович'], ['Морозов Алексей Николаевич', 'Николаевич'],
            ['Волков Сергей Владимирович', 'Владимирович'], ['Лебедев Максим Олегович', 'Олегович'],
            ['Семёнов Артём Денисович', 'Денисович'], ['Егоров Кирилл Игоревич', 'Игоревич'],
            ['Павлов Роман Андреевич', 'Андреевич'], ['Орлов Даниил Вадимович', 'Вадимович'],
        ];
        foreach ($data as $item) {
            $rows[] = ['full_name' => $item[0], 'lname' => $item[1]];
        }
        $this->connection->method('fetchAllAssociative')->willReturn($rows);

        $result = $this->detector->detect('public', 'users');
        // lname → column hint /lname/i → PATTERN_LASTNAME, несмотря на суффиксы отчеств
        $this->assertEquals(PatternDetector::PATTERN_LASTNAME, $result['lname']);
    }

    public function testSampleNeverSortsTheWholeTable(): void
    {
        $captured = [];
        $this->connection->method('fetchAllAssociative')->willReturnCallback(function ($sql) use (&$captured) {
            $captured[] = $sql;
            return [['email' => 'a@b.c']];
        });

        $this->detector->detect('public', 'users');
        $this->detector->detect('public', 'users', null, 100);
        $this->detector->detect('public', 'users', null, 5000000);

        // Размер неизвестен — голова; небольшая — BERNOULLI; большая — блочный SYSTEM.
        $this->assertSame('SELECT * FROM "public"."users" LIMIT 200', $captured[0]);
        $this->assertSame('SELECT * FROM "public"."users" TABLESAMPLE BERNOULLI (100) LIMIT 200', $captured[1]);
        $this->assertSame('SELECT * FROM "public"."users" TABLESAMPLE SYSTEM (0.012) LIMIT 200', $captured[2]);
        foreach ($captured as $sql) {
            $this->assertStringNotContainsString('ORDER BY', $sql);
        }
    }

    public function testEmptyBlockSampleFallsBackToTableHead(): void
    {
        $captured = [];
        $this->connection->method('fetchAllAssociative')->willReturnCallback(function ($sql) use (&$captured) {
            $captured[] = $sql;
            return strpos($sql, 'TABLESAMPLE') !== false ? [] : [['email' => 'a@b.c']];
        });

        $this->detector->detect('public', 'users', null, 5000000);

        $this->assertCount(2, $captured);
        $this->assertStringContainsString('TABLESAMPLE', $captured[0]);
        $this->assertSame('SELECT * FROM "public"."users" LIMIT 200', $captured[1]);
    }

    public function testPiiHintsAreSharedWithTheCodeGate(): void
    {
        $this->assertTrue(PatternDetector::hintsPii('last_name'));
        $this->assertTrue(PatternDetector::hintsPii('mobile_phone'));
        $this->assertTrue(PatternDetector::hintsPii('фио'));
        $this->assertFalse(PatternDetector::hintsPii('status_id'));
    }

    /**
     * Дата — не телефон.
     *
     * Проверка «похоже на телефон» срезала все нецифровые символы и смотрела на длину: `2025-01-02`
     * превращалось в 8 цифр, метка времени — в 14, и обе попадали в диапазон 7–15. Из-за этого V-7
     * объявлял утечкой персональных данных любую колонку с датами без faker, а это ошибка, которая
     * запирает выгрузку с прода.
     *
     * @dataProvider dateShapes
     */
    public function testDatesAreNotDetectedAsPhones(string $value): void
    {
        $values = array_fill(0, 20, $value);

        self::assertNull(PatternDetector::detectPatternInValues('value_date', $values));
    }

    /** @return array<string, array{string}> */
    public static function dateShapes(): array
    {
        return [
            'ISO-дата' => ['2025-01-02'],
            'метка времени' => ['2025-01-02 10:30:00'],
            'ISO с T' => ['2025-01-02T10:30:00'],
            'русская дата' => ['31.12.2025'],
        ];
    }

    /** Настоящие телефоны при этом по-прежнему опознаются — иначе лечение хуже болезни. */
    public function testRealPhonesAreStillDetected(): void
    {
        $values = array_fill(0, 10, '+7 (999) 123-45-67');
        $values[] = '89991234567';

        self::assertSame(PatternDetector::PATTERN_PHONE, PatternDetector::detectPatternInValues('contact', $values));
    }
}
