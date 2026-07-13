<?php

namespace Timbrs\DatabaseDumps\Tests\Unit\Service\Analysis\CodeHints;

use PHPUnit\Framework\TestCase;
use Timbrs\DatabaseDumps\Service\Analysis\CodeHints\CriteriaDetector;

class CriteriaDetectorTest extends TestCase
{
    /**
     * @return array<int, array<string, mixed>>
     */
    private function detect(string $content, bool $isRepository = false): array
    {
        $lines = preg_split('/\R/u', $content);
        return (new CriteriaDetector())->detect($content, $lines === false ? [] : $lines, 'app/Models/Order.php', $isRepository);
    }

    /**
     * @param array<int, array<string, mixed>> $cands
     * @return array<int, array<string, mixed>>
     */
    private function byOrigin(array $cands, string $origin): array
    {
        $out = [];
        foreach ($cands as $c) {
            if ($c['origin'] === $origin) {
                $out[] = $c;
            }
        }
        return $out;
    }

    public function testEloquentScopeNameAndWhere(): void
    {
        $content = "<?php\nclass Order extends Model\n{\n"
            . "    public function scopeActive(\$query)\n    {\n"
            . "        return \$query->where('status', 'active');\n"
            . "    }\n}\n";

        $scopes = $this->byOrigin($this->detect($content), 'eloquent_scope');
        $this->assertCount(1, $scopes);
        $this->assertSame('active', $scopes[0]['name']);
        $this->assertStringContainsString("status = 'active'", $scopes[0]['where']);
        $this->assertSame('low', $scopes[0]['confidence']);
    }

    public function testRepositoryFinderWithAndWhere(): void
    {
        $content = "<?php\nclass OrderRepository\n{\n"
            . "    public function findClosed()\n    {\n"
            . "        return \$this->createQueryBuilder('o')\n"
            . "            ->andWhere('o.status = :status')\n"
            . "            ->getQuery()->getResult();\n"
            . "    }\n}\n";

        $repo = $this->byOrigin($this->detect($content, true), 'repository_method');
        $this->assertCount(1, $repo);
        $this->assertSame('closed', $repo[0]['name']);
        $this->assertStringContainsString('o.status = :status', $repo[0]['where']);
    }

    public function testRepositoryMethodsIgnoredWhenNotRepository(): void
    {
        $content = "<?php\nclass Order\n{\n"
            . "    public function findClosed() { return \$this->createQueryBuilder('o')->andWhere('o.x = 1'); }\n}\n";

        $repo = $this->byOrigin($this->detect($content, false), 'repository_method');
        $this->assertCount(0, $repo);
    }

    public function testPhpEnumValues(): void
    {
        $content = "<?php\nenum OrderStatus: string\n{\n"
            . "    case Active = 'active';\n"
            . "    case Closed = 'closed';\n}\n";

        $enums = $this->byOrigin($this->detect($content), 'enum');
        $this->assertCount(1, $enums);
        $this->assertSame('OrderStatus', $enums[0]['enum_type']);
        $this->assertSame(['active', 'closed'], $enums[0]['values']);
    }

    public function testStringConstants(): void
    {
        $content = "<?php\nclass Order\n{\n"
            . "    const STATUS_ACTIVE = 'active';\n"
            . "    const STATUS_CLOSED = 'closed';\n}\n";

        $consts = $this->byOrigin($this->detect($content), 'const');
        $this->assertCount(1, $consts);
        $this->assertSame(['active', 'closed'], $consts[0]['values']);
    }

    public function testFileFieldSet(): void
    {
        $content = "<?php\nclass Order { public function scopeActive(\$q) { return \$q->where('x', 'y'); } }\n";
        $cands = $this->detect($content);
        $this->assertNotEmpty($cands);
        $this->assertSame('app/Models/Order.php', $cands[0]['file']);
    }
}
