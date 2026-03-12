<?php

namespace Timbrs\DatabaseDumps\Tests\Unit\Service\Faker;

use Timbrs\DatabaseDumps\Service\Faker\PatternDetector;
use Timbrs\DatabaseDumps\Service\Faker\RussianFaker;
use PHPUnit\Framework\TestCase;

class RussianFakerTest extends TestCase
{
    /** @var RussianFaker */
    private $faker;

    protected function setUp(): void
    {
        $this->faker = new RussianFaker();
    }

    public function testApplyFio(): void
    {
        $rows = [
            ['id' => 1, 'full_name' => 'Тестов Тест Тестович'],
            ['id' => 2, 'full_name' => 'Другов Друг Другович'],
        ];
        $result = $this->faker->apply('public', 'users', ['full_name' => PatternDetector::PATTERN_FIO], $rows);

        $this->assertNotEquals('Тестов Тест Тестович', $result[0]['full_name']);
        $this->assertNotEquals('Другов Друг Другович', $result[1]['full_name']);
        // Should be 3 Cyrillic words
        $this->assertMatchesRegularExpression('/^[А-ЯЁа-яё]+ [А-ЯЁа-яё]+ [А-ЯЁа-яё]+$/u', $result[0]['full_name']);
    }

    public function testApplyFioShort(): void
    {
        $rows = [
            ['id' => 1, 'short_name' => 'Тестов Т.Т.'],
        ];
        $result = $this->faker->apply('public', 'users', ['short_name' => PatternDetector::PATTERN_FIO_SHORT], $rows);

        $this->assertNotEquals('Тестов Т.Т.', $result[0]['short_name']);
        // Format: Фамилия И.О.
        $this->assertMatchesRegularExpression('/^[А-ЯЁа-яё]+ [А-ЯЁ]\.[А-ЯЁ]\.$/u', $result[0]['short_name']);
    }

    public function testApplyEmail(): void
    {
        $rows = [
            ['id' => 1, 'email' => 'original@test.com'],
        ];
        $result = $this->faker->apply('public', 'users', ['email' => PatternDetector::PATTERN_EMAIL], $rows);

        $this->assertNotEquals('original@test.com', $result[0]['email']);
        $this->assertStringContainsString('@', $result[0]['email']);
    }

    public function testApplyPhone(): void
    {
        $rows = [
            ['id' => 1, 'phone' => '+79001234567'],
        ];
        $result = $this->faker->apply('public', 'users', ['phone' => PatternDetector::PATTERN_PHONE], $rows);

        $this->assertNotEquals('+79001234567', $result[0]['phone']);
        $this->assertMatchesRegularExpression('/^\+79\d{9}$/', $result[0]['phone']);
    }

    public function testNullPreservation(): void
    {
        $rows = [
            ['id' => 1, 'full_name' => null],
        ];
        $result = $this->faker->apply('public', 'users', ['full_name' => PatternDetector::PATTERN_FIO], $rows);

        $this->assertNull($result[0]['full_name']);
    }

    public function testApplyName(): void
    {
        $rows = [
            ['id' => 1, 'display_name' => 'Тестов Тест'],
        ];
        $result = $this->faker->apply('public', 'users', ['display_name' => PatternDetector::PATTERN_NAME], $rows);

        $this->assertNotEquals('Тестов Тест', $result[0]['display_name']);
        // Should be 2 Cyrillic words
        $this->assertMatchesRegularExpression('/^[А-ЯЁа-яё]+ [А-ЯЁа-яё]+$/u', $result[0]['display_name']);
    }

    public function testDeterminism(): void
    {
        $rows = [
            ['id' => 1, 'full_name' => 'Оригинал Оригиналов Оригиналович'],
        ];
        $result1 = $this->faker->apply('public', 'users', ['full_name' => PatternDetector::PATTERN_FIO], $rows);
        $result2 = $this->faker->apply('public', 'users', ['full_name' => PatternDetector::PATTERN_FIO], $rows);

        $this->assertEquals($result1[0]['full_name'], $result2[0]['full_name']);
    }

