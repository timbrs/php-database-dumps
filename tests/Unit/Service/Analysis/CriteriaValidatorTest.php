<?php

namespace Timbrs\DatabaseDumps\Tests\Unit\Service\Analysis;

use PHPUnit\Framework\TestCase;
use Timbrs\DatabaseDumps\Service\Analysis\CriteriaValidator;

class CriteriaValidatorTest extends TestCase
{
    /** @var CriteriaValidator */
    private $validator;

    protected function setUp(): void
    {
        $this->validator = new CriteriaValidator();
    }

    public function testCleanWhereHasNoSyntaxProblems(): void
    {
        $this->assertSame([], $this->validator->syntaxProblems("active_flg = 1 AND NOW() BETWEEN date_from AND date_to"));
        $this->assertTrue($this->validator->isDumpable("work_status = 'active'"));
    }

    public function testDetectsTableAlias(): void
    {
        $problems = $this->validator->syntaxProblems('t1.active_flg = 1');
        $this->assertNotEmpty($problems);
        $this->assertStringContainsString('алиас', $problems[0]);
        $this->assertFalse($this->validator->isDumpable('t2.status = 1'));
    }

    public function testDetectsBindParameter(): void
    {
        $problems = $this->validator->syntaxProblems('login = :login');
        $this->assertNotEmpty($problems);
        $this->assertStringContainsString('bind-параметр', implode(' ', $problems));
        $this->assertFalse($this->validator->isDumpable('active_flg = :flag'));
    }

    public function testDetectsBothAliasAndParam(): void
    {
        $problems = $this->validator->syntaxProblems('t1.active_flg = :flag AND :now BETWEEN t1.date_from AND t1.date_to');
        $this->assertCount(2, $problems);
    }

    public function testCastAndTimeLiteralAreNotBindParams(): void
    {
        // Postgres-каст ::text и время '12:30:00' не должны считаться bind-параметром/алиасом.
        $this->assertTrue($this->validator->isDumpable("status::text = 'active'"));
        $this->assertTrue($this->validator->isDumpable("created_at::time > '12:30:00'"));
    }

    public function testUnknownColumnsFlaggedAgainstInventory(): void
    {
        $columns = ['active_flg', 'date_from', 'date_to', 'work_status'];

        // camelCase-имя свойства ORM — нет такой колонки в БД.
        $problems = $this->validator->problems('activeFlg = 1', $columns);
        $this->assertNotEmpty($problems);
        $this->assertStringContainsString('activeflg', implode(' ', $problems));

        // Реальные колонки + функции/литералы — чисто.
        $this->assertSame([], $this->validator->problems(
            "active_flg = 1 AND NOW() BETWEEN date_from AND date_to AND work_status = 'x'",
            $columns
        ));
    }

    public function testColumnCheckSkippedWithoutInventory(): void
    {
        // Без списка колонок сверки нет — только синтаксис.
        $this->assertSame([], $this->validator->problems('whatever_column = 1'));
    }

    public function testCaseInsensitiveColumnMatch(): void
    {
        // PG сворачивает неэкранированные идентификаторы к нижнему регистру — USER_ID == user_id.
        $this->assertSame([], $this->validator->problems('USER_ID = 5', ['user_id']));
    }

    public function testStringLiteralWithDotNotTreatedAsColumn(): void
    {
        // Значение в кавычках с точкой/словами не должно давать «неизвестную колонку».
        $this->assertSame([], $this->validator->problems("email = 'user@bank.ru'", ['email']));
    }
}
