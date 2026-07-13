<?php

namespace Timbrs\DatabaseDumps\Tests\Unit\Service\Analysis\CodeHints;

use PHPUnit\Framework\TestCase;
use Timbrs\DatabaseDumps\Service\Analysis\CodeHints\MigrationFkResolver;

class MigrationFkResolverTest extends TestCase
{
    /**
     * @param array<string, string> $files
     * @return array<int, array<string, mixed>>
     */
    private function resolve(array $files): array
    {
        return (new MigrationFkResolver())->resolve($files);
    }

    /**
     * @param array<int, array<string, mixed>> $edges
     * @return array<string, array<string, mixed>>
     */
    private function byKey(array $edges): array
    {
        $out = [];
        foreach ($edges as $e) {
            $out[$e['source_table'] . '.' . $e['source_column'] . '->' . $e['target_table']] = $e;
        }
        return $out;
    }

    public function testFluentForeignReferencesOn(): void
    {
        $files = [
            'database/migrations/2024_01_01_000000_orders.php' =>
                "<?php\nreturn new class extends Migration {\n"
                . "public function up() {\n"
                . "Schema::create('orders', function (Blueprint \$table) {\n"
                . "    \$table->foreignId('id');\n"
                . "    \$table->unsignedBigInteger('client_id');\n"
                . "    \$table->foreign('client_id')->references('id')->on('clients');\n"
                . "});\n}\n"
                . "public function down() { Schema::dropIfExists('orders'); }\n};\n",
        ];

        $edges = $this->byKey($this->resolve($files));
        $this->assertArrayHasKey('orders.client_id->clients', $edges);
        $e = $edges['orders.client_id->clients'];
        $this->assertSame('id', $e['target_column']);
        $this->assertSame('migration', $e['origin']);
    }

    public function testForeignIdConstrained(): void
    {
        $files = [
            'database/migrations/2024_02_01_000000_items.php' =>
                "<?php\npublic function up() {\n"
                . "Schema::create('items', function (Blueprint \$table) {\n"
                . "    \$table->foreignId('order_id')->constrained('orders');\n"
                . "    \$table->foreignId('client_id')->constrained();\n"
                . "});\n}\n",
        ];

        $edges = $this->byKey($this->resolve($files));
        $this->assertArrayHasKey('items.order_id->orders', $edges);
        // constrained() без аргумента → выведенная таблица clients.
        $this->assertArrayHasKey('items.client_id->clients', $edges);
        $this->assertSame(MigrationFkResolver::CONF_MED, $edges['items.client_id->clients']['confidence']);
    }

    public function testRawSqlAlterTableAddConstraint(): void
    {
        $files = [
            'migrations/Version20240301000000.php' =>
                "<?php\npublic function up(Schema \$schema): void {\n"
                . "\$this->addSql('ALTER TABLE orders ADD CONSTRAINT fk_o_client FOREIGN KEY (client_id) REFERENCES clients (id)');\n"
                . "}\n",
        ];

        $edges = $this->byKey($this->resolve($files));
        $this->assertArrayHasKey('orders.client_id->clients', $edges);
        $this->assertSame('id', $edges['orders.client_id->clients']['target_column']);
    }

    public function testRawSqlInlineForeignKeyInCreateTable(): void
    {
        $files = [
            'migrations/Version20240301000001.php' =>
                "<?php\npublic function up(Schema \$schema): void {\n"
                . "\$this->addSql('CREATE TABLE orders (id INT, client_id INT, FOREIGN KEY (client_id) REFERENCES clients (id))');\n"
                . "}\n",
        ];

        $edges = $this->byKey($this->resolve($files));
        $this->assertArrayHasKey('orders.client_id->clients', $edges);
    }

    public function testLaterMigrationDropsForeignKey(): void
    {
        $files = [
            'database/migrations/2024_01_01_000000_add.php' =>
                "<?php\npublic function up() {\n"
                . "Schema::table('orders', function (Blueprint \$table) {\n"
                . "    \$table->foreign('client_id')->references('id')->on('clients');\n"
                . "});\n}\n",
            'database/migrations/2024_05_01_000000_drop.php' =>
                "<?php\npublic function up() {\n"
                . "Schema::table('orders', function (Blueprint \$table) {\n"
                . "    \$table->dropForeign(['client_id']);\n"
                . "});\n}\n",
        ];

        $edges = $this->byKey($this->resolve($files));
        // FK добавлен ранней, снят поздней миграцией → в итоге его нет.
        $this->assertArrayNotHasKey('orders.client_id->clients', $edges);
        $this->assertCount(0, $edges);
    }

    public function testLaterMigrationDropColumnRemovesFk(): void
    {
        $files = [
            'database/migrations/2024_01_01_000000_add.php' =>
                "<?php\npublic function up() {\n"
                . "Schema::table('orders', function (\$table) {\n"
                . "    \$table->foreign('client_id')->references('id')->on('clients');\n"
                . "});\n}\n",
            'database/migrations/2024_06_01_000000_drop.php' =>
                "<?php\npublic function up() {\n"
                . "Schema::table('orders', function (\$table) {\n"
                . "    \$table->dropColumn('client_id');\n"
                . "});\n}\n",
        ];

        $edges = $this->byKey($this->resolve($files));
        $this->assertArrayNotHasKey('orders.client_id->clients', $edges);
    }

    public function testDropConstraintRawSql(): void
    {
        $files = [
            'migrations/Version20240101000000.php' =>
                "<?php\npublic function up(Schema \$schema): void {\n"
                . "\$this->addSql('ALTER TABLE orders ADD CONSTRAINT fk FOREIGN KEY (client_id) REFERENCES clients (id)');\n"
                . "}\n",
            'migrations/Version20240701000000.php' =>
                "<?php\npublic function up(Schema \$schema): void {\n"
                . "\$this->addSql('ALTER TABLE orders DROP CONSTRAINT fk');\n"
                . "}\n",
        ];

        $edges = $this->byKey($this->resolve($files));
        $this->assertArrayNotHasKey('orders.client_id->clients', $edges);
    }

    public function testDownBodyIgnored(): void
    {
        // FK стоит только в down() — не учитываем.
        $files = [
            'database/migrations/2024_01_01_000000_x.php' =>
                "<?php\npublic function up() {\n"
                . "Schema::create('orders', function (\$table) { \$table->unsignedBigInteger('client_id'); });\n"
                . "}\n"
                . "public function down() {\n"
                . "Schema::table('orders', function (\$table) {\n"
                . "    \$table->foreign('client_id')->references('id')->on('clients');\n"
                . "});\n}\n",
        ];

        $edges = $this->byKey($this->resolve($files));
        $this->assertArrayNotHasKey('orders.client_id->clients', $edges);
    }

    public function testSortingByFilenameChronology(): void
    {
        // Даже если порядок в массиве обратный, применяется по возрастанию имени файла.
        $files = [
            'database/migrations/2024_09_01_000000_drop.php' =>
                "<?php\npublic function up() { Schema::table('orders', function (\$t) { \$t->dropForeign(['client_id']); }); }\n",
            'database/migrations/2024_03_01_000000_add.php' =>
                "<?php\npublic function up() { Schema::table('orders', function (\$t) { \$t->foreign('client_id')->references('id')->on('clients'); }); }\n",
        ];

        $edges = $this->byKey($this->resolve($files));
        // add (март) → drop (сентябрь): FK снят.
        $this->assertArrayNotHasKey('orders.client_id->clients', $edges);
    }
}
