<?php

namespace Timbrs\DatabaseDumps\Tests\Unit\Service\Analysis;

use PHPUnit\Framework\TestCase;
use Timbrs\DatabaseDumps\Contract\LoggerInterface;
use Timbrs\DatabaseDumps\Service\Analysis\CodeHintScanner;

class CodeHintScannerTest extends TestCase
{
    /**
     * Сканер с подменёнными enumerateFiles()/readFile().
     *
     * @param array<string, string> $files абсолютный путь => содержимое
     */
    private function scanner(array $files): CodeHintScanner
    {
        return new class('/proj', $this->createMock(LoggerInterface::class), $files) extends CodeHintScanner {
            /** @var array<string, string> */
            private $files;

            /**
             * @param array<string, string> $files
             */
            public function __construct(string $dir, LoggerInterface $logger, array $files)
            {
                parent::__construct($dir, $logger);
                $this->files = $files;
            }

            protected function enumerateFiles(string $dataDir): array
            {
                return array_keys($this->files);
            }

            protected function readFile(string $path)
            {
                return isset($this->files[$path]) ? $this->files[$path] : false;
            }
        };
    }

    public function testCategorizesEntityRepositoryModelSqlAndClassUsage(): void
    {
        $files = [
            '/proj/src/Entity/Client.php' => "<?php\nnamespace App\\Entity;\nuse Doctrine\\ORM\\Mapping as ORM;\n#[ORM\\Table(name: 'clients')]\nclass Client\n{\n}\n",
            '/proj/src/Repository/ClientRepository.php' => "<?php\nnamespace App\\Repository;\nclass ClientRepository\n{\n    public function q() { \$this->qb->from('clients', 'c'); }\n}\n",
            '/proj/app/Models/Order.php' => "<?php\nnamespace App\\Models;\nclass Order\n{\n    protected \$table = 'orders';\n}\n",
            '/proj/legacy/report.sql' => "SELECT id, status\nFROM clients c\nJOIN orders o ON o.client_id = c.id\n",
            '/proj/src/Service/ClientService.php' => "<?php\nnamespace App\\Service;\nuse App\\Entity\\Client;\nuse App\\Models\\Order;\nclass ClientService\n{\n    public function make(Client \$c, Order \$o) {}\n}\n",
        ];

        $map = $this->scanner($files)->scan(['public.clients', 'public.orders'], 'database');

        $this->assertArrayHasKey('public.clients', $map);
        $this->assertArrayHasKey('public.orders', $map);

        $clients = $map['public.clients'];
        $this->assertSame(1, $clients['counts']['entity']);
        $this->assertSame(1, $clients['counts']['repository']);
        $this->assertSame(1, $clients['counts']['sql']);
        $this->assertSame(2, $clients['counts']['entity usage']);
        $this->assertFalse($clients['truncated']);
        $this->assertArrayHasKey('entity', $clients['categories']);
        $this->assertArrayHasKey('repository', $clients['categories']);
        $this->assertArrayHasKey('entity usage', $clients['categories']);
        $this->assertSame('1 entity, 2 entity usage, 1 repository, 1 sql', $clients['summary']);

        $orders = $map['public.orders'];
        $this->assertSame(1, $orders['counts']['model']);
        $this->assertSame(1, $orders['counts']['sql']);
        $this->assertSame(2, $orders['counts']['model usage']);
        $this->assertSame('1 model, 2 model usage, 1 sql', $orders['summary']);

        // Сниппет entity указывает на строку с ORM\Table.
        $entityHint = $clients['categories']['entity'][0];
        $this->assertSame('src/Entity/Client.php', $entityHint['file']);
        $this->assertStringContainsString("ORM\\Table(name: 'clients')", $entityHint['snippet']);
    }

