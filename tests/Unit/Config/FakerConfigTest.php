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
                    'phone' => 'phone',
                ],
            ],
        ];

        $config = new FakerConfig($data);

        $this->assertSame($data, $config->toArray());
    }

    public function testConstructorRejectsUnknownPattern(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new FakerConfig([
            'public' => [
                'users' => ['name' => 'fioo'], // опечатка — должно бросить
            ],
        ]);
    }

    public function testConstructorRejectsNonStringPattern(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new FakerConfig([
            'public' => [
                'users' => ['name' => 123],
            ],
        ]);
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

    /**
     * В русских БД колонки называются кириллицей (напр. «ФИО») — идентификатор
     * должен приниматься, а не рушить сборку DumpConfig.
     */
    public function testAcceptsCyrillicColumnIdentifier(): void
    {
        $config = new FakerConfig([
            'pdl' => [
                'sb_dsaap_dict_pyt_tp_umsb' => ['ФИО' => 'fio'],
            ],
        ]);

        $this->assertSame(['ФИО' => 'fio'], $config->getTableFaker('pdl', 'sb_dsaap_dict_pyt_tp_umsb'));
    }

    public function testAcceptsCyrillicSchemaAndTable(): void
    {
        $config = new FakerConfig([
            'справочник' => [
                'клиенты' => ['фамилия' => 'lastname'],
            ],
        ]);

        $this->assertSame(['фамилия' => 'lastname'], $config->getTableFaker('справочник', 'клиенты'));
    }

    /**
     * @dataProvider dangerousIdentifierProvider
     */
    public function testRejectsDangerousColumnIdentifier(string $column): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new FakerConfig([
            'public' => [
                'users' => [$column => 'fio'],
            ],
        ]);
    }

    /** @return array<string, array{0: string}> */
    public static function dangerousIdentifierProvider(): array
    {
        return [
            'double quote' => ['na"me'],
            'space' => ['full name'],
            'dot' => ['a.b'],
            'semicolon' => ['name;drop'],
            'slash' => ['a/b'],
            'dash' => ['full-name'],
        ];
    }
}
