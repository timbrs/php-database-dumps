<?php

namespace Timbrs\DatabaseDumps\Tests\Unit\Service\Analysis\CodeHints;

use PHPUnit\Framework\TestCase;
use Timbrs\DatabaseDumps\Service\Analysis\CodeHints\RelationshipDetector;

class RelationshipDetectorTest extends TestCase
{
    /**
     * @return array<int, array<string, mixed>>
     */
    private function detect(string $content): array
    {
        $lines = preg_split('/\R/u', $content);
        return (new RelationshipDetector())->detect($content, $lines === false ? [] : $lines, 'src/Entity/Order.php');
    }

    public function testDoctrineManyToOneWithJoinColumn(): void
    {
        $content = "<?php\n"
            . "class Order\n{\n"
            . "    #[ORM\\ManyToOne(targetEntity: Client::class)]\n"
            . "    #[ORM\\JoinColumn(name: 'client_id', referencedColumnName: 'id')]\n"
            . "    private ?Client \$client;\n"
            . "}\n";

        $cands = $this->detect($content);
        $this->assertCount(1, $cands);
        $c = $cands[0];
        $this->assertSame('client_id', $c['source_column']);
        $this->assertSame('Client', $c['target_class']);
        $this->assertSame('id', $c['target_column']);
        $this->assertSame('belongs_to', $c['kind']);
        $this->assertSame('doctrine', $c['origin']);
        $this->assertSame('src/Entity/Order.php', $c['file']);
    }

    public function testDoctrineOneToManyMappedBy(): void
    {
        $content = "<?php\n"
            . "class Client\n{\n"
            . "    #[ORM\\OneToMany(targetEntity: Order::class, mappedBy: 'client')]\n"
            . "    private Collection \$orders;\n"
            . "}\n";

        $cands = $this->detect($content);
        $this->assertCount(1, $cands);
        $this->assertSame('has_many', $cands[0]['kind']);
        $this->assertSame('Order', $cands[0]['target_class']);
        // На стороне родителя колонки-источника нет — пусто.
        $this->assertSame('', $cands[0]['source_column']);
    }

    public function testDoctrineTargetEntityFromPropertyType(): void
    {
        // targetEntity не задан явно — берётся из типа свойства.
        $content = "<?php\n"
            . "class Order\n{\n"
            . "    #[ORM\\OneToOne]\n"
            . "    #[ORM\\JoinColumn(name: 'invoice_id', referencedColumnName: 'id')]\n"
            . "    private ?Invoice \$invoice;\n"
            . "}\n";

        $cands = $this->detect($content);
        $this->assertCount(1, $cands);
        $this->assertSame('Invoice', $cands[0]['target_class']);
        $this->assertSame('has_one', $cands[0]['kind']);
        $this->assertSame('invoice_id', $cands[0]['source_column']);
    }

    public function testDoctrineAnnotationStyle(): void
    {
        $content = "<?php\n"
            . "class Order\n{\n"
            . "    /**\n"
            . "     * @ORM\\ManyToOne(targetEntity=\"Client\")\n"
            . "     * @ORM\\JoinColumn(name=\"client_id\", referencedColumnName=\"id\")\n"
            . "     */\n"
            . "    private \$client;\n"
            . "}\n";

        $cands = $this->detect($content);
        $this->assertCount(1, $cands);
        $this->assertSame('Client', $cands[0]['target_class']);
        $this->assertSame('client_id', $cands[0]['source_column']);
    }

    public function testEloquentBelongsToAndHasMany(): void
    {
        $content = "<?php\n"
            . "class Order extends Model\n{\n"
            . "    public function client() { return \$this->belongsTo(Client::class, 'client_id', 'id'); }\n"
            . "    public function items() { return \$this->hasMany(Item::class); }\n"
            . "}\n";

        $cands = $this->detect($content);
        $this->assertCount(2, $cands);

        $byClass = [];
        foreach ($cands as $c) {
            $byClass[$c['target_class']] = $c;
        }
        $this->assertSame('belongs_to', $byClass['Client']['kind']);
        $this->assertSame('client_id', $byClass['Client']['source_column']);
        $this->assertSame('id', $byClass['Client']['target_column']);
        $this->assertSame('eloquent', $byClass['Client']['origin']);

        $this->assertSame('has_many', $byClass['Item']['kind']);
        $this->assertSame('', $byClass['Item']['source_column']);
    }

    public function testManyToManyMapsToOther(): void
    {
        $content = "<?php\nclass Order\n{\n    #[ORM\\ManyToMany(targetEntity: Tag::class)]\n    private \$tags;\n}\n";
        $cands = $this->detect($content);
        $this->assertCount(1, $cands);
        $this->assertSame('other', $cands[0]['kind']);
    }
}