    public function testTracksRepositoryUsageByConvention(): void
    {
        $files = [
            // Энтити без явного repositoryClass — репозиторий связывается по конвенции {Entity}Repository.
            '/proj/src/Entity/Client.php' => "<?php\nnamespace App\\Entity;\nuse Doctrine\\ORM\\Mapping as ORM;\n#[ORM\\Table(name: 'clients')]\nclass Client\n{\n}\n",
            '/proj/src/Repository/ClientRepository.php' => "<?php\nnamespace App\\Repository;\nclass ClientRepository\n{\n}\n",
            // Использование репозитория (use + инъекция) в двух разных файлах = 4.
            '/proj/src/Controller/ClientController.php' => "<?php\nnamespace App\\Controller;\nuse App\\Repository\\ClientRepository;\nclass ClientController\n{\n    public function __construct(private ClientRepository \$repo) {}\n}\n",
            '/proj/src/Service/Report.php' => "<?php\nnamespace App\\Service;\nuse App\\Repository\\ClientRepository;\nclass Report\n{\n    public function run(ClientRepository \$r) {}\n}\n",
        ];

        $map = $this->scanner($files)->scan(['public.clients'], 'database');

        $clients = $map['public.clients'];
        $this->assertArrayHasKey('repository usage', $clients['counts']);
        $this->assertSame(4, $clients['counts']['repository usage']);
        $this->assertArrayHasKey('repository usage', $clients['categories']);
        // Файл-определение репозитория не считается за использование.
        foreach ($clients['categories']['repository usage'] as $hint) {
            $this->assertNotSame('src/Repository/ClientRepository.php', $hint['file']);
        }
    }

    public function testTracksRepositoryUsageByExplicitRepositoryClass(): void
    {
        $files = [
            // Явный repositoryClass с нестандартным именем (конвенция {Entity}Repository не совпала бы).
            '/proj/src/Entity/Order.php' => "<?php\nnamespace App\\Entity;\nuse Doctrine\\ORM\\Mapping as ORM;\n#[ORM\\Entity(repositoryClass: OrderStore::class)]\n#[ORM\\Table(name: 'orders')]\nclass Order\n{\n}\n",
            '/proj/src/Repository/OrderStore.php' => "<?php\nnamespace App\\Repository;\nclass OrderStore\n{\n}\n",
            '/proj/src/Service/Checkout.php' => "<?php\nnamespace App\\Service;\nuse App\\Repository\\OrderStore;\nclass Checkout\n{\n    public function pay(OrderStore \$s) {}\n}\n",
        ];

        $map = $this->scanner($files)->scan(['public.orders'], 'database');

        $orders = $map['public.orders'];
        $this->assertArrayHasKey('repository usage', $orders['counts']);
        // Checkout.php: use + сигнатура; плюс строка атрибута repositoryClass в энтити.
        $this->assertGreaterThanOrEqual(2, $orders['counts']['repository usage']);
    }

    public function testConventionalRepositoryIgnoredWhenClassAbsent(): void
    {
        // Репозитория {Entity}Repository нет в коде → конвенциональный маппинг отбрасывается.
        $files = [
            '/proj/src/Entity/Client.php' => "<?php\n#[ORM\\Table(name: 'clients')]\nclass Client\n{\n}\n",
            '/proj/src/Service/Uses.php' => "<?php\nclass Uses { public function f(ClientRepository \$r) {} }\n",
        ];

        $map = $this->scanner($files)->scan(['public.clients'], 'database');

        $this->assertArrayNotHasKey('repository usage', $map['public.clients']['counts']);
    }

    public function testTruncatedByThresholdKeepsDefsDropsSql(): void
    {
        // 200 sql-хитов (> GENERIC_HIT_THRESHOLD) + одна entity-дефиниция.
        $sqlLines = [];
        for ($i = 0; $i < 200; $i++) {
            $sqlLines[] = 'FROM bigtable';
        }
        $files = [
            '/proj/legacy/mass.sql' => implode("\n", $sqlLines) . "\n",
            '/proj/src/Entity/Big.php' => "<?php\n#[ORM\\Table(name: 'bigtable')]\nclass Big\n{\n}\n",
        ];

        $map = $this->scanner($files)->scan(['public.bigtable'], 'database');

        $this->assertArrayHasKey('public.bigtable', $map);
        $big = $map['public.bigtable'];
        $this->assertTrue($big['truncated']);
        // Счётчики полные.
        $this->assertSame(200, $big['counts']['sql']);
        $this->assertSame(1, $big['counts']['entity']);
        // Точная дефиниция (entity) остаётся, массовый sql свёрнут — сниппетов sql нет.
        $this->assertArrayHasKey('entity', $big['categories']);
        $this->assertArrayNotHasKey('sql', $big['categories']);
    }

    public function testTruncatedByShortTableName(): void
    {
        $files = [
            '/proj/legacy/q.sql' => "SELECT * FROM ab\n",
        ];

        $map = $this->scanner($files)->scan(['public.ab'], 'database');

        $this->assertArrayHasKey('public.ab', $map);
        $this->assertTrue($map['public.ab']['truncated']);
        $this->assertSame(1, $map['public.ab']['counts']['sql']);
        // Короткое имя → sql-сниппеты не вкладываются.
        $this->assertArrayNotHasKey('sql', $map['public.ab']['categories']);
    }

