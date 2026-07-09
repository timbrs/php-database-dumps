<?php

namespace Timbrs\DatabaseDumps\Tests\Unit\Util;

use PHPUnit\Framework\TestCase;
use Timbrs\DatabaseDumps\Util\EnvFileWriter;
use Timbrs\DatabaseDumps\Util\FileSystemHelper;

class EnvFileWriterTest extends TestCase
{
    /** @var string */
    private $projectDir;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir() . '/dbdump_env_' . bin2hex(random_bytes(6));
        mkdir($this->projectDir, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (['/.env', '/.env.local'] as $f) {
            @unlink($this->projectDir . $f);
        }
        @rmdir($this->projectDir);
    }

    private function writer(): EnvFileWriter
    {
        return new EnvFileWriter(new FileSystemHelper());
    }

    private function read(string $path): string
    {
        $content = file_get_contents($path);
        return $content === false ? '' : $content;
    }

    public function testCreatesEnvLocalWhenNoneExists(): void
    {
        $target = $this->writer()->setVar($this->projectDir, 'DBDUMP_LLM_TOKEN', 'abc123');

        $this->assertSame($this->projectDir . '/.env.local', $target);
        $this->assertSame("DBDUMP_LLM_TOKEN=abc123\n", $this->read($target));
    }

    public function testPrefersEnvLocalOverEnv(): void
    {
        file_put_contents($this->projectDir . '/.env', "FOO=bar\n");
        file_put_contents($this->projectDir . '/.env.local', "FOO=bar\n");

        $target = $this->writer()->setVar($this->projectDir, 'DBDUMP_LLM_TOKEN', 'x');
        $this->assertSame($this->projectDir . '/.env.local', $target);
    }

    public function testWritesToEnvWhenNoEnvLocal(): void
    {
        file_put_contents($this->projectDir . '/.env', "FOO=bar\n");

        $target = $this->writer()->setVar($this->projectDir, 'DBDUMP_LLM_TOKEN', 'x');
        $this->assertSame($this->projectDir . '/.env', $target);
        $content = $this->read($this->projectDir . '/.env');
        $this->assertStringContainsString("FOO=bar", $content);
        $this->assertStringContainsString("DBDUMP_LLM_TOKEN=x", $content);
    }

    public function testUpdatesExistingKeyWithoutDuplicates(): void
    {
        file_put_contents($this->projectDir . '/.env.local', "A=1\nDBDUMP_LLM_TOKEN=old\nB=2\n");

        $this->writer()->setVar($this->projectDir, 'DBDUMP_LLM_TOKEN', 'new');

        $content = $this->read($this->projectDir . '/.env.local');
        $this->assertSame(1, substr_count($content, 'DBDUMP_LLM_TOKEN='));
        $this->assertStringContainsString('DBDUMP_LLM_TOKEN=new', $content);
        $this->assertStringContainsString("A=1", $content);
        $this->assertStringContainsString("B=2", $content);
    }

    public function testQuotesValueWithSpecialChars(): void
    {
        $this->writer()->setVar($this->projectDir, 'DBDUMP_LLM_TOKEN', 'a b"c');

        $content = $this->read($this->projectDir . '/.env.local');
        $this->assertStringContainsString('DBDUMP_LLM_TOKEN="a b\\"c"', $content);
    }

    public function testAppendsNewlineWhenFileHasNoTrailingNewline(): void
    {
        file_put_contents($this->projectDir . '/.env.local', 'A=1');

        $this->writer()->setVar($this->projectDir, 'DBDUMP_LLM_TOKEN', 'x');

        $content = $this->read($this->projectDir . '/.env.local');
        $this->assertStringContainsString("A=1\nDBDUMP_LLM_TOKEN=x\n", $content);
    }
}
