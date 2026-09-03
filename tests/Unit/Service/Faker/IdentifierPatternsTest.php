<?php

namespace Timbrs\DatabaseDumps\Tests\Unit\Service\Faker;

use PHPUnit\Framework\TestCase;
use Timbrs\DatabaseDumps\Service\Faker\PatternDetector;
use Timbrs\DatabaseDumps\Service\Faker\RussianFaker;

class IdentifierPatternsTest extends TestCase
{
    public function testInnKeepsLengthAndChecksum(): void
    {
        $rows = $this->apply(['inn' => PatternDetector::PATTERN_INN], [
            ['id' => 1, 'inn' => '7707083893'],
            ['id' => 2, 'inn' => '500100732259'],
        ]);

        self::assertSame(10, strlen($rows[0]['inn']));
        self::assertSame(12, strlen($rows[1]['inn']));
        self::assertNotSame('7707083893', $rows[0]['inn']);
        self::assertTrue($this->innIsValid($rows[0]['inn']), 'десятизначный ИНН должен проходить контроль');
        self::assertTrue($this->innIsValid($rows[1]['inn']), 'двенадцатизначный ИНН должен проходить контроль');
    }

    /**
     * Ключевое свойство: по ИНН связывают клиента и лид, поэтому одинаковый вход обязан дать
     * одинаковый выход в разных таблицах и разных строках.
     */
    public function testValueSeededPatternsAreStableAcrossTablesAndRows(): void
    {
        $clients = $this->apply(['inn' => PatternDetector::PATTERN_INN], [['id' => 1, 'inn' => '7707083893']], 'clients');
        $leads = $this->apply(['inn' => PatternDetector::PATTERN_INN], [['id' => 999, 'inn' => '7707083893']], 'leads');

        self::assertSame($clients[0]['inn'], $leads[0]['inn']);
    }

    public function testDigitsKeepShapeAndSeparators(): void
    {
        $rows = $this->apply(['kpp' => PatternDetector::PATTERN_DIGITS], [['id' => 1, 'kpp' => '770-101-001']]);

        self::assertMatchesRegularExpression('/^\d{3}-\d{3}-\d{3}$/', $rows[0]['kpp']);
        self::assertNotSame('770-101-001', $rows[0]['kpp']);
    }

    public function testBirthDateShiftsButKeepsFormat(): void
    {
        $rows = $this->apply(['born' => PatternDetector::PATTERN_BIRTH_DATE], [
            ['id' => 1, 'born' => '1980-05-17'],
            ['id' => 2, 'born' => '1980-05-17 10:20:30'],
            ['id' => 3, 'born' => 'не дата'],
        ]);

        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $rows[0]['born']);
        self::assertNotSame('1980-05-17', $rows[0]['born']);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $rows[1]['born']);
        self::assertSame('не дата', $rows[2]['born'], 'неразобранное значение остаётся как есть');

        $shift = abs(strtotime($rows[0]['born']) - strtotime('1980-05-17')) / 86400;
        self::assertLessThanOrEqual(180, $shift);
    }

    public function testOgrnKeepsLengthAndControlDigit(): void
    {
        $rows = $this->apply(['ogrn' => PatternDetector::PATTERN_OGRN], [
            ['id' => 1, 'ogrn' => '1027700132195'],
            ['id' => 2, 'ogrn' => '304500116000157'],
        ]);

        self::assertSame(13, strlen($rows[0]['ogrn']));
        self::assertSame(15, strlen($rows[1]['ogrn']));
        self::assertSame($this->ogrnControl($rows[0]['ogrn']), substr($rows[0]['ogrn'], -1));
        self::assertSame($this->ogrnControl($rows[1]['ogrn']), substr($rows[1]['ogrn'], -1));
    }

    public function testNewPatternsAreAllowedByConfig(): void
    {
        foreach (PatternDetector::VALUE_SEEDED_PATTERNS as $pattern) {
            self::assertContains($pattern, PatternDetector::ALLOWED_PATTERNS, $pattern);
        }
    }

    /**
     * @param array<string, string>              $faker
     * @param array<int, array<string, mixed>>   $rows
     * @return array<int, array<string, mixed>>
     */
    private function apply(array $faker, array $rows, string $table = 'clients'): array
    {
        return (new RussianFaker())->apply('public', $table, $faker, $rows);
    }

    private function innIsValid(string $inn): bool
    {
        $weights10 = [2, 4, 10, 3, 5, 9, 4, 6, 8];
        $weights11 = [7, 2, 4, 10, 3, 5, 9, 4, 6, 8];
        $weights12 = [3, 7, 2, 4, 10, 3, 5, 9, 4, 6, 8];

        $checksum = function (string $digits, array $weights): int {
            $sum = 0;
            foreach ($weights as $i => $weight) {
                $sum += ((int) $digits[$i]) * $weight;
            }

            return ($sum % 11) % 10;
        };

        if (strlen($inn) === 10) {
            return $checksum($inn, $weights10) === (int) $inn[9];
        }

        return $checksum($inn, $weights11) === (int) $inn[10]
            && $checksum($inn, $weights12) === (int) $inn[11];
    }

    private function ogrnControl(string $ogrn): string
    {
        $body = substr($ogrn, 0, -1);
        $divisor = strlen($ogrn) === 13 ? 11 : 13;
        $remainder = 0;
        for ($i = 0; $i < strlen($body); $i++) {
            $remainder = ($remainder * 10 + (int) $body[$i]) % $divisor;
        }

        return (string) ($remainder % 10);
    }
}
