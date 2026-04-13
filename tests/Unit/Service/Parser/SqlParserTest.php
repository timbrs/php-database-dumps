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

    public function testIsValidReturnsTrueForSelectStatement(): void
    {
        $this->assertTrue($this->parser->isValid("SELECT * FROM users"));
    }

    public function testIsValidReturnsTrueForInsertStatement(): void
    {
        $this->assertTrue($this->parser->isValid("INSERT INTO users VALUES (1)"));
    }

    public function testIsValidReturnsTrueForUpdateStatement(): void
    {
        $this->assertTrue($this->parser->isValid("UPDATE users SET name = 'test'"));
    }

    public function testIsValidReturnsTrueForDeleteStatement(): void
    {
        $this->assertTrue($this->parser->isValid("DELETE FROM users WHERE id = 1"));
    }

    public function testIsValidReturnsTrueForTruncateStatement(): void
    {
        $this->assertTrue($this->parser->isValid("TRUNCATE TABLE users"));
    }

    public function testIsValidReturnsTrueForSetStatement(): void
    {
        $this->assertTrue($this->parser->isValid("SET work_mem = '256MB'"));
    }

    public function testIsValidReturnsFalseForEmptyString(): void
    {
        $this->assertFalse($this->parser->isValid(""));
    }

    public function testIsValidReturnsFalseForWhitespace(): void
    {
        $this->assertFalse($this->parser->isValid("   "));
    }

    public function testIsValidReturnsFalseForRandomText(): void
    {
        $this->assertFalse($this->parser->isValid("random text"));
    }

    public function testIsValidIsCaseInsensitive(): void
    {
        $this->assertTrue($this->parser->isValid("select * from users"));
        $this->assertTrue($this->parser->isValid("SELECT * FROM users"));
        $this->assertTrue($this->parser->isValid("SeLeCt * FrOm users"));
    }

    public function testParseColumnListFromInsert(): void
    {
        $sql = 'INSERT INTO "public"."users" ("id", "name", "email") VALUES (1, \'Test\', \'test@test.com\');';
        $columns = $this->parser->parseColumnList($sql);

        $this->assertEquals(['id', 'name', 'email'], $columns);
    }

    public function testParseColumnListWithBackticks(): void
    {
        $sql = 'INSERT INTO `mydb`.`users` (`id`, `name`, `email`) VALUES (1, \'Test\', \'test@test.com\');';
        $columns = $this->parser->parseColumnList($sql);

        $this->assertEquals(['id', 'name', 'email'], $columns);
    }

    public function testParseColumnListWithHeaderComments(): void
    {
        $sql = "-- Дамп таблицы: public.users\n-- Дата: 2024-01-01\n\nTRUNCATE TABLE \"public\".\"users\" CASCADE;\n\nINSERT INTO \"public\".\"users\" (\"id\", \"name\") VALUES\n(1, 'Test');";
        $columns = $this->parser->parseColumnList($sql);

        $this->assertEquals(['id', 'name'], $columns);
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
}