    public function testDeterminismAcrossTables(): void
    {
        $rows = [
            ['id' => 1, 'full_name' => 'Оригинал Оригиналов Оригиналович'],
        ];
        $result1 = $this->faker->apply('public', 'users', ['full_name' => PatternDetector::PATTERN_FIO], $rows);
        $result2 = $this->faker->apply('other_schema', 'employees', ['full_name' => PatternDetector::PATTERN_FIO], $rows);

        $this->assertEquals($result1[0]['full_name'], $result2[0]['full_name']);
    }

    public function testEmptyRows(): void
    {
        $result = $this->faker->apply('public', 'users', ['full_name' => PatternDetector::PATTERN_FIO], []);
        $this->assertEmpty($result);
    }

    public function testUnchangedColumnsNotInConfig(): void
    {
        $rows = [
            ['id' => 1, 'full_name' => 'Тест Тестов Тестович', 'age' => 25],
        ];
        $result = $this->faker->apply('public', 'users', ['full_name' => PatternDetector::PATTERN_FIO], $rows);

        $this->assertEquals(25, $result[0]['age']);
        $this->assertEquals(1, $result[0]['id']);
    }

    public function testSameNameDifferentRowsProduceDifferentReplacements(): void
    {
        $fakerConfig = [
            'display_name' => PatternDetector::PATTERN_NAME,
            'email' => PatternDetector::PATTERN_EMAIL,
        ];
        $rows = [
            ['id' => 1, 'display_name' => 'Тестов Тест', 'email' => 'test1@example.com'],
            ['id' => 2, 'display_name' => 'Тестов Тест', 'email' => 'test2@example.com'],
        ];
        $result = $this->faker->apply('public', 'users', $fakerConfig, $rows);

        // Нет fio-паттерна → seed от всех колонок → разный email даёт разные замены name
        $this->assertNotEquals($result[0]['display_name'], $result[1]['display_name']);
    }

    public function testFioSeedPriorityOverOtherColumns(): void
    {
        $fakerConfig = [
            'full_name' => PatternDetector::PATTERN_FIO,
            'email' => PatternDetector::PATTERN_EMAIL,
        ];
        $rows1 = [
            ['id' => 1, 'full_name' => 'Тестов Тест Тестович', 'email' => 'one@example.com'],
        ];
        $rows2 = [
            ['id' => 2, 'full_name' => 'Тестов Тест Тестович', 'email' => 'other@example.com'],
        ];

        $result1 = $this->faker->apply('public', 'users', $fakerConfig, $rows1);
        $result2 = $this->faker->apply('public', 'users', $fakerConfig, $rows2);

        // Одинаковое ФИО → одинаковый seed → одинаковая замена, независимо от email
        $this->assertEquals($result1[0]['full_name'], $result2[0]['full_name']);
        $this->assertEquals($result1[0]['email'], $result2[0]['email']);
    }

    public function testFioAndFioShortConsistentInSameRow(): void
    {
        $fakerConfig = [
            'full_name' => PatternDetector::PATTERN_FIO,
            'short_name' => PatternDetector::PATTERN_FIO_SHORT,
        ];
        $rows = [
            ['id' => 1, 'full_name' => 'Тестов Тест Тестович', 'short_name' => 'Тестов Т.Т.'],
        ];
        $result = $this->faker->apply('public', 'users', $fakerConfig, $rows);

        // fio: "Фамилия Имя Отчество", fio_short: "Фамилия И.О."
        $fioParts = explode(' ', $result[0]['full_name']);
        $this->assertCount(3, $fioParts);

        $expectedShort = $fioParts[0] . ' ' . mb_substr($fioParts[1], 0, 1) . '.' . mb_substr($fioParts[2], 0, 1) . '.';
        $this->assertEquals($expectedShort, $result[0]['short_name']);
    }

