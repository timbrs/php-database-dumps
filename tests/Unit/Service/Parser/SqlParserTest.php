<?php

namespace Timbrs\DatabaseDumps\Tests\Unit\Service\Parser;

use PHPUnit\Framework\TestCase;
use Timbrs\DatabaseDumps\Service\Parser\SqlParser;
use Timbrs\DatabaseDumps\Service\Parser\StatementSplitter;

class SqlParserTest extends TestCase
{
    /** @var SqlParser */
    private $parser;

    protected function setUp(): void
    {
        $splitter = new StatementSplitter();
        $this->parser = new SqlParser($splitter);
    }

    public function testParseFile(): void
    {
        $sql = "SELECT * FROM users; SELECT * FROM orders;";
        $statements = $this->parser->parseFile($sql);
        $this->assertCount(2, $statements);
    }

    public function testParseColumnListFromInsert(): void
    {
        $sql = 'INSERT INTO "public"."users" ("id", "name", "email") VALUES (1, \'Test\', \'test@test.com\');';
        $columns = $this->parser->parseColumnList($sql);
        $this->assertSame(['id', 'name', 'email'], $columns);
    }

    public function testParseColumnListWithBackticks(): void
    {
        $sql = 'INSERT INTO `mydb`.`users` (`id`, `name`, `email`) VALUES (1, \'Test\', \'test@test.com\');';
        $columns = $this->parser->parseColumnList($sql);
        $this->assertSame(['id', 'name', 'email'], $columns);
    }

    public function testParseColumnListWithHeaderComments(): void
    {
        $sql = "-- Дамп таблицы: public.users\n-- Дата: 2024-01-01\n\nTRUNCATE TABLE \"public\".\"users\" CASCADE;\n\nINSERT INTO \"public\".\"users\" (\"id\", \"name\") VALUES\n(1, 'Test');";
        $columns = $this->parser->parseColumnList($sql);
        $this->assertSame(['id', 'name'], $columns);
    }

    public function testParseColumnListNoInsert(): void
    {
        $sql = "TRUNCATE TABLE users CASCADE;\n-- Таблица пуста";
        $columns = $this->parser->parseColumnList($sql);
        $this->assertNull($columns);
    }

    public function testParseColumnListEmptySql(): void
    {
        $columns = $this->parser->parseColumnList('');
        $this->assertNull($columns);
    }

    public function testParseFileSupportsDollarQuoted(): void
    {
        // PG dollar-quoted: ; внутри $$ ... $$ не должна разрывать statement
        $sql = "CREATE FUNCTION x() RETURNS void AS \$\$\nBEGIN\n  RAISE NOTICE 'a;b;c';\nEND;\n\$\$ LANGUAGE plpgsql;\nSELECT 1;";
        $statements = $this->parser->parseFile($sql);
        $this->assertCount(2, $statements);
    }

    public function testParseFileSupportsPlSqlBlock(): void
    {
        // Oracle PL/SQL BEGIN..END блок не должен разрываться по внутренним ;
        $sql = "BEGIN INSERT INTO t VALUES (1); UPDATE t SET x = 2; END;\nSELECT 1 FROM dual;";
        $statements = $this->parser->parseFile($sql);
        $this->assertCount(2, $statements);
    }
}
