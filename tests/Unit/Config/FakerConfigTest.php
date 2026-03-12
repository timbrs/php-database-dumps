<?php

namespace Timbrs\DatabaseDumps\Tests\Unit\Config;

use PHPUnit\Framework\TestCase;
use Timbrs\DatabaseDumps\Config\FakerConfig;

class FakerConfigTest extends TestCase
{
    public function testGetTableFakerReturnsColumnsForExistingTable(): void
    {
        $config = new FakerConfig([
            'public' => [
                'users' => [
                    'email' => 'email',
                    'name' => 'name',
                ],
            ],
        ]);

        $result = $config->getTableFaker('public', 'users');

        $this->assertSame(['email' => 'email', 'name' => 'name'], $result);
    }

    public function testGetTableFakerReturnsNullForNonExistentTable(): void
    {
        $config = new FakerConfig([
            'public' => [
                'users' => [
                    'email' => 'email',
                ],
            ],
        ]);

        $this->assertNull($config->getTableFaker('public', 'orders'));
    }

    public function testGetTableFakerReturnsNullForNonExistentSchema(): void
    {
        $config = new FakerConfig([
            'public' => [
                'users' => [
                    'email' => 'email',
                ],
            ],
        ]);

        $this->assertNull($config->getTableFaker('private', 'users'));
    }

    public function testToArrayReturnsFullConfig(): void
    {
        $data = [
            'public' => [
                'users' => [
                    'email' => 'email',
                    'name' => 'name',
                ],
            ],
            'billing' => [
                'invoices' => [
                    'address' => 'address',
                ],
            ],
        ];

        $config = new FakerConfig($data);

        $this->assertSame($data, $config->toArray());
    }

    public function testIsEmptyReturnsTrueForEmptyConfig(): void
    {
        $config = new FakerConfig([]);

        $this->assertTrue($config->isEmpty());
    }

    public function testIsEmptyReturnsFalseForNonEmptyConfig(): void
    {
        $config = new FakerConfig([
            'public' => [
                'users' => [
                    'email' => 'email',
                ],
            ],
        ]);

        $this->assertFalse($config->isEmpty());
    }

    public function testEmptyConstructor(): void
    {
        $config = new FakerConfig();

        $this->assertTrue($config->isEmpty());
        $this->assertSame([], $config->toArray());
    }
}