    public function testEmailCorrespondsToNameInSameRow(): void
    {
        $translitMap = [
            'а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'g', 'д' => 'd', 'е' => 'e', 'ё' => 'yo',
            'ж' => 'zh', 'з' => 'z', 'и' => 'i', 'й' => 'y', 'к' => 'k', 'л' => 'l', 'м' => 'm',
            'н' => 'n', 'о' => 'o', 'п' => 'p', 'р' => 'r', 'с' => 's', 'т' => 't', 'у' => 'u',
            'ф' => 'f', 'х' => 'kh', 'ц' => 'ts', 'ч' => 'ch', 'ш' => 'sh', 'щ' => 'shch',
            'ъ' => '', 'ы' => 'y', 'ь' => '', 'э' => 'e', 'ю' => 'yu', 'я' => 'ya',
        ];

        $fakerConfig = [
            'display_name' => PatternDetector::PATTERN_NAME,
            'email' => PatternDetector::PATTERN_EMAIL,
        ];
        $rows = [
            ['id' => 1, 'display_name' => 'Тестов Тест', 'email' => 'test@example.com'],
        ];
        $result = $this->faker->apply('public', 'users', $fakerConfig, $rows);

        // name: "Фамилия Имя", email транслитерирован из того же имени
        $nameParts = explode(' ', $result[0]['display_name']);
        $translitFirst = strtr(mb_strtolower($nameParts[1]), $translitMap);
        $translitLast = strtr(mb_strtolower($nameParts[0]), $translitMap);

        // Email формат: {first}.{last}{num}@{domain}
        $emailLocal = explode('@', $result[0]['email'])[0];
        $this->assertStringStartsWith($translitFirst . '.' . $translitLast, $emailLocal);
    }

    public function testPerRowDeterminismWithMultipleColumns(): void
    {
        $fakerConfig = [
            'full_name' => PatternDetector::PATTERN_FIO,
            'email' => PatternDetector::PATTERN_EMAIL,
            'phone' => PatternDetector::PATTERN_PHONE,
        ];
        $rows = [
            ['id' => 1, 'full_name' => 'Тестов Тест Тестович', 'email' => 'test@example.com', 'phone' => '+79001234567'],
        ];

        $result1 = $this->faker->apply('public', 'users', $fakerConfig, $rows);
        $result2 = $this->faker->apply('public', 'users', $fakerConfig, $rows);

        $this->assertEquals($result1[0]['full_name'], $result2[0]['full_name']);
        $this->assertEquals($result1[0]['email'], $result2[0]['email']);
        $this->assertEquals($result1[0]['phone'], $result2[0]['phone']);
    }

