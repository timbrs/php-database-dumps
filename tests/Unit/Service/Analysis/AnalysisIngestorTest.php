<?php

namespace Timbrs\DatabaseDumps\Tests\Unit\Service\Analysis;

use PHPUnit\Framework\TestCase;
use Timbrs\DatabaseDumps\Contract\FileSystemInterface;
use Timbrs\DatabaseDumps\Contract\LoggerInterface;
use Timbrs\DatabaseDumps\Service\Analysis\AnalysisIngestor;

class AnalysisIngestorTest extends TestCase
{
    /**
     * @param array<string, string> $files path => json content
     */
    private function ingestorWith(array $files): AnalysisIngestor
    {
        $fs = $this->createMock(FileSystemInterface::class);
        $fs->method('isDirectory')->willReturn(true);
        $fs->method('findFiles')->willReturn(array_keys($files));
        $fs->method('read')->willReturnCallback(function ($path) use ($files) {
            return $files[$path] ?? '';
        });

        return new AnalysisIngestor($fs, $this->createMock(LoggerInterface::class));
    }

    public function testParsesRelationshipsIntoCascadeFrom(): void
    {
        $json = json_encode([
            'relationships' => [
                [
                    'source_table' => 'public.orders',
                    'source_column' => 'client_id',
                    'target_table' => 'public.clients',
                    'target_column' => 'id',
                    'kind' => 'belongs_to',
                    'confidence' => 90,
                    'source' => 'code',
                ],
            ],
        ]);

        $result = $this->ingestorWith(['/out/public.json' => (string) $json])->ingest('/out');

        $this->assertCount(1, $result['cascade_from']);
        $cf = $result['cascade_from'][0];
        $this->assertSame('public', $cf['schema']);
        $this->assertSame('orders', $cf['table']);
        $this->assertSame('public.clients', $cf['parent']);
        $this->assertSame('client_id', $cf['fk_column']);
        $this->assertSame('id', $cf['parent_column']);
        $this->assertSame('code', $cf['source']);
        $this->assertSame(90, $cf['confidence']);
    }

    public function testParsesCriteriaIntoSampleCriteria(): void
    {
        $json = json_encode([
            'criteria' => [
                ['table' => 'public.clients', 'name' => 'active', 'sql_where' => "status = 'active'", 'limit' => 50, 'confidence' => 85],
                ['table' => 'public.clients', 'name' => 'vip', 'sql_where' => "tier = 'vip'"],
            ],
        ]);

        $result = $this->ingestorWith(['/out/public.json' => (string) $json])->ingest('/out');

        $this->assertCount(2, $result['sample_criteria']);
        $this->assertSame('public', $result['sample_criteria'][0]['schema']);
        $this->assertSame('clients', $result['sample_criteria'][0]['table']);
        $this->assertSame('active', $result['sample_criteria'][0]['name']);
        $this->assertSame("status = 'active'", $result['sample_criteria'][0]['where']);
        $this->assertSame(50, $result['sample_criteria'][0]['limit']);
        $this->assertNull($result['sample_criteria'][1]['limit']);
    }

    public function testMergesMultipleChunkFiles(): void
    {
        $files = [
            '/out/public.json' => (string) json_encode([
                'relationships' => [
                    ['source_table' => 'public.orders', 'source_column' => 'client_id', 'target_table' => 'public.clients', 'target_column' => 'id'],
                ],
            ]),
            '/out/billing.json' => (string) json_encode([
                'criteria' => [
                    ['table' => 'billing.invoices', 'name' => 'overdue', 'sql_where' => 'due_at < NOW()'],
                ],
            ]),
        ];

        $result = $this->ingestorWith($files)->ingest('/out');
        $this->assertCount(1, $result['cascade_from']);
        $this->assertCount(1, $result['sample_criteria']);
        $this->assertCount(2, $result['files']);
    }

    public function testSkipsInvalidEntries(): void
    {
        $json = json_encode([
            'relationships' => [
                ['source_table' => 'no_dot_here', 'source_column' => 'x', 'target_table' => 'public.y', 'target_column' => 'id'],
                ['source_table' => 'public.orders', 'source_column' => 'bad;col', 'target_table' => 'public.clients', 'target_column' => 'id'],
            ],
            'criteria' => [
                ['table' => 'public.clients', 'name' => 'bad-name', 'sql_where' => 'x = 1'],
                ['table' => 'public.clients', 'name' => 'ok', 'sql_where' => 'x = 1'],
            ],
        ]);

        $result = $this->ingestorWith(['/out/public.json' => (string) $json])->ingest('/out');
        $this->assertCount(0, $result['cascade_from']);
        $this->assertCount(1, $result['sample_criteria']);
        $this->assertSame('ok', $result['sample_criteria'][0]['name']);
    }

    public function testSkipsInvalidJsonFile(): void
    {
        $result = $this->ingestorWith(['/out/broken.json' => 'not json {'])->ingest('/out');
        $this->assertSame([], $result['cascade_from']);
        $this->assertSame([], $result['files']);
    }

    public function testMissingDirectoryReturnsEmpty(): void
    {
        $fs = $this->createMock(FileSystemInterface::class);
        $fs->method('isDirectory')->willReturn(false);
        $ingestor = new AnalysisIngestor($fs, $this->createMock(LoggerInterface::class));

        $result = $ingestor->ingest('/nonexistent');
        $this->assertSame([], $result['cascade_from']);
        $this->assertSame([], $result['sample_criteria']);
    }

