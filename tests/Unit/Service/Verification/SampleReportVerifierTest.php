<?php

namespace Timbrs\DatabaseDumps\Tests\Unit\Service\Verification;

use PHPUnit\Framework\TestCase;
use Timbrs\DatabaseDumps\Config\TableConfig;
use Timbrs\DatabaseDumps\Contract\FileSystemInterface;
use Timbrs\DatabaseDumps\Service\Validation\Finding;
use Timbrs\DatabaseDumps\Service\Verification\DumpColumnStore;
use Timbrs\DatabaseDumps\Service\Verification\DumpValueReader;
use Timbrs\DatabaseDumps\Service\Verification\DumpVerificationInput;
use Timbrs\DatabaseDumps\Service\Verification\SampleReportVerifier;

class SampleReportVerifierTest extends TestCase
{
    private const REPORT = '/proj/docker/database/analysis/sample-report.json';

    /**
     * Пустую корзину не видит больше никто: критерий отработал, ошибки не было, просто
     * ничего не нашлось — а вида данных в дампе нет.
     */
    public function testEmptyBucketIsReported(): void
    {
        $findings = $this->verify([
            'tables' => [
                'tasks.activities' => [
                    'buckets' => [
                        ['name' => 'open', 'limit' => 100, 'rows' => 100, 'ms' => 12],
                        ['name' => 'overdue_closed', 'limit' => 100, 'rows' => 0, 'ms' => 8],
                    ],
                    'truncated_by_cap' => false,
                ],
            ],
        ]);

        $this->assertCount(1, $findings);
        $this->assertSame(SampleReportVerifier::CODE, $findings[0]->getCode());
        $this->assertSame(Finding::SEVERITY_WARNING, $findings[0]->getSeverity());
        $this->assertStringContainsString('overdue_closed', $findings[0]->getMessage());
    }

    /**
     * Упавшую корзину сборщик запросов пропускает молча — это ошибка, не предупреждение.
     */
    public function testFailedBucketIsAnError(): void
    {
        $findings = $this->verify([
            'tables' => [
                'tasks.activities' => [
                    'buckets' => [
                        ['name' => 'recent', 'limit' => 100, 'rows' => 0, 'error' => '42703 column t1.x does not exist'],
                    ],
                ],
            ],
        ]);

        $this->assertCount(1, $findings);
        $this->assertSame(Finding::SEVERITY_ERROR, $findings[0]->getSeverity());
        $this->assertStringContainsString('42703', $findings[0]->getMessage());
        // Пустой корзиной падение не считается: иначе на одну причину две находки.
        $this->assertStringNotContainsString('пуста', $findings[0]->getMessage());
    }

    public function testTruncationByCapIsReportedOncePerTable(): void
    {
        $findings = $this->verify([
            'tables' => [
                'tasks.activities' => [
                    'buckets' => [
                        ['name' => 'open', 'limit' => 25, 'rows' => 25],
                        ['name' => 'closed', 'limit' => 25, 'rows' => 25],
                    ],
                    'cap' => 50,
                    'selected' => 50,
                    'before_cap' => 200,
                    'truncated_by_cap' => true,
                ],
            ],
        ]);

        $this->assertCount(1, $findings);
        $this->assertStringContainsString('усечена потолком', $findings[0]->getMessage());
        $this->assertSame(200, $findings[0]->getSuggestion()['before_cap']);
    }

    public function testHealthyReportGivesNoFindings(): void
    {
        $findings = $this->verify([
            'tables' => [
                'tasks.activities' => [
                    'buckets' => [['name' => 'open', 'limit' => 100, 'rows' => 100]],
                    'truncated_by_cap' => false,
                ],
            ],
        ]);

        $this->assertSame([], $findings);
    }

    /**
     * Отчёт лежит от прошлого экспорта целиком, а проверять просили одну таблицу.
     */
    public function testOtherTablesFromAnOlderExportAreIgnored(): void
    {
        $findings = $this->verify([
            'tables' => [
                'tasks.activities' => ['buckets' => [['name' => 'open', 'rows' => 5]]],
                'clients.clients' => ['buckets' => [['name' => 'red', 'rows' => 0]]],
            ],
        ]);

        $this->assertSame([], $findings, 'Находка пришла по таблице, которую не проверяли.');
    }

    /**
     * Отчёта нет — выгрузка могла идти вообще без корзин; находка была бы шумом
     * на каждом полном экспорте.
     */
    public function testMissingReportIsSilent(): void
    {
        $verifier = new SampleReportVerifier($this->fileSystem([]));

        $this->assertSame([], $this->runVerifier($verifier));
        // Счётчики обнулены, а не пусты: строка из нулей в отчёте честнее отсутствия ключа.
        $this->assertSame(
            ['tables' => 0, 'empty_buckets' => 0, 'failed_buckets' => 0, 'truncated' => 0],
            $verifier->stats()
        );
    }

    public function testBrokenJsonIsSilent(): void
    {
        $verifier = new SampleReportVerifier($this->fileSystem([self::REPORT => 'не json']));

        $this->assertSame([], $this->runVerifier($verifier));
    }

    public function testStatsCountEachKindOfTrouble(): void
    {
        $verifier = new SampleReportVerifier($this->fileSystem([
            self::REPORT => (string) json_encode(['tables' => [
                'tasks.activities' => [
                    'buckets' => [
                        ['name' => 'a', 'rows' => 0],
                        ['name' => 'b', 'rows' => 0, 'error' => 'boom'],
                    ],
                    'truncated_by_cap' => true,
                    'selected' => 1,
                    'before_cap' => 9,
                ],
            ]]),
        ]));
        $this->runVerifier($verifier);

        $this->assertSame(
            ['tables' => 1, 'empty_buckets' => 1, 'failed_buckets' => 1, 'truncated' => 1],
            $verifier->stats()
        );
    }

    /**
     * @param array<string, mixed> $report
     *
     * @return array<int, Finding>
     */
    private function verify(array $report): array
    {
        $verifier = new SampleReportVerifier(
            $this->fileSystem([self::REPORT => (string) json_encode($report)])
        );

        return $this->runVerifier($verifier);
    }

    /**
     * @return array<int, Finding>
     */
    private function runVerifier(SampleReportVerifier $verifier): array
    {
        $input = new DumpVerificationInput(
            '/proj/docker/database/dumps',
            [new TableConfig('tasks', 'activities', 100)]
        );
        $store = new DumpColumnStore(new DumpValueReader());
        $verifier->plan($input, $store);

        return $verifier->check($input, $store);
    }

    /**
     * @param array<string, string> $files
     */
    private function fileSystem(array $files): FileSystemInterface
    {
        $fs = $this->createMock(FileSystemInterface::class);
        $fs->method('exists')->willReturnCallback(function ($path) use ($files) {
            return isset($files[$path]);
        });
        $fs->method('read')->willReturnCallback(function ($path) use ($files) {
            return isset($files[$path]) ? $files[$path] : '';
        });

        return $fs;
    }
}
