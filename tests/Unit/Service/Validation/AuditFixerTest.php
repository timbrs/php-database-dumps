<?php

namespace Timbrs\DatabaseDumps\Tests\Unit\Service\Validation;

use Symfony\Component\Yaml\Yaml;
use Timbrs\DatabaseDumps\Service\Validation\AuditFixer;
use Timbrs\DatabaseDumps\Service\Validation\ConfigAuditor;
use Timbrs\DatabaseDumps\Service\Validation\ConfigDocument;
use Timbrs\DatabaseDumps\Service\Validation\InventoryReader;
use Timbrs\DatabaseDumps\Tests\Support\InMemoryFileSystem;
use Timbrs\DatabaseDumps\Tests\Support\ValidationTestCase;

class AuditFixerTest extends ValidationTestCase
{
    private const PUB_FILE = '/proj/database/dump-settings/pub.yaml';
    private const OTHER_FILE = '/proj/database/dump-settings/other.yaml';

    /**
     * Прогнать полный цикл: аудит → автоправки → повторный аудит.
     *
     * @param array<string, string> $files
     * @return array{fs: InMemoryFileSystem, report: array<string, mixed>, codes_before: array<int, string>, codes_after: array<int, string>}
     */
    private function runFix(array $files): array
    {
        $fs = new InMemoryFileSystem($files);
        $auditor = new ConfigAuditor($fs);

        $before = $auditor->audit(self::CONFIG_PATH, new InventoryReader($fs, self::INVENTORY_PATH));
        $report = (new AuditFixer($fs))->fix(
            ConfigDocument::load($fs, self::CONFIG_PATH),
            $before->getFindings()
        );
        $after = $auditor->audit(self::CONFIG_PATH, new InventoryReader($fs, self::INVENTORY_PATH));

        return [
            'fs' => $fs,
            'report' => $report,
            'codes_before' => $this->codes($before->getFindings()),
            'codes_after' => $this->codes($after->getFindings()),
        ];
    }

    /**
     * @return array<string, array<string, array<string, mixed>>>
     */
    private function inventory(): array
    {
        return [
            'pub' => [
                'orders' => [
                    'row_count' => 100,
                    'columns' => [
                        'id' => 'bigint',
                        'client_id' => 'bigint',
                        'name' => 'character varying',
                        'created_at' => 'timestamp without time zone',
                        'status' => 'integer',
                    ],
                ],
                'clients' => ['row_count' => 10, 'columns' => ['id' => 'bigint']],
            ],
        ];
    }

    public function testRemovesFakerOnTimestamp(): void
    {
        $files = $this->splitConfig([
            'pub' => [
                'full_export' => ['clients'],
                'partial_export' => ['orders' => ['limit' => 10]],
                'faker' => ['orders' => ['name' => 'fio', 'created_at' => 'phone']],
            ],
        ]);
        $files[self::INVENTORY_PATH] = $this->inventoryJson($this->inventory());

        $result = $this->runFix($files);

        $this->assertSame(1, $result['report']['applied']);
        $this->assertSame(['F-1' => 1], $result['report']['by_code']);
        $this->assertContains('F-1', $result['codes_before']);
        $this->assertNotContains('F-1', $result['codes_after']);

        $written = Yaml::parse($result['fs']->read(self::PUB_FILE));
        $this->assertSame(['name' => 'fio'], $written['faker']['orders']);
    }

    public function testDropsFakerSectionWhenItBecomesEmpty(): void
    {
        $files = $this->splitConfig([
            'pub' => [
                'full_export' => ['clients'],
                'partial_export' => ['orders' => ['limit' => 10]],
                'faker' => ['orders' => ['created_at' => 'phone']],
            ],
        ]);
        $files[self::INVENTORY_PATH] = $this->inventoryJson($this->inventory());

        $result = $this->runFix($files);
        $written = Yaml::parse($result['fs']->read(self::PUB_FILE));

        $this->assertArrayNotHasKey('faker', $written);
        $this->assertNotContains('S-4', $result['codes_after']);
    }

