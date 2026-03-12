<?php

namespace Timbrs\DatabaseDumps\Tests\Unit\Service\Generator;

use PHPUnit\Framework\TestCase;
use Timbrs\DatabaseDumps\Contract\ConnectionRegistryInterface;
use Timbrs\DatabaseDumps\Platform\PostgresPlatform;
use Timbrs\DatabaseDumps\Service\Generator\TruncateGenerator;

class TruncateGeneratorTest extends TestCase
{
    /** @var TruncateGenerator */
    private $generator;

    protected function setUp(): void
    {
        $platform = new PostgresPlatform();

        $registry = $this->createMock(ConnectionRegistryInterface::class);
        $registry->method('getPlatform')->willReturn($platform);

        $this->generator = new TruncateGenerator($registry);
    }

    public function testGenerate(): void
    {
        $sql = $this->generator->generate('users', 'users');

        $this->assertEquals('TRUNCATE TABLE "users"."users" CASCADE;', $sql);
    }

    public function testGenerateWithDifferentSchema(): void
    {
        $sql = $this->generator->generate('clients', 'clients');

        $this->assertEquals('TRUNCATE TABLE "clients"."clients" CASCADE;', $sql);
    }

    public function testGenerateQuotesIdentifiers(): void
    {
        $sql = $this->generator->generate('test_schema', 'test_table');

        $this->assertStringContainsString('"test_schema"', $sql);
        $this->assertStringContainsString('"test_table"', $sql);
    }

    public function testGenerateIncludesCascade(): void
    {
        $sql = $this->generator->generate('users', 'users');

        $this->assertStringContainsString('CASCADE', $sql);
    }
}
