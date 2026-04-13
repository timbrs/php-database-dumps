<?php

namespace Timbrs\DatabaseDumps\Tests\Unit\Service\Generator;

use PHPUnit\Framework\TestCase;
use Timbrs\DatabaseDumps\Contract\ConnectionRegistryInterface;
use Timbrs\DatabaseDumps\Contract\DatabaseConnectionInterface;
use Timbrs\DatabaseDumps\Platform\PostgresPlatform;
use Timbrs\DatabaseDumps\Service\Generator\DeferredUpdateGenerator;

class DeferredUpdateGeneratorTest extends TestCase
{
    /** @var DatabaseConnectionInterface&\PHPUnit\Framework\MockObject\MockObject */
    private $connection;

    /** @var DeferredUpdateGenerator */
    private $generator;

    protected function setUp(): void
    {
        $this->connection = $this->createMock(DatabaseConnectionInterface::class);
        $this->connection->method('quote')->willReturnCallback(function ($value) {
            return "'{$value}'";
        });

        $platform = new PostgresPlatform();

        $registry = $this->createMock(ConnectionRegistryInterface::class);
        $registry->method('getConnection')->willReturn($this->connection);
        $registry->method('getPlatform')->willReturn($platform);

        $this->generator = new DeferredUpdateGenerator($registry);
    }

    public function testGenerateWithDeferredValues(): void
    {
        $deferredColumns = [
            ['column' => 'parent_id', 'reference_table' => 'public.categories', 'reference_column' => 'id']
        ];

        $deferredValues = [
            ['pk_column' => 'id', 'pk_value' => 1, 'column' => 'parent_id', 'value' => 5],
            ['pk_column' => 'id', 'pk_value' => 2, 'column' => 'parent_id', 'value' => 10]
        ];

        $sql = $this->generator->generate('public', 'categories', $deferredColumns, $deferredValues);

        $this->assertStringContainsString('Восстановление отложенных FK-столбцов', $sql);
        $this->assertStringContainsString('UPDATE "public"."categories" SET "parent_id" = \'5\' WHERE "id" = \'1\';', $sql);
        $this->assertStringContainsString('UPDATE "public"."categories" SET "parent_id" = \'10\' WHERE "id" = \'2\';', $sql);
    }

    public function testGenerateSkipsNullValues(): void
    {
        $deferredColumns = [
            ['column' => 'parent_id', 'reference_table' => 'public.categories', 'reference_column' => 'id']
        ];

        $deferredValues = [
            ['pk_column' => 'id', 'pk_value' => 1, 'column' => 'parent_id', 'value' => 5],
            ['pk_column' => 'id', 'pk_value' => 2, 'column' => 'parent_id', 'value' => null],
            ['pk_column' => 'id', 'pk_value' => 3, 'column' => 'parent_id', 'value' => 7]
        ];

        $sql = $this->generator->generate('public', 'categories', $deferredColumns, $deferredValues);

        $this->assertStringContainsString('UPDATE "public"."categories" SET "parent_id" = \'5\' WHERE "id" = \'1\';', $sql);
        $this->assertStringNotContainsString("\"id\" = '2'", $sql);
        $this->assertStringContainsString('UPDATE "public"."categories" SET "parent_id" = \'7\' WHERE "id" = \'3\';', $sql);
    }

    public function testGenerateEmptyValues(): void
    {
        $deferredColumns = [
            ['column' => 'parent_id', 'reference_table' => 'public.categories', 'reference_column' => 'id']
        ];

        $sql = $this->generator->generate('public', 'categories', $deferredColumns, array());

        $this->assertSame('', $sql);
    }

    public function testGenerateChunksYieldsContent(): void
    {
        $deferredColumns = [
            ['column' => 'parent_id', 'reference_table' => 'public.categories', 'reference_column' => 'id']
        ];

        $deferredValues = [
            ['pk_column' => 'id', 'pk_value' => 1, 'column' => 'parent_id', 'value' => 5]
        ];

        $chunks = iterator_to_array(
            $this->generator->generateChunks('public', 'categories', $deferredColumns, $deferredValues)
        );

        $this->assertCount(1, $chunks);
        $this->assertStringContainsString('UPDATE "public"."categories"', $chunks[0]);
        $this->assertStringContainsString('Восстановление отложенных FK-столбцов', $chunks[0]);
    }

    public function testGenerateChunksEmptyYieldsNothing(): void
    {
        $deferredColumns = [
            ['column' => 'parent_id', 'reference_table' => 'public.categories', 'reference_column' => 'id']
        ];

        $chunks = iterator_to_array(
            $this->generator->generateChunks('public', 'categories', $deferredColumns, array())
        );

        $this->assertCount(0, $chunks);
    }
}