    /**
     * @dataProvider phoneFormatProvider
     */
    public function testPhoneFormatPreservation(string $input, string $regex): void
    {
        $rows = [['id' => 1, 'phone' => $input]];
        $result = $this->faker->apply('public', 'users', ['phone' => PatternDetector::PATTERN_PHONE], $rows);
        $this->assertMatchesRegularExpression($regex, $result[0]['phone']);
        $this->assertNotEquals($input, $result[0]['phone']);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function phoneFormatProvider(): array
    {
        return [
            'plus-seven-parens'  => ['+7(903)150-03-03', '/^\+7\(9\d{2}\)\d{3}-\d{2}-\d{2}$/'],
            'eight-dashes'       => ['8-929-317-7788',    '/^8-9\d{2}-\d{3}-\d{4}$/'],
            'seven-dashes'       => ['7-905-150-0303',    '/^7-9\d{2}-\d{3}-\d{4}$/'],
            'bare-11-digits'     => ['79051500303',       '/^79\d{9}$/'],
            'plus-bare-11'       => ['+79501500303',      '/^\+79\d{9}$/'],
            'eight-parens'       => ['8(903)-150-03-03',  '/^8\(9\d{2}\)-\d{3}-\d{2}-\d{2}$/'],
            'plus-seven-spaces'  => ['+7 903 150 03 03',  '/^\+7 9\d{2} \d{3} \d{2} \d{2}$/'],
            'ten-digits'         => ['9051500303',        '/^9\d{9}$/'],
        ];
    }

    public function testPhonePrefixPreserved(): void
    {
        $rows = [['id' => 1, 'phone' => '89291234567']];
        $result = $this->faker->apply('public', 'users', ['phone' => PatternDetector::PATTERN_PHONE], $rows);
        $this->assertStringStartsWith('89', $result[0]['phone']);
    }

    public function testPhoneDeterminismWithFormat(): void
    {
        $rows = [['id' => 1, 'phone' => '+7(903)150-03-03']];
        $config = ['phone' => PatternDetector::PATTERN_PHONE];
        $r1 = $this->faker->apply('public', 'users', $config, $rows);
        $r2 = $this->faker->apply('public', 'users', $config, $rows);
        $this->assertEquals($r1[0]['phone'], $r2[0]['phone']);
    }

    public function testApplyFirstname(): void
    {
        $rows = [['id' => 1, 'first_name' => 'Тест']];
        $result = $this->faker->apply('public', 'users', ['first_name' => PatternDetector::PATTERN_FIRSTNAME], $rows);

        $this->assertNotEquals('Тест', $result[0]['first_name']);
        $this->assertMatchesRegularExpression('/^[А-ЯЁа-яё]+$/u', $result[0]['first_name']);
    }

    public function testApplyLastname(): void
    {
        $rows = [['id' => 1, 'surname' => 'Тестов']];
        $result = $this->faker->apply('public', 'users', ['surname' => PatternDetector::PATTERN_LASTNAME], $rows);

        $this->assertNotEquals('Тестов', $result[0]['surname']);
        $this->assertMatchesRegularExpression('/^[А-ЯЁа-яё]+$/u', $result[0]['surname']);
    }

    public function testApplyPatronymic(): void
    {
        $rows = [['id' => 1, 'patronymic' => 'Тестович']];
        $result = $this->faker->apply('public', 'users', ['patronymic' => PatternDetector::PATTERN_PATRONYMIC], $rows);

        $this->assertNotEquals('Тестович', $result[0]['patronymic']);
        $this->assertMatchesRegularExpression('/^[А-ЯЁа-яё]+$/u', $result[0]['patronymic']);
    }

    public function testLinkedColumnsConsistentWithComposite(): void
    {
        $fakerConfig = [
            'display_name' => PatternDetector::PATTERN_NAME,
            'first_name' => PatternDetector::PATTERN_FIRSTNAME,
            'last_name' => PatternDetector::PATTERN_LASTNAME,
        ];
        $rows = [
            ['id' => 1, 'display_name' => 'Тестов Тест', 'first_name' => 'Тест', 'last_name' => 'Тестов'],
        ];
        $result = $this->faker->apply('public', 'users', $fakerConfig, $rows);

        $nameParts = explode(' ', $result[0]['display_name']);
        $this->assertEquals($nameParts[0], $result[0]['last_name']);
        $this->assertEquals($nameParts[1], $result[0]['first_name']);
    }

    public function testApplyGender(): void
    {
        $rows = [['id' => 1, 'gender' => 'Male']];
        $result = $this->faker->apply('public', 'users', ['gender' => PatternDetector::PATTERN_GENDER], $rows);

        $this->assertContains($result[0]['gender'], ['Male', 'Female']);
    }

    public function testApplyGenderMF(): void
    {
        $rows = [['id' => 1, 'sex' => 'M']];
        $result = $this->faker->apply('public', 'users', ['sex' => PatternDetector::PATTERN_GENDER], $rows);

        $this->assertContains($result[0]['sex'], ['M', 'F']);
    }

    public function testApplyGenderCyrillic(): void
    {
        $rows = [['id' => 1, 'sex' => 'м']];
        $result = $this->faker->apply('public', 'users', ['sex' => PatternDetector::PATTERN_GENDER], $rows);

        $this->assertContains($result[0]['sex'], ['м', 'ж']);
    }

    public function testApplyGenderRussianWords(): void
    {
        $rows = [['id' => 1, 'gender' => 'мужчина']];
        $result = $this->faker->apply('public', 'users', ['gender' => PatternDetector::PATTERN_GENDER], $rows);

        $this->assertContains($result[0]['gender'], ['мужчина', 'женщина']);
    }

    public function testGenderCasePreservation(): void
    {
        // Проверяем 3 регистра для латиницы
        $rows = [
            ['id' => 1, 'g' => 'MALE'],
            ['id' => 2, 'g' => 'Male'],
            ['id' => 3, 'g' => 'male'],
        ];
        $result = $this->faker->apply('public', 'users', ['g' => PatternDetector::PATTERN_GENDER], $rows);

        $this->assertContains($result[0]['g'], ['MALE', 'FEMALE']);
        $this->assertContains($result[1]['g'], ['Male', 'Female']);
        $this->assertContains($result[2]['g'], ['male', 'female']);
    }

    public function testGenderCasePreservationCyrillic(): void
    {
        $rows = [
            ['id' => 1, 'g' => 'М'],
            ['id' => 2, 'g' => 'м'],
        ];
        $result = $this->faker->apply('public', 'users', ['g' => PatternDetector::PATTERN_GENDER], $rows);

        $this->assertContains($result[0]['g'], ['М', 'Ж']);
        $this->assertContains($result[1]['g'], ['м', 'ж']);
    }

    public function testGenderConsistentWithNameInRow(): void
    {
        $fakerConfig = [
            'full_name' => PatternDetector::PATTERN_FIO,
            'gender' => PatternDetector::PATTERN_GENDER,
        ];
        $rows = [
            ['id' => 1, 'full_name' => 'Тестов Тест Тестович', 'gender' => 'male'],
        ];
        $result = $this->faker->apply('public', 'users', $fakerConfig, $rows);

        $fioParts = explode(' ', $result[0]['full_name']);
        $isMaleName = mb_substr($fioParts[2], -2) === 'ич'; // отчество на -ич = мужское

        if ($isMaleName) {
            $this->assertEquals('male', $result[0]['gender']);
        } else {
            $this->assertEquals('female', $result[0]['gender']);
        }
    }

    public function testGenderDeterminism(): void
    {
        $rows = [['id' => 1, 'gender' => 'Male']];
        $config = ['gender' => PatternDetector::PATTERN_GENDER];
        $r1 = $this->faker->apply('public', 'users', $config, $rows);
        $r2 = $this->faker->apply('public', 'users', $config, $rows);

        $this->assertEquals($r1[0]['gender'], $r2[0]['gender']);
    }

    public function testGenderNullPreservation(): void
    {
        $rows = [['id' => 1, 'gender' => null]];
        $result = $this->faker->apply('public', 'users', ['gender' => PatternDetector::PATTERN_GENDER], $rows);

        $this->assertNull($result[0]['gender']);
    }

    public function testGenderUnrecognizedFallback(): void
    {
        $rows = [['id' => 1, 'gender' => '1']];
        $result = $this->faker->apply('public', 'users', ['gender' => PatternDetector::PATTERN_GENDER], $rows);

        $this->assertEquals('1', $result[0]['gender']);
    }

    public function testSellerBuyerDifferentPeople(): void
    {
        $fakerConfig = [
            'seller_fio' => PatternDetector::PATTERN_FIO,
            'seller_fio_short' => PatternDetector::PATTERN_FIO_SHORT,
            'seller_email' => PatternDetector::PATTERN_EMAIL,
            'buyer_fio' => PatternDetector::PATTERN_FIO,
            'buyer_fio_short' => PatternDetector::PATTERN_FIO_SHORT,
            'buyer_email' => PatternDetector::PATTERN_EMAIL,
        ];
        $rows = [
            [
                'id' => 1,
                'seller_fio' => 'Продавцов Продавец Продавцович',
                'seller_fio_short' => 'Продавцов П.П.',
                'seller_email' => 'seller@example.com',
                'buyer_fio' => 'Покупателев Покупатель Покупателевич',
                'buyer_fio_short' => 'Покупателев П.П.',
                'buyer_email' => 'buyer@example.com',
            ],
        ];
        $result = $this->faker->apply('public', 'deals', $fakerConfig, $rows);

        // seller и buyer — РАЗНЫЕ люди
        $this->assertNotEquals($result[0]['seller_fio'], $result[0]['buyer_fio']);

        // seller_fio и seller_fio_short согласованы
        $sellerParts = explode(' ', $result[0]['seller_fio']);
        $expectedSellerShort = $sellerParts[0] . ' ' . mb_substr($sellerParts[1], 0, 1) . '.' . mb_substr($sellerParts[2], 0, 1) . '.';
        $this->assertEquals($expectedSellerShort, $result[0]['seller_fio_short']);

        // buyer_fio и buyer_fio_short согласованы
        $buyerParts = explode(' ', $result[0]['buyer_fio']);
        $expectedBuyerShort = $buyerParts[0] . ' ' . mb_substr($buyerParts[1], 0, 1) . '.' . mb_substr($buyerParts[2], 0, 1) . '.';
        $this->assertEquals($expectedBuyerShort, $result[0]['buyer_fio_short']);
    }

    public function testSellerBuyerEmailsCorrespondToOwnGroup(): void
    {
        $translitMap = [
            'а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'g', 'д' => 'd', 'е' => 'e', 'ё' => 'yo',
            'ж' => 'zh', 'з' => 'z', 'и' => 'i', 'й' => 'y', 'к' => 'k', 'л' => 'l', 'м' => 'm',
            'н' => 'n', 'о' => 'o', 'п' => 'p', 'р' => 'r', 'с' => 's', 'т' => 't', 'у' => 'u',
            'ф' => 'f', 'х' => 'kh', 'ц' => 'ts', 'ч' => 'ch', 'ш' => 'sh', 'щ' => 'shch',
            'ъ' => '', 'ы' => 'y', 'ь' => '', 'э' => 'e', 'ю' => 'yu', 'я' => 'ya',
        ];

        $fakerConfig = [
            'seller_fio' => PatternDetector::PATTERN_FIO,
            'seller_email' => PatternDetector::PATTERN_EMAIL,
            'buyer_fio' => PatternDetector::PATTERN_FIO,
            'buyer_email' => PatternDetector::PATTERN_EMAIL,
        ];
        $rows = [
            [
                'id' => 1,
                'seller_fio' => 'Продавцов Продавец Продавцович',
                'seller_email' => 'seller@example.com',
                'buyer_fio' => 'Покупателев Покупатель Покупателевич',
                'buyer_email' => 'buyer@example.com',
            ],
        ];
        $result = $this->faker->apply('public', 'deals', $fakerConfig, $rows);

        // seller_email соответствует seller_fio
        $sellerParts = explode(' ', $result[0]['seller_fio']);
        $sellerTranslitFirst = strtr(mb_strtolower($sellerParts[1]), $translitMap);
        $sellerTranslitLast = strtr(mb_strtolower($sellerParts[0]), $translitMap);
        $sellerEmailLocal = explode('@', $result[0]['seller_email'])[0];
        $this->assertStringStartsWith($sellerTranslitFirst . '.' . $sellerTranslitLast, $sellerEmailLocal);

        // buyer_email соответствует buyer_fio
        $buyerParts = explode(' ', $result[0]['buyer_fio']);
        $buyerTranslitFirst = strtr(mb_strtolower($buyerParts[1]), $translitMap);
        $buyerTranslitLast = strtr(mb_strtolower($buyerParts[0]), $translitMap);
        $buyerEmailLocal = explode('@', $result[0]['buyer_email'])[0];
        $this->assertStringStartsWith($buyerTranslitFirst . '.' . $buyerTranslitLast, $buyerEmailLocal);
    }

    public function testSingleAnchorBackwardCompatible(): void
    {
        // С одним якорем поведение идентично старому
        $fakerConfig = [
            'full_name' => PatternDetector::PATTERN_FIO,
            'short_name' => PatternDetector::PATTERN_FIO_SHORT,
            'email' => PatternDetector::PATTERN_EMAIL,
        ];
        $rows = [
            ['id' => 1, 'full_name' => 'Тестов Тест Тестович', 'short_name' => 'Тестов Т.Т.', 'email' => 'test@example.com'],
        ];
        $result = $this->faker->apply('public', 'users', $fakerConfig, $rows);

        // Все колонки согласованы (один человек)
        $fioParts = explode(' ', $result[0]['full_name']);
        $expectedShort = $fioParts[0] . ' ' . mb_substr($fioParts[1], 0, 1) . '.' . mb_substr($fioParts[2], 0, 1) . '.';
        $this->assertEquals($expectedShort, $result[0]['short_name']);
    }

    public function testFioWithAllLinkedComponents(): void
    {
        $fakerConfig = [
            'full_name' => PatternDetector::PATTERN_FIO,
            'first_name' => PatternDetector::PATTERN_FIRSTNAME,
            'last_name' => PatternDetector::PATTERN_LASTNAME,
            'patronymic' => PatternDetector::PATTERN_PATRONYMIC,
        ];
        $rows = [
            [
                'id' => 1,
                'full_name' => 'Тестов Тест Тестович',
                'first_name' => 'Тест',
                'last_name' => 'Тестов',
                'patronymic' => 'Тестович',
            ],
        ];
        $result = $this->faker->apply('public', 'users', $fakerConfig, $rows);

        $fioParts = explode(' ', $result[0]['full_name']);
        $this->assertCount(3, $fioParts);
        $this->assertEquals($fioParts[0], $result[0]['last_name']);
        $this->assertEquals($fioParts[1], $result[0]['first_name']);
        $this->assertEquals($fioParts[2], $result[0]['patronymic']);
    }
}