    public function testCountsRemainFullWhenSnippetsCapped(): void
    {
        // 15 sql-хитов (< порога) → counts=15, но сниппетов не больше MAX_PER_CATEGORY (10).
        $sqlLines = [];
        for ($i = 0; $i < 15; $i++) {
            $sqlLines[] = 'FROM invoices';
        }
        $files = ['/proj/legacy/rep.sql' => implode("\n", $sqlLines) . "\n"];

        $map = $this->scanner($files)->scan(['public.invoices'], 'database');

        $inv = $map['public.invoices'];
        $this->assertFalse($inv['truncated']);
        $this->assertSame(15, $inv['counts']['sql']);
        $this->assertCount(CodeHintScanner::MAX_PER_CATEGORY, $inv['categories']['sql']);
    }

    public function testContextIncludesSurroundingLines(): void
    {
        $files = [
            '/proj/legacy/ctx.sql' => "line0\nline1\nFROM clients\nline3\nline4\n",
        ];

        $map = $this->scanner($files)->scan(['public.clients'], 'database');

        $hint = $map['public.clients']['categories']['sql'][0];
        // Совпадение на 3-й строке (line index 2) → номер строки 1-based.
        $this->assertSame(3, $hint['line']);
        // ±2 строки контекста → 5 строк.
        $lines = explode("\n", $hint['snippet']);
        $this->assertCount(5, $lines);
        $this->assertSame('line0', $lines[0]);
        $this->assertSame('FROM clients', $lines[2]);
        $this->assertSame('line4', $lines[4]);
    }

    public function testEmptyTablesAbsentFromMap(): void
    {
        $files = ['/proj/legacy/x.sql' => "FROM clients\n"];

        $map = $this->scanner($files)->scan(['public.clients', 'public.unused'], 'database');

        $this->assertArrayHasKey('public.clients', $map);
        $this->assertArrayNotHasKey('public.unused', $map);
    }

    public function testRelationshipInDbFkFlag(): void
    {
        $files = [
            '/proj/src/Entity/Order.php' => "<?php\nnamespace App\\Entity;\n#[ORM\\Table(name: 'orders')]\nclass Order\n{\n"
                . "    #[ORM\\ManyToOne(targetEntity: Client::class)]\n"
                . "    #[ORM\\JoinColumn(name: 'client_id', referencedColumnName: 'id')]\n"
                . "    private ?Client \$client;\n}\n",
            '/proj/src/Entity/Client.php' => "<?php\nnamespace App\\Entity;\n#[ORM\\Table(name: 'clients')]\nclass Client\n{\n}\n",
        ];

        // Без FK в графе БД → in_db_fk: false (связь есть в коде, но не во FK — риск для дампов).
        $map = $this->scanner($files)->scan(['public.orders', 'public.clients'], 'database');
        $this->assertArrayHasKey('relationships', $map['public.orders']);
        $rel = $map['public.orders']['relationships'][0];
        $this->assertSame('client_id', $rel['source_column']);
        $this->assertSame('public.clients', $rel['target_table']);
        $this->assertSame('belongs_to', $rel['kind']);
        $this->assertSame('doctrine', $rel['origin']);
        $this->assertFalse($rel['in_db_fk']);

        // С FK в графе БД → in_db_fk: true.
        $dbFks = ['public.orders' => [
            ['column' => 'client_id', 'references_table' => 'public.clients', 'references_column' => 'id'],
        ]];
        $map2 = $this->scanner($files)->scan(['public.orders', 'public.clients'], 'database', [], $dbFks);
        $this->assertTrue($map2['public.orders']['relationships'][0]['in_db_fk']);
    }

    public function testMigrationRelationshipResolved(): void
    {
        $files = [
            '/proj/src/Entity/Order.php' => "<?php\n#[ORM\\Table(name: 'orders')]\nclass Order\n{\n}\n",
            '/proj/database/migrations/2024_01_01_000000_orders.php' =>
                "<?php\npublic function up() {\n"
                . "Schema::table('orders', function (\$t) { \$t->foreign('client_id')->references('id')->on('clients'); });\n"
                . "}\n",
            '/proj/src/Entity/Client.php' => "<?php\n#[ORM\\Table(name: 'clients')]\nclass Client\n{\n}\n",
        ];

        $map = $this->scanner($files)->scan(['public.orders', 'public.clients'], 'database');
        $this->assertArrayHasKey('relationships', $map['public.orders']);
        $rel = $map['public.orders']['relationships'][0];
        $this->assertSame('migration', $rel['origin']);
        $this->assertSame('public.clients', $rel['target_table']);
        $this->assertFalse($rel['in_db_fk']);
    }

