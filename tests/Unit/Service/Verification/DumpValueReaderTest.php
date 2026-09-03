<?php

namespace Timbrs\DatabaseDumps\Tests\Unit\Service\Verification;

use PHPUnit\Framework\TestCase;
use Timbrs\DatabaseDumps\Service\Verification\DumpValueReader;

/**
 * Читает настоящие файлы: парсер разбирает формат InsertGenerator, и подменять
 * файловую систему тут нечего — проверяется именно разбор текста.
 */
class DumpValueReaderTest extends TestCase
{
    /** @var string */
    private $dir;

    /** @var string */
    private $path;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/dumpreader_' . bin2hex(random_bytes(4));
        mkdir($this->dir);
        $this->path = $this->dir . '/persons_additional.sql';

        // Значения подобраны так, чтобы сломать наивный split: запятая, скобка,
        // экранированная кавычка, перевод строки и точка с запятой внутри литерала.
        file_put_contents($this->path, <<<'SQL'
-- table: persons.persons_additional
TRUNCATE TABLE "persons"."persons_additional" CASCADE;

INSERT INTO "persons"."persons_additional" ("id", "person_id", "note") VALUES
(1, 10, 'простая строка'),
(2, 11, 'с запятой, и скобкой ( внутри'),
(3, NULL, 'кавычка '' внутри'),
(4, 12, 'многострочное
значение с ; точкой с запятой'),
(5, 13, NULL);

INSERT INTO "persons"."persons_additional" ("id", "person_id", "note") VALUES (6, 14, 'вторая пачка');

SELECT setval('persons.persons_additional_id_seq', (SELECT MAX("id") FROM "persons"."persons_additional"));
SQL
        );
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir . '/*') ?: []);
        rmdir($this->dir);
    }

    public function testReadsColumnAcrossBatchesAndKeepsNulls(): void
    {
        $result = (new DumpValueReader())->readColumn($this->path, 'person_id');

        self::assertTrue($result['found']);
        self::assertSame(6, $result['rows']);
        self::assertSame(['10', '11', null, '12', '13', '14'], $result['values']);
    }

    public function testTrailingStatementsAreNotCountedAsRows(): void
    {
        // setval(...) в конце файла содержит скобки, но кортежем не является.
        $result = (new DumpValueReader())->readColumn($this->path, 'id');

        self::assertSame(['1', '2', '3', '4', '5', '6'], $result['values']);
    }

    public function testLiteralsSurviveCommasParensQuotesAndNewlines(): void
    {
        $result = (new DumpValueReader())->readColumn($this->path, 'note');

        self::assertCount(6, $result['values']);
        self::assertSame('с запятой, и скобкой ( внутри', $result['values'][1]);
        self::assertSame("кавычка ' внутри", $result['values'][2]);
        self::assertStringContainsString('точкой с запятой', (string) $result['values'][3]);
        self::assertNull($result['values'][4]);
    }

    public function testMissingColumnIsReportedButRowsStillCounted(): void
    {
        $result = (new DumpValueReader())->readColumn($this->path, 'no_such_column');

        self::assertFalse($result['found']);
        self::assertSame([], $result['values']);
        self::assertSame(6, $result['rows']);
    }

    public function testColumnNameMatchingIgnoresQuotesAndCase(): void
    {
        $result = (new DumpValueReader())->readColumn($this->path, '"PERSON_ID"');

        self::assertTrue($result['found']);
    }

    public function testUnreadableFileFails(): void
    {
        $this->expectException(\RuntimeException::class);

        (new DumpValueReader())->readColumn($this->dir . '/absent.sql', 'id');
    }

    public function testScanProjectsSeveralColumnsInOnePass(): void
    {
        $rows = [];
        $headers = [];
        $result = (new DumpValueReader())->scan(
            $this->path,
            ['person_id', 'note', 'absent'],
            function (array $row) use (&$rows): void {
                $rows[] = $row;
            },
            function (array $columns) use (&$headers): void {
                $headers[] = $columns;
            }
        );

        self::assertSame(['person_id' => true, 'note' => true, 'absent' => false], $result['found']);
        self::assertSame(6, $result['rows']);
        self::assertSame(['id', 'person_id', 'note'], $result['columns']);
        self::assertCount(2, $headers);
        self::assertCount(6, $rows);
        // Только запрошенные колонки: id в проекции нет, порядок — по шапке INSERT.
        self::assertSame(['person_id', 'note'], array_keys($rows[0]));
        self::assertSame('10', $rows[0]['person_id']);
        self::assertSame('с запятой, и скобкой ( внутри', $rows[1]['note']);
        self::assertNull($rows[2]['person_id']);
        self::assertSame(['person_id' => '14', 'note' => 'вторая пачка'], $rows[5]);
    }

    public function testScanAllColumnsUsesHeaderNames(): void
    {
        $rows = [];
        $result = (new DumpValueReader())->scan($this->path, [DumpValueReader::ALL_COLUMNS], function (array $row) use (&$rows): void {
            $rows[] = $row;
        });

        self::assertSame([], $result['found']);
        self::assertSame(['id' => '5', 'person_id' => '13', 'note' => null], $rows[4]);
    }

    public function testScanWithoutColumnsOnlyCountsRows(): void
    {
        $visited = 0;
        $result = (new DumpValueReader())->scan($this->path, [], function () use (&$visited): void {
            $visited++;
        });

        self::assertSame(6, $result['rows']);
        self::assertSame(0, $visited);
    }
}
