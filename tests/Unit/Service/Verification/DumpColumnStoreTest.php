<?php

namespace Timbrs\DatabaseDumps\Tests\Unit\Service\Verification;

use PHPUnit\Framework\TestCase;
use Timbrs\DatabaseDumps\Service\Verification\DumpColumnStore;
use Timbrs\DatabaseDumps\Service\Verification\DumpValueReader;
use Timbrs\DatabaseDumps\Service\Verification\Sink\CountingSetSink;
use Timbrs\DatabaseDumps\Service\Verification\Sink\SampleSink;

class DumpColumnStoreTest extends TestCase
{
    /** @var string */
    private $dir;

    /** @var string */
    private $path;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/colstore_' . bin2hex(random_bytes(4));
        mkdir($this->dir);
        $this->path = $this->dir . '/clients.sql';
        file_put_contents($this->path, <<<'SQL'
TRUNCATE TABLE "public"."clients" CASCADE;

INSERT INTO "public"."clients" ("id", "status", "email") VALUES
(1, 'red', 'a@x.ru'),
(2, 'green', NULL),
(3, 'red', 'c@x.ru');
SQL
        );
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir . '/*') ?: []);
        rmdir($this->dir);
    }

    public function testOneScanFeedsEverySinkOfTheFile(): void
    {
        $reader = $this->getMockBuilder(DumpValueReader::class)->onlyMethods(['scan'])->getMock();
        $real = new DumpValueReader();
        $reader->expects(self::once())->method('scan')->willReturnCallback(
            function (string $path, array $columns, callable $visitor, callable $onHeader = null) use ($real) {
                return $real->scan($path, $columns, $visitor, $onHeader);
            }
        );

        $store = new DumpColumnStore($reader);
        $status = new CountingSetSink();
        $statusAgain = new SampleSink(10);
        $email = new SampleSink(10);
        $store->request($this->path, 'status', $status);
        $store->request($this->path, 'status', $statusAgain);
        $store->request($this->path, 'email', $email);
        $store->request($this->path, 'missing', new SampleSink(10));
        $store->load();

        self::assertSame(['red' => 2, 'green' => 1], $status->counts());
        self::assertSame(['red', 'green', 'red'], $statusAgain->values());
        self::assertSame(['a@x.ru', 'c@x.ru'], $email->values());
        self::assertSame(3, $store->rows($this->path));
        self::assertTrue($store->found($this->path, 'status'));
        self::assertFalse($store->found($this->path, 'missing'));
        self::assertFalse($store->isMissing($this->path));
        self::assertSame(['id', 'status', 'email'], $store->columns($this->path));
    }

    public function testRequestAllCreatesSinksFromTheHeader(): void
    {
        $store = new DumpColumnStore(new DumpValueReader());
        $created = [];
        $store->requestAll($this->path, function (string $column) use (&$created) {
            if ($column === 'id') {
                return null;
            }
            $created[$column] = new SampleSink(10);

            return $created[$column];
        });
        $direct = new CountingSetSink();
        $store->request($this->path, 'status', $direct);
        $store->load();

        self::assertSame(['status', 'email'], array_keys($created));
        self::assertSame(['red', 'green', 'red'], $created['status']->values());
        self::assertSame(['a@x.ru', 'c@x.ru'], $created['email']->values());
        // Сток, заявленный по имени, тоже получил значения при чтении всех колонок.
        self::assertSame(['red' => 2, 'green' => 1], $direct->counts());
        self::assertTrue($store->found($this->path, 'status'));
    }

    public function testRowsOnlyRequestCountsWithoutSinks(): void
    {
        $store = new DumpColumnStore(new DumpValueReader());
        $store->requestRows($this->path);
        $store->requestRows($this->dir . '/absent.sql');
        $store->load();

        self::assertSame(3, $store->rows($this->path));
        self::assertTrue($store->isMissing($this->dir . '/absent.sql'));
        self::assertNull($store->rows($this->dir . '/absent.sql'));
    }
}