    public function testCollectsColumnUsage(): void
    {
        $json = json_encode([
            'columns' => [
                ['table' => 'public.clients', 'column' => 'status', 'usages' => ['filter'], 'is_key' => true],
            ],
        ]);
        $result = $this->ingestorWith(['/out/public.json' => (string) $json])->ingest('/out');
        $this->assertCount(1, $result['columns']);
        $this->assertSame('status', $result['columns'][0]['column']);
    }

    public function testClampsConfidenceToRange(): void
    {
        $json = json_encode([
            'relationships' => [
                ['source_table' => 'public.orders', 'source_column' => 'client_id', 'target_table' => 'public.clients', 'target_column' => 'id', 'confidence' => 250],
                ['source_table' => 'public.items', 'source_column' => 'order_id', 'target_table' => 'public.orders', 'target_column' => 'id', 'confidence' => -5],
            ],
        ]);
        $result = $this->ingestorWith(['/out/public.json' => (string) $json])->ingest('/out');
        $this->assertCount(2, $result['cascade_from']);
        $this->assertSame(100, $result['cascade_from'][0]['confidence']);
        $this->assertSame(0, $result['cascade_from'][1]['confidence']);
    }

    public function testNormalizesInvalidLimitToNull(): void
    {
        $json = json_encode([
            'criteria' => [
                ['table' => 'public.clients', 'name' => 'zero', 'sql_where' => 'x = 1', 'limit' => 0],
                ['table' => 'public.clients', 'name' => 'neg', 'sql_where' => 'x = 1', 'limit' => -10],
                ['table' => 'public.clients', 'name' => 'str', 'sql_where' => 'x = 1', 'limit' => '25'],
            ],
        ]);
        $result = $this->ingestorWith(['/out/public.json' => (string) $json])->ingest('/out');
        $this->assertCount(3, $result['sample_criteria']);
        $this->assertNull($result['sample_criteria'][0]['limit']);
        $this->assertNull($result['sample_criteria'][1]['limit']);
        $this->assertSame(25, $result['sample_criteria'][2]['limit']);
    }

    public function testRejectsNonScalarTableAndColumnFields(): void
    {
        // Нескалярные поля (массив вместо строки) не должны вызывать ошибку и отбрасываются.
        $json = json_encode([
            'relationships' => [
                ['source_table' => ['x'], 'source_column' => 'c', 'target_table' => 'public.y', 'target_column' => 'id'],
                ['source_table' => 'public.orders', 'source_column' => ['arr'], 'target_table' => 'public.clients', 'target_column' => 'id'],
            ],
            'criteria' => [
                ['table' => ['public', 'x'], 'name' => 'bad', 'sql_where' => 'x = 1'],
            ],
        ]);
        $result = $this->ingestorWith(['/out/public.json' => (string) $json])->ingest('/out');
        $this->assertCount(0, $result['cascade_from']);
        $this->assertCount(0, $result['sample_criteria']);
        // Файл валиден (распарсился), поэтому учтён в files.
        $this->assertCount(1, $result['files']);
    }

    public function testRejectsPathTraversalInTableName(): void
    {
        // Недоверенный вывод агента с попыткой path traversal в имени схемы/таблицы.
        $json = json_encode([
            'relationships' => [
                ['source_table' => '../../etc.passwd', 'source_column' => 'id', 'target_table' => 'public.clients', 'target_column' => 'id'],
                ['source_table' => 'public.orders', 'source_column' => 'cid', 'target_table' => '../secrets.x', 'target_column' => 'id'],
            ],
            'criteria' => [
                ['table' => 'public/../../evil.t', 'name' => 'x', 'sql_where' => 'a = 1'],
            ],
        ]);
        $result = $this->ingestorWith(['/out/public.json' => (string) $json])->ingest('/out');
        $this->assertCount(0, $result['cascade_from']);
        $this->assertCount(0, $result['sample_criteria']);
    }

    public function testSurvivesUnreadableFile(): void
    {
        $fs = $this->createMock(FileSystemInterface::class);
        $fs->method('isDirectory')->willReturn(true);
        $fs->method('findFiles')->willReturn(['/out/a.json', '/out/b.json']);
        $fs->method('read')->willReturnCallback(function ($path) {
            if ($path === '/out/a.json') {
                throw new \RuntimeException('I/O error');
            }
            return (string) json_encode([
                'criteria' => [['table' => 'public.clients', 'name' => 'ok', 'sql_where' => 'x = 1']],
            ]);
        });

        $ingestor = new AnalysisIngestor($fs, $this->createMock(LoggerInterface::class));
        $result = $ingestor->ingest('/out');

        // Сбой чтения одного файла не валит ингест — второй обработан.
        $this->assertCount(1, $result['sample_criteria']);
        $this->assertCount(1, $result['files']);
    }

    public function testTopLevelJsonArrayProducesNothing(): void
    {
        // json_decode('[]') => [] (массив) — не должно падать, ключей нет.
        $result = $this->ingestorWith(['/out/public.json' => '[]'])->ingest('/out');
        $this->assertCount(0, $result['cascade_from']);
        $this->assertCount(0, $result['sample_criteria']);
        $this->assertCount(1, $result['files']);
    }
}