    public function testCriteriaFromScope(): void
    {
        $files = [
            '/proj/app/Models/Order.php' => "<?php\nnamespace App\\Models;\nclass Order extends Model\n{\n"
                . "    protected \$table = 'orders';\n"
                . "    public function scopeActive(\$q) { return \$q->where('status', 'active'); }\n}\n",
        ];

        $map = $this->scanner($files)->scan(['public.orders'], 'database');
        $this->assertArrayHasKey('criteria', $map['public.orders']);
        $crit = $map['public.orders']['criteria'][0];
        $this->assertSame('active', $crit['name']);
        $this->assertStringContainsString("status = 'active'", $crit['where']);
        $this->assertSame('eloquent_scope', $crit['origin']);
    }

    public function testColumnsSectionWithEnumValues(): void
    {
        $files = [
            '/proj/app/Models/Order.php' => "<?php\nnamespace App\\Models;\nclass Order extends Model\n{\n"
                . "    protected \$table = 'orders';\n"
                . "    protected \$casts = ['status' => OrderStatus::class];\n"
                . "    public function scopeActive(\$q) { return \$q->where('status', 'active'); }\n}\n",
            '/proj/app/Enums/OrderStatus.php' => "<?php\nnamespace App\\Enums;\nenum OrderStatus: string\n{\n"
                . "    case Active = 'active';\n    case Closed = 'closed';\n}\n",
        ];

        $tableColumns = ['public.orders' => ['status', 'client_id']];
        $map = $this->scanner($files)->scan(['public.orders'], 'database', $tableColumns);

        $this->assertArrayHasKey('columns', $map['public.orders']);
        $this->assertArrayHasKey('status', $map['public.orders']['columns']);
        $status = $map['public.orders']['columns']['status'];
        $this->assertSame('OrderStatus', $status['enum']['type']);
        $this->assertSame(['active', 'closed'], $status['enum']['values']);
    }

    public function testPreciseSectionsSurviveTruncated(): void
    {
        // orders раздут sql-хитами (> порога) → truncated, но relationships остаются.
        $sql = [];
        for ($i = 0; $i < 200; $i++) {
            $sql[] = 'FROM orders';
        }
        $files = [
            '/proj/legacy/mass.sql' => implode("\n", $sql) . "\n",
            '/proj/src/Entity/Order.php' => "<?php\n#[ORM\\Table(name: 'orders')]\nclass Order\n{\n"
                . "    #[ORM\\ManyToOne(targetEntity: Client::class)]\n"
                . "    #[ORM\\JoinColumn(name: 'client_id', referencedColumnName: 'id')]\n"
                . "    private ?Client \$client;\n}\n",
            '/proj/src/Entity/Client.php' => "<?php\n#[ORM\\Table(name: 'clients')]\nclass Client\n{\n}\n",
        ];

        $map = $this->scanner($files)->scan(['public.orders', 'public.clients'], 'database');
        $this->assertTrue($map['public.orders']['truncated']);
        // Массовый sql свёрнут…
        $this->assertArrayNotHasKey('sql', $map['public.orders']['categories']);
        // …но точная секция relationships сохранена.
        $this->assertArrayHasKey('relationships', $map['public.orders']);
    }

    public function testCriteriaLimit(): void
    {
        $scopes = '';
        for ($i = 0; $i < CodeHintScanner::MAX_CRITERIA + 5; $i++) {
            $scopes .= "    public function scopeSeg{$i}(\$q) { return \$q->where('col{$i}', 'v{$i}'); }\n";
        }
        $files = [
            '/proj/app/Models/Order.php' => "<?php\nclass Order\n{\n    protected \$table = 'orders';\n{$scopes}}\n",
        ];

        $map = $this->scanner($files)->scan(['public.orders'], 'database');
        $this->assertCount(CodeHintScanner::MAX_CRITERIA, $map['public.orders']['criteria']);
    }