    public function testRemovesUnknownPatternAndDeadCascade(): void
    {
        $files = $this->splitConfig([
            'pub' => [
                'full_export' => ['clients'],
                'partial_export' => ['orders' => [
                    'limit' => 10,
                    'cascade_from' => [
                        ['parent' => 'pub.clients', 'fk_column' => 'ghost_id', 'parent_column' => 'id'],
                        ['parent' => 'pub.clients', 'fk_column' => 'client_id', 'parent_column' => 'id'],
                    ],
                ]],
                'faker' => ['orders' => ['name' => 'passport_scan']],
            ],
        ]);
        $files[self::INVENTORY_PATH] = $this->inventoryJson($this->inventory());

        $result = $this->runFix($files);
        $written = Yaml::parse($result['fs']->read(self::PUB_FILE));

        $this->assertSame(2, $result['report']['applied']);
        $this->assertArrayNotHasKey('faker', $written);
        $this->assertCount(1, $written['partial_export']['orders']['cascade_from']);
        $this->assertSame('client_id', $written['partial_export']['orders']['cascade_from'][0]['fk_column']);
        $this->assertNotContains('L-3', $result['codes_after']);
        $this->assertNotContains('F-2', $result['codes_after']);
    }

    public function testRenamesDuplicateCriterion(): void
    {
        $files = $this->splitConfig([
            'pub' => [
                'full_export' => ['clients'],
                'partial_export' => ['orders' => [
                    'limit' => 500,
                    'sample' => ['criteria' => [
                        ['name' => 'active', 'where' => 'status = 1', 'limit' => 10],
                        ['name' => 'active', 'where' => 'status = 2', 'limit' => 10],
                    ]],
                ]],
            ],
        ]);
        $files[self::INVENTORY_PATH] = $this->inventoryJson($this->inventory());

        $result = $this->runFix($files);
        $written = Yaml::parse($result['fs']->read(self::PUB_FILE));
        $criteria = $written['partial_export']['orders']['sample']['criteria'];

        $this->assertSame('active', $criteria[0]['name']);
        $this->assertSame('active_2', $criteria[1]['name']);
        $this->assertNotContains('Q-4', $result['codes_after']);
    }

    public function testTouchesOnlyFilesWithFixes(): void
    {
        $files = $this->splitConfig([
            'pub' => [
                'full_export' => ['clients'],
                'partial_export' => ['orders' => ['limit' => 10]],
                'faker' => ['orders' => ['created_at' => 'phone']],
            ],
            'other' => ['partial_export' => ['stuff' => ['limit' => 10]]],
        ]);
        $files[self::INVENTORY_PATH] = $this->inventoryJson(array_merge(
            $this->inventory(),
            ['other' => ['stuff' => ['row_count' => 1, 'columns' => ['id' => 'bigint']]]]
        ));
        $otherBefore = $files[self::OTHER_FILE];

        $result = $this->runFix($files);

        // Путь резолвится средствами YamlConfigLoader, поэтому на Windows в нём
        // разделители ОС — сравниваем нормализованно.
        $touched = array_map(function ($path) {
            return str_replace('\\', '/', $path);
        }, $result['report']['files']);
        $this->assertSame([self::PUB_FILE], $touched);
        $this->assertSame($otherBefore, $result['fs']->read(self::OTHER_FILE));
    }

    public function testNothingToFixLeavesFilesIntact(): void
    {
        $files = $this->splitConfig([
            'pub' => [
                'full_export' => ['clients'],
                'partial_export' => ['orders' => ['limit' => 10]],
                'faker' => ['orders' => ['name' => 'fio']],
            ],
        ]);
        $files[self::INVENTORY_PATH] = $this->inventoryJson($this->inventory());
        $before = $files[self::PUB_FILE];

        $result = $this->runFix($files);

        $this->assertSame(0, $result['report']['applied']);
        $this->assertSame([], $result['report']['files']);
        $this->assertSame($before, $result['fs']->read(self::PUB_FILE));
    }

    /**
     * Конфиг без разбиения на файлы: правка должна попасть в общий dump_config.yaml,
     * под ключ схемы.
     */
    public function testFixesMonolithicConfig(): void
    {
        $files = [
            self::CONFIG_PATH => Yaml::dump([
                'full_export' => ['pub' => ['clients']],
                'partial_export' => ['pub' => ['orders' => ['limit' => 10]]],
                'faker' => ['pub' => ['orders' => ['created_at' => 'phone', 'name' => 'fio']]],
            ], 7, 2),
            self::INVENTORY_PATH => $this->inventoryJson($this->inventory()),
        ];

        $result = $this->runFix($files);
        $written = Yaml::parse($result['fs']->read(self::CONFIG_PATH));

        $this->assertSame(1, $result['report']['applied']);
        $this->assertSame(['name' => 'fio'], $written['faker']['pub']['orders']);
        $this->assertSame(['orders' => ['limit' => 10]], $written['partial_export']['pub']);
    }
}
