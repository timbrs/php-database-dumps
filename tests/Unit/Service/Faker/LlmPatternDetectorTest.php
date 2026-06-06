<?php

namespace Timbrs\DatabaseDumps\Tests\Unit\Service\Faker;

use PHPUnit\Framework\TestCase;
use Timbrs\DatabaseDumps\Contract\AiClientInterface;
use Timbrs\DatabaseDumps\Contract\ConnectionRegistryInterface;
use Timbrs\DatabaseDumps\Contract\DatabaseConnectionInterface;
use Timbrs\DatabaseDumps\Platform\PostgresPlatform;
use Timbrs\DatabaseDumps\Service\Faker\LlmPatternDetector;
use Timbrs\DatabaseDumps\Service\Faker\PatternDetector;

class LlmPatternDetectorTest extends TestCase
{
    /** @var AiClientInterface&\PHPUnit\Framework\MockObject\MockObject */
    private $aiClient;

    /** @var PatternDetector&\PHPUnit\Framework\MockObject\MockObject */
    private $regexDetector;

    /** @var DatabaseConnectionInterface&\PHPUnit\Framework\MockObject\MockObject */
    private $connection;

    /** @var ConnectionRegistryInterface&\PHPUnit\Framework\MockObject\MockObject */
    private $registry;

    protected function setUp(): void
    {
        $this->aiClient = $this->createMock(AiClientInterface::class);
        $this->regexDetector = $this->createMock(PatternDetector::class);

        $this->connection = $this->createMock(DatabaseConnectionInterface::class);
        $this->connection->method('getPlatformName')->willReturn('postgresql');
        $this->connection->method('fetchAllAssociative')->willReturn([
            ['email' => 'a@b.ru', 'full_name' => 'Иванов Иван Иванович', 'inn' => '7707083893', 'gender' => 'м'],
        ]);

        $this->registry = $this->createMock(ConnectionRegistryInterface::class);
        $this->registry->method('getConnection')->willReturn($this->connection);
        $this->registry->method('getPlatform')->willReturn(new PostgresPlatform());
    }

    private function detector(): LlmPatternDetector
    {
        return new LlmPatternDetector($this->aiClient, $this->regexDetector, $this->registry);
    }

    public function testMapsCanonicalResponseWithThresholdAndTypeMap(): void
    {
        $this->aiClient->method('isAvailable')->willReturn(true);
        $this->regexDetector->method('detect')->willReturn([]);

        $this->aiClient->method('chatJson')->willReturn([
            'columns' => [
                ['column_name' => 'email', 'pii_type' => 'email', 'confidence' => 95],
                ['column_name' => 'full_name', 'pii_type' => 'fio', 'confidence' => 90],
                ['column_name' => 'inn', 'pii_type' => 'inn_org', 'confidence' => 99], // не поддерживается → drop
                ['column_name' => 'gender', 'pii_type' => 'gender', 'confidence' => 50], // ниже порога → drop
            ],
        ]);

        $result = $this->detector()->detect('public', 'clients');

        $this->assertSame([
            'email' => PatternDetector::PATTERN_EMAIL,
            'full_name' => PatternDetector::PATTERN_FIO,
        ], $result);
    }

    public function testSetAiClientSwapsUnderlyingClient(): void
    {
        $this->aiClient->method('isAvailable')->willReturn(false);
        $detector = $this->detector();
        $this->assertFalse($detector->isAvailable());

        $newClient = $this->createMock(AiClientInterface::class);
        $newClient->method('isAvailable')->willReturn(true);

        $detector->setAiClient($newClient);
        $this->assertTrue($detector->isAvailable());
    }

    public function testSurnameMapsToLastname(): void
    {
        $this->aiClient->method('isAvailable')->willReturn(true);
        $this->regexDetector->method('detect')->willReturn([]);
        $this->aiClient->method('chatJson')->willReturn([
            'columns' => [
                ['column_name' => 'sname', 'pii_type' => 'surname', 'confidence' => 88],
            ],
        ]);

        $result = $this->detector()->detect('public', 'clients');
        $this->assertSame(['sname' => PatternDetector::PATTERN_LASTNAME], $result);
    }

    public function testNonePiiTypeDropped(): void
    {
        $this->aiClient->method('isAvailable')->willReturn(true);
        $this->regexDetector->method('detect')->willReturn([]);
        $this->aiClient->method('chatJson')->willReturn([
            'columns' => [
                ['column_name' => 'login', 'pii_type' => 'none', 'confidence' => 100],
            ],
        ]);

        $this->assertSame([], $this->detector()->detect('public', 'clients'));
    }

    public function testFallsBackToRegexWhenLlmUnavailable(): void
    {
        $this->aiClient->method('isAvailable')->willReturn(false);
        $this->aiClient->expects($this->never())->method('chatJson');
        $this->regexDetector->method('detect')->willReturn(['email' => PatternDetector::PATTERN_EMAIL]);

        $result = $this->detector()->detect('public', 'clients');
        $this->assertSame(['email' => PatternDetector::PATTERN_EMAIL], $result);
    }

    public function testFallsBackToRegexOnLlmError(): void
    {
        $this->aiClient->method('isAvailable')->willReturn(true);
        $this->aiClient->method('chatJson')->willThrowException(new \RuntimeException('boom'));
        $this->regexDetector->method('detect')->willReturn(['phone' => PatternDetector::PATTERN_PHONE]);

        $result = $this->detector()->detect('public', 'clients');
        $this->assertSame(['phone' => PatternDetector::PATTERN_PHONE], $result);
    }

