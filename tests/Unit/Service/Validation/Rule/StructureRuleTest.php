<?php

namespace Timbrs\DatabaseDumps\Tests\Unit\Service\Validation\Rule;

use Symfony\Component\Yaml\Yaml;
use Timbrs\DatabaseDumps\Service\Validation\Finding;
use Timbrs\DatabaseDumps\Service\Validation\Rule\StructureRule;
use Timbrs\DatabaseDumps\Tests\Support\ValidationTestCase;

class StructureRuleTest extends ValidationTestCase
{
    /**
     * @param array<string, string> $files
     * @return array<int, Finding>
     */
    private function findings(array $files): array
    {
        if (!isset($files[self::INVENTORY_PATH])) {
            $files[self::INVENTORY_PATH] = $this->inventoryJson([
                'pub' => ['orders' => ['row_count' => 10, 'columns' => ['id' => 'bigint']]],
            ]);
        }
        return (new StructureRule())->apply($this->context($files));
    }

    public function testMissingIncludeFileIsError(): void
    {
        $files = [
            self::CONFIG_PATH => Yaml::dump(['includes' => ['pub' => './dump-settings/pub.yaml']], 4, 2),
            // Файл схемы намеренно не создаём.
            '/proj/database/dump-settings/other.yaml' => "full_export:\n  - x\n",
        ];

        $finding = $this->firstWithCode($this->findings($files), 'S-1');
        $this->assertNotNull($finding);
        $this->assertSame(Finding::SEVERITY_ERROR, $finding->getSeverity());
        $this->assertSame('pub', $finding->getSchema());
    }

    public function testBrokenYamlIsError(): void
    {
        $files = $this->splitConfig(['pub' => ['partial_export' => ['orders' => ['limit' => 10]]]]);
        $files['/proj/database/dump-settings/pub.yaml'] = "partial_export:\n  orders:\n   limit: [unclosed\n";

        $this->assertSame(1, $this->countCode($this->findings($files), 'S-1'));
    }

    public function testInvalidTableConfigBecomesFinding(): void
    {
        $files = $this->splitConfig([
            'pub' => ['partial_export' => ['orders' => ['limit' => 10, 'where' => "status = 'new'; DROP TABLE x"]]],
        ]);

        $finding = $this->firstWithCode($this->findings($files), 'S-2');
        $this->assertNotNull($finding);
        $this->assertSame('pub.orders', $finding->getTarget());
        $this->assertStringContainsString('where', $finding->getMessage());
    }

    public function testTableInBothSectionsIsError(): void
    {
        $files = $this->splitConfig([
            'pub' => [
                'full_export' => ['orders'],
                'partial_export' => ['orders' => ['limit' => 10]],
            ],
        ]);

        $finding = $this->firstWithCode($this->findings($files), 'S-3');
        $this->assertNotNull($finding);
        $this->assertSame(Finding::SEVERITY_ERROR, $finding->getSeverity());
    }

    public function testEmptySectionIsFixableWarning(): void
    {
        $files = $this->splitConfig(['pub' => ['partial_export' => ['orders' => ['limit' => 10]]]]);
        $files['/proj/database/dump-settings/pub.yaml'] .= "faker: {}\n";

        $finding = $this->firstWithCode($this->findings($files), 'S-4');
        $this->assertNotNull($finding);
        $this->assertTrue($finding->isFixable());
        $this->assertSame('faker', $finding->getSuggestion()['section']);
    }

    public function testEmptyFakerTableIsFixableWarning(): void
    {
        $files = $this->splitConfig([
            'pub' => [
                'partial_export' => ['orders' => ['limit' => 10]],
                'faker' => ['orders' => []],
            ],
        ]);

        $finding = $this->firstWithCode($this->findings($files), 'S-4');
        $this->assertNotNull($finding);
        $this->assertSame('remove_faker_table', $finding->getSuggestion()['fix']);
    }

    public function testCleanConfigHasNoStructureFindings(): void
    {
        $files = $this->splitConfig(['pub' => ['partial_export' => ['orders' => ['limit' => 10]]]]);

        $this->assertSame([], $this->codes($this->findings($files)));
    }
}
