<?php

namespace Timbrs\DatabaseDumps\Tests\Unit\Service\Analysis\CodeHints;

use PHPUnit\Framework\TestCase;
use Timbrs\DatabaseDumps\Service\Analysis\CodeHints\ColumnUsageDetector;

class ColumnUsageDetectorTest extends TestCase
{
    /**
     * @param array<int, string>          $columns
     * @param array<string, string>       $files rel => content
     * @return array<string, array<string, mixed>>
     */
    private function detect(array $columns, array $files): array
    {
        $prepared = [];
        foreach ($files as $rel => $content) {
            $lines = preg_split('/\R/u', $content);
            $prepared[] = ['rel' => $rel, 'content' => $content, 'lines' => $lines === false ? [] : $lines];
        }
        return (new ColumnUsageDetector())->detect($columns, $prepared);
    }

    public function testCategorizesUsages(): void
    {
        $files = [
            'src/Repository/OrderRepository.php' =>
                "<?php\n"
                . "\$qb->andWhere('o.status = :status');\n"        // filter
                . "\$qb->join('o.client', 'c');\n"                  // join (col 'client'? use status)
                . "\$qb->orderBy('o.created_at', 'DESC');\n"        // order
                . "UPDATE orders SET status = 'x';\n",              // write
        ];

        $cols = $this->detect(['status', 'created_at'], $files);

        $this->assertArrayHasKey('status', $cols);
        $this->assertContains('filter', $cols['status']['usages']);
        $this->assertContains('write', $cols['status']['usages']);
        $this->assertArrayHasKey('created_at', $cols);
        $this->assertContains('order', $cols['created_at']['usages']);
        $this->assertSame(2, $cols['status']['count']);
    }

    public function testReadWhenNoSpecialContext(): void
    {
        $files = ['src/Service/X.php' => "<?php\necho \$order->email;\n"];
        $cols = $this->detect(['email'], $files);
        $this->assertSame(['read'], $cols['email']['usages']);
    }

    public function testEloquentCastsMetadata(): void
    {
        $files = [
            'app/Models/Order.php' =>
                "<?php\nclass Order extends Model {\n"
                . "    protected \$casts = [\n"
                . "        'status' => OrderStatus::class,\n"
                . "        'is_paid' => 'boolean',\n"
                . "    ];\n}\n",
        ];

        $cols = $this->detect(['status', 'is_paid'], $files);
        $this->assertSame('OrderStatus', $cols['status']['enum']['type']);
        $this->assertSame('boolean', $cols['is_paid']['cast']);
    }

    public function testDoctrineEnumTypeMetadata(): void
    {
        $files = [
            'src/Entity/Order.php' =>
                "<?php\nclass Order {\n"
                . "    #[ORM\\Column(name: 'status', enumType: OrderStatus::class)]\n"
                . "    private \$status;\n}\n",
        ];

        $cols = $this->detect(['status'], $files);
        $this->assertSame('OrderStatus', $cols['status']['enum']['type']);
    }

    public function testNoiseGuardShortNameNoSample(): void
    {
        // Короткое имя (2 симв.) → count есть, sample нет.
        $files = ['src/x.php' => "<?php\nWHERE id = 1\nWHERE id = 2\n"];
        $cols = $this->detect(['id'], $files);
        $this->assertArrayHasKey('id', $cols);
        $this->assertSame(2, $cols['id']['count']);
        $this->assertArrayNotHasKey('sample', $cols['id']);
    }

    public function testSamplePresentForNormalColumn(): void
    {
        $files = ['src/Repository/OrderRepository.php' => "<?php\n\$qb->andWhere('o.status = :s');\n"];
        $cols = $this->detect(['status'], $files);
        $this->assertArrayHasKey('sample', $cols['status']);
        $this->assertSame('src/Repository/OrderRepository.php', $cols['status']['sample']['file']);
    }
}
