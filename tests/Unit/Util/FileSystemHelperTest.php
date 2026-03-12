<?php

namespace Timbrs\DatabaseDumps\Tests\Unit\Util;

use PHPUnit\Framework\TestCase;
use Timbrs\DatabaseDumps\Util\FileSystemHelper;

class FileSystemHelperTest extends TestCase
{
    /** @var FileSystemHelper */
    private $helper;

    /** @var string */
    private $tempDir;

    protected function setUp(): void
    {
        $this->helper = new FileSystemHelper();
        $this->tempDir = sys_get_temp_dir() . '/db_dumps_test_' . uniqid();
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $files = glob($this->tempDir . '/*');
        if ($files) {
            foreach ($files as $file) {
                unlink($file);
            }
        }
        if (is_dir($this->tempDir)) {
            rmdir($this->tempDir);
        }
    }

    public function testAppendCreatesContentInExistingFile(): void
    {
        $path = $this->tempDir . '/test.sql';
        file_put_contents($path, 'first');

        $this->helper->append($path, ' second');

        $this->assertEquals('first second', file_get_contents($path));
    }

    public function testAppendToEmptyFile(): void
    {
        $path = $this->tempDir . '/empty.sql';
        file_put_contents($path, '');

        $this->helper->append($path, 'content');

        $this->assertEquals('content', file_get_contents($path));
    }

    public function testAppendMultipleTimes(): void
    {
        $path = $this->tempDir . '/multi.sql';
        file_put_contents($path, 'header');

        $this->helper->append($path, "\nbatch1");
        $this->helper->append($path, "\nbatch2");
        $this->helper->append($path, "\nbatch3");

        $this->assertEquals("header\nbatch1\nbatch2\nbatch3", file_get_contents($path));
    }

    public function testWriteThenAppend(): void
    {
        $path = $this->tempDir . '/stream.sql';

        $this->helper->write($path, '-- header');
        $this->helper->append($path, "\n-- batch 1");
        $this->helper->append($path, "\n-- batch 2");

        $this->assertEquals("-- header\n-- batch 1\n-- batch 2", file_get_contents($path));
    }
}
