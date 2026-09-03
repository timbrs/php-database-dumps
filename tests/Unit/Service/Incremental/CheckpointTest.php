<?php

namespace Timbrs\DatabaseDumps\Tests\Unit\Service\Incremental;

use PHPUnit\Framework\TestCase;
use Timbrs\DatabaseDumps\Contract\FileSystemInterface;
use Timbrs\DatabaseDumps\Service\Incremental\Checkpoint;

class CheckpointTest extends TestCase
{
    /** @var array<string, string> */
    private $written = [];

    public function testCreateSortsTablesAndStampsTime(): void
    {
        $checkpoint = Checkpoint::create('Version20250601000000', '2025-06-01T00:00:00Z', 'abc1234', [
            'public.orders' => ['config_sha256' => 'a'],
            'public.clients' => ['config_sha256' => 'b'],
        ]);

        $this->assertSame('Version20250601000000', $checkpoint->newestMigration());
        $this->assertSame('2025-06-01T00:00:00Z', $checkpoint->inventoryGeneratedAt());
        $this->assertSame('abc1234', $checkpoint->headCommit());
        $this->assertSame(['public.clients', 'public.orders'], array_keys($checkpoint->tables()));
        $this->assertNotNull($checkpoint->createdAt());
    }

    public function testSaveAndLoadRoundTrip(): void
    {
        $fs = $this->fileSystem();
        $original = Checkpoint::create('Version1', null, null, ['s.t' => ['config_sha256' => 'x']]);
        $original->save($fs, '/proj/checkpoint.json');

        $loaded = Checkpoint::load($fs, '/proj/checkpoint.json');

        $this->assertNotNull($loaded);
        $this->assertSame('x', $loaded->hash('s.t', 'config_sha256'));
        $this->assertSame('Version1', $loaded->newestMigration());
    }

    /**
     * Нет файла или он битый — «первый прогон», а не ошибка: инкремент должен позвать
     * полный цикл, а не свалиться.
     */
    public function testMissingOrBrokenFileGivesNull(): void
    {
        $fs = $this->fileSystem();
        $this->assertNull(Checkpoint::load($fs, '/proj/nope.json'));

        $this->written['/proj/broken.json'] = 'не json';
        $this->assertNull(Checkpoint::load($fs, '/proj/broken.json'));
    }

    /**
     * Старая отметка без нового сенсора не должна объявить все таблицы грязными:
     * отсутствующий хеш — «не знаю», а не «изменилось».
     */
    public function testUnknownHashFieldIsNullNotEmptyString(): void
    {
        $checkpoint = new Checkpoint(['tables' => ['s.t' => ['config_sha256' => 'x']]]);

        $this->assertSame('x', $checkpoint->hash('s.t', 'config_sha256'));
        $this->assertNull($checkpoint->hash('s.t', 'codes_sha256'));
        $this->assertNull($checkpoint->hash('s.other', 'config_sha256'));
    }

    public function testEmptyCheckpointHasNullFields(): void
    {
        $checkpoint = new Checkpoint();

        $this->assertNull($checkpoint->createdAt());
        $this->assertNull($checkpoint->newestMigration());
        $this->assertNull($checkpoint->inventoryGeneratedAt());
        $this->assertNull($checkpoint->headCommit());
        $this->assertSame([], $checkpoint->tables());
        $this->assertNull($checkpoint->table('s.t'));
    }

    private function fileSystem(): FileSystemInterface
    {
        $fs = $this->createMock(FileSystemInterface::class);
        $fs->method('exists')->willReturnCallback(function ($path) {
            return isset($this->written[$path]);
        });
        $fs->method('read')->willReturnCallback(function ($path) {
            return isset($this->written[$path]) ? $this->written[$path] : '';
        });
        $fs->method('write')->willReturnCallback(function ($path, $content) {
            $this->written[$path] = $content;
        });

        return $fs;
    }
}
