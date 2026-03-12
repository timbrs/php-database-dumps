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

    public function testSkipsColumnsWithFewValues(): void
    {
        $rows = [];
        for ($i = 0; $i < 5; $i++) {
            $rows[] = ['email' => "user{$i}@example.com"];
        }
        $this->connection->method('fetchAllAssociative')->willReturn($rows);

        $result = $this->detector->detect('public', 'users');
        $this->assertEmpty($result);
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
        $this->assertEquals(PatternDetector::PATTERN_NAME, $result['display_name']);
        $this->assertArrayNotHasKey('first_name', $result);
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
}