    public function testSchemaCollisionRoutesQualifiedAndFlagsBareAmbiguous(): void
    {
        // Одно «голое» имя `phones` в двух схемах (clients, user).
        $files = [
            // Doctrine-энтити со schema:'user' → entity ТОЛЬКО на user.phones.
            '/proj/src/Entity/Phone.php' => "<?php\nnamespace App\\Entity;\nuse Doctrine\\ORM\\Mapping as ORM;\n"
                . "#[ORM\\Table(name: 'phones', schema: 'user')]\nclass Phone\n{\n}\n",
            // SQL с квалификатором схемы → хит ТОЛЬКО на clients.phones.
            '/proj/legacy/report.sql' => "SELECT id\nFROM clients.phones c\n",
            // Голое упоминание без схемы → обе таблицы, ambiguous.
            '/proj/app/Legacy/Bare.php' => "<?php\n\$q = DB::table('phones')->get();\n",
        ];

        $map = $this->scanner($files)->scan(['clients.phones', 'user.phones'], 'database');

        $this->assertArrayHasKey('clients.phones', $map);
        $this->assertArrayHasKey('user.phones', $map);

        $clients = $map['clients.phones'];
        $user = $map['user.phones'];

        // Doctrine entity разведён точно по схеме: только user.phones.
        $this->assertSame(1, $user['counts']['entity']);
        $this->assertArrayNotHasKey('entity', $clients['counts']);

        // FROM clients.phones ушёл только на clients.phones; user.phones sql — лишь голое упоминание.
        // clients.phones sql = FROM(1) + голое(1) = 2; user.phones sql = голое(1).
        $this->assertSame(2, $clients['counts']['sql']);
        $this->assertSame(1, $user['counts']['sql']);

        // Голое DB::table('phones') неразрешимо → у обеих ambiguous + полный набор ключей.
        $this->assertTrue($clients['ambiguous']);
        $this->assertTrue($user['ambiguous']);
        $this->assertSame(['clients.phones', 'user.phones'], $clients['ambiguous_with']);
        $this->assertSame(['clients.phones', 'user.phones'], $user['ambiguous_with']);
    }

    public function testNonCollidingNameHasNoAmbiguousFlag(): void
    {
        // Контроль регресса: уникальное имя таблицы → голое упоминание не помечается ambiguous.
        $files = ['/proj/legacy/x.sql' => "FROM clients\n"];

        $map = $this->scanner($files)->scan(['public.clients'], 'database');

        $this->assertArrayHasKey('public.clients', $map);
        $this->assertArrayNotHasKey('ambiguous', $map['public.clients']);
        $this->assertArrayNotHasKey('ambiguous_with', $map['public.clients']);
    }

    public function testEnumerateFiltersExtensionsExcludedDirsAndDataDir(): void
    {
        $root = sys_get_temp_dir() . '/dbdump_scan_' . uniqid();
        $mk = function ($rel, $content) use ($root) {
            $abs = $root . '/' . $rel;
            $dir = dirname($abs);
            if (!is_dir($dir)) {
                mkdir($dir, 0777, true);
            }
            file_put_contents($abs, $content);
        };
        $mk('a.php', "<?php\n");
        $mk('sub/c.sql', "SELECT 1\n");
        $mk('notes.txt', "text\n");
        $mk('vendor/lib/x.php', "<?php\n");
        $mk('database/dumps/d.sql', "SELECT 1\n");
        $mk('database/analysis/e.yaml', "k: v\n");

        $scanner = new class($root, $this->createMock(LoggerInterface::class)) extends CodeHintScanner {
            /**
             * @return array<int, string>
             */
            public function files(string $dataDir): array
            {
                return $this->enumerateFiles($dataDir);
            }
        };

        $found = $scanner->files('database');
        $rel = [];
        foreach ($found as $abs) {
            $rel[] = str_replace('\\', '/', substr($abs, strlen($root) + 1));
        }

        $this->assertContains('a.php', $rel);
        $this->assertContains('sub/c.sql', $rel);
        $this->assertNotContains('notes.txt', $rel);
        $this->assertNotContains('vendor/lib/x.php', $rel);
        $this->assertNotContains('database/dumps/d.sql', $rel);
        $this->assertNotContains('database/analysis/e.yaml', $rel);

        // cleanup
        foreach (['a.php', 'sub/c.sql', 'notes.txt', 'vendor/lib/x.php', 'database/dumps/d.sql', 'database/analysis/e.yaml'] as $r) {
            @unlink($root . '/' . $r);
        }
    }
}
