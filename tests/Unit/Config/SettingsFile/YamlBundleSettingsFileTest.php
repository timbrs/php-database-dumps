<?php

namespace Timbrs\DatabaseDumps\Tests\Unit\Config\SettingsFile;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;
use Timbrs\DatabaseDumps\Config\SettingsFile\YamlBundleSettingsFile;
use Timbrs\DatabaseDumps\Tests\Support\InMemoryFileSystem;

class YamlBundleSettingsFileTest extends TestCase
{
    private const PROJECT = '/proj';
    private const PATH = '/proj/config/packages/database_dumps.yaml';

    public function testPathIsBundleConfigInPackages(): void
    {
        $file = new YamlBundleSettingsFile(new InMemoryFileSystem());

        $this->assertSame(self::PATH, $file->path(self::PROJECT));
    }

    public function testReadReturnsContentOfRootKey(): void
    {
        $file = $this->fileWith("database_dumps:\n    data_dir: 'var/db'\n    llm:\n        url: 'https://x/v1'\n");

        $settings = $file->read(self::PROJECT);

        $this->assertIsArray($settings);
        $this->assertSame('var/db', $settings['data_dir']);
        $this->assertSame('https://x/v1', $settings['llm']['url']);
    }

    public function testReadReturnsNullWhenFileMissing(): void
    {
        $file = new YamlBundleSettingsFile(new InMemoryFileSystem());

        $this->assertNull($file->read(self::PROJECT));
    }

    /**
     * Чужой корневой ключ — не наш конфиг: читать его содержимое как настройки нельзя.
     */
    public function testReadReturnsNullWhenRootKeyAbsent(): void
    {
        $file = $this->fileWith("framework:\n    secret: x\n");

        $this->assertNull($file->read(self::PROJECT));
    }

    public function testWriteKeepsRootKeyAndIsReadableBack(): void
    {
        $fs = new InMemoryFileSystem();
        $file = new YamlBundleSettingsFile($fs);

        $file->write(self::PROJECT, ['data_dir' => 'var/db', 'llm' => ['url' => 'https://x/v1']]);

        $written = $fs->all()[self::PATH];
        $this->assertStringContainsString('database_dumps:', $written);
        $this->assertSame('var/db', Yaml::parse($written)['database_dumps']['data_dir']);
        $this->assertSame(['data_dir' => 'var/db', 'llm' => ['url' => 'https://x/v1']], $file->read(self::PROJECT));
    }

    /**
     * В этом файле живут и ключи, которыми store не управляет (platform, batch_size).
     * Запись из configure-llm не должна их сносить.
     */
    public function testWritePreservesKeysTheStoreDoesNotManage(): void
    {
        $fs = new InMemoryFileSystem([
            self::PATH => "database_dumps:\n    platform: 'mysql'\n    batch_size: 250\n    data_dir: 'var/db'\n",
        ]);
        $file = new YamlBundleSettingsFile($fs);

        $file->write(self::PROJECT, ['data_dir' => 'var/other', 'llm' => ['url' => 'https://x/v1']]);

        $settings = $file->read(self::PROJECT);
        $this->assertIsArray($settings);
        $this->assertSame('mysql', $settings['platform']);
        $this->assertSame(250, $settings['batch_size']);
        $this->assertSame('var/other', $settings['data_dir'], 'переданное значение перекрывает старое');
        $this->assertSame('https://x/v1', $settings['llm']['url']);
    }

    public function testWriteAddsHeaderComment(): void
    {
        $fs = new InMemoryFileSystem();

        (new YamlBundleSettingsFile($fs))->write(self::PROJECT, ['data_dir' => 'var/db']);

        $this->assertStringStartsWith('# timbrs/database-dumps', $fs->all()[self::PATH]);
    }

    private function fileWith(string $yaml): YamlBundleSettingsFile
    {
        return new YamlBundleSettingsFile(new InMemoryFileSystem([self::PATH => $yaml]));
    }
}