    public function testIsAvailableDelegatesToClient(): void
    {
        $this->aiClient->method('isAvailable')->willReturn(true);
        $this->assertTrue($this->detector()->isAvailable());
    }

    public function testConfidenceAbove100StillAccepted(): void
    {
        $this->aiClient->method('isAvailable')->willReturn(true);
        $this->regexDetector->method('detect')->willReturn([]);
        $this->aiClient->method('chatJson')->willReturn([
            'columns' => [
                ['column_name' => 'email', 'pii_type' => 'email', 'confidence' => 150],
            ],
        ]);

        $this->assertSame(['email' => PatternDetector::PATTERN_EMAIL], $this->detector()->detect('public', 'c'));
    }

    public function testNegativeConfidenceRejected(): void
    {
        $this->aiClient->method('isAvailable')->willReturn(true);
        $this->regexDetector->method('detect')->willReturn([]);
        $this->aiClient->method('chatJson')->willReturn([
            'columns' => [
                ['column_name' => 'email', 'pii_type' => 'email', 'confidence' => -10],
            ],
        ]);

        $this->assertSame([], $this->detector()->detect('public', 'c'));
    }

    public function testEmptyColumnNameSkipped(): void
    {
        $this->aiClient->method('isAvailable')->willReturn(true);
        $this->regexDetector->method('detect')->willReturn([]);
        $this->aiClient->method('chatJson')->willReturn([
            'columns' => [
                ['column_name' => '', 'pii_type' => 'email', 'confidence' => 99],
            ],
        ]);

        $this->assertSame([], $this->detector()->detect('public', 'c'));
    }

    public function testMalformedResponseWithoutColumnsReturnsEmpty(): void
    {
        $this->aiClient->method('isAvailable')->willReturn(true);
        $this->regexDetector->method('detect')->willReturn([]);
        $this->aiClient->method('chatJson')->willReturn(['unexpected' => true]);

        $this->assertSame([], $this->detector()->detect('public', 'c'));
    }

    public function testNonArrayColumnEntrySkipped(): void
    {
        $this->aiClient->method('isAvailable')->willReturn(true);
        $this->regexDetector->method('detect')->willReturn([]);
        $this->aiClient->method('chatJson')->willReturn([
            'columns' => [
                'not-an-array',
                ['column_name' => 'email', 'pii_type' => 'email', 'confidence' => 90],
            ],
        ]);

        $this->assertSame(['email' => PatternDetector::PATTERN_EMAIL], $this->detector()->detect('public', 'c'));
    }

    public function testColumnCapAppliedOnHugeResponse(): void
    {
        $this->aiClient->method('isAvailable')->willReturn(true);
        $this->regexDetector->method('detect')->willReturn([]);

        // 2000 валидных колонок — детектор обязан обрезать до лимита 1000 и не упасть.
        $columns = [];
        for ($i = 0; $i < 2000; $i++) {
            $columns[] = ['column_name' => 'c' . $i, 'pii_type' => 'email', 'confidence' => 99];
        }
        $this->aiClient->method('chatJson')->willReturn(['columns' => $columns]);

        $result = $this->detector()->detect('public', 'c');
        $this->assertLessThanOrEqual(1000, count($result));
        $this->assertArrayHasKey('c0', $result);
        $this->assertArrayNotHasKey('c1999', $result);
    }

    public function testEmptySamplesFallBackToRegexHints(): void
    {
        $this->aiClient->method('isAvailable')->willReturn(true);
        $this->aiClient->expects($this->never())->method('chatJson');
        $this->regexDetector->method('detect')->willReturn(['x' => PatternDetector::PATTERN_FIO]);

        // Пустая таблица → нет сэмплов → возвращаем regex-хинты, LLM не дёргаем.
        $emptyConn = $this->createMock(DatabaseConnectionInterface::class);
        $emptyConn->method('getPlatformName')->willReturn('postgresql');
        $emptyConn->method('fetchAllAssociative')->willReturn([]);
        $registry = $this->createMock(ConnectionRegistryInterface::class);
        $registry->method('getConnection')->willReturn($emptyConn);
        $registry->method('getPlatform')->willReturn(new PostgresPlatform());

        $detector = new LlmPatternDetector($this->aiClient, $this->regexDetector, $registry);
        $this->assertSame(['x' => PatternDetector::PATTERN_FIO], $detector->detect('public', 'empty'));
    }

    public function testSystemPromptLoadsResourcesAndSubstitutesClassification(): void
    {
        // Через рефлексию проверяем, что buildSystemPrompt подставляет {CLASSIFICATION}.
        $detector = $this->detector();
        $ref = new \ReflectionMethod($detector, 'buildSystemPrompt');
        $ref->setAccessible(true);
        $prompt = $ref->invoke($detector);

        $this->assertStringNotContainsString('{CLASSIFICATION}', $prompt);
        $this->assertStringContainsString('классификации персональных данных', $prompt);
    }

    public function testSystemPromptFallsBackWhenResourceMissing(): void
    {
        // Подкласс с loadResource() => null имитирует отсутствие файлов-ресурсов.
        $detector = new class ($this->aiClient, $this->regexDetector, $this->registry) extends LlmPatternDetector {
            protected function loadResource(string $name)
            {
                return null;
            }
        };

        $ref = new \ReflectionMethod($detector, 'buildSystemPrompt');
        $ref->setAccessible(true);
        $prompt = $ref->invoke($detector);

        $this->assertStringContainsString('"columns"', $prompt);
        $this->assertStringNotContainsString('{CLASSIFICATION}', $prompt);
    }
}
