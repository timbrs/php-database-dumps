<?php

namespace Timbrs\DatabaseDumps\Tests\Unit\Service\ConfigGenerator;

use Timbrs\DatabaseDumps\Config\DumpConfig;
use Timbrs\DatabaseDumps\Contract\FileSystemInterface;
use Timbrs\DatabaseDumps\Contract\LoggerInterface;
use Timbrs\DatabaseDumps\Service\ConfigGenerator\ConfigSplitter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

class ConfigSplitterTest extends TestCase
{
    /** @var FileSystemInterface&\PHPUnit\Framework\MockObject\MockObject */
    private $fileSystem;

    /** @var LoggerInterface&\PHPUnit\Framework\MockObject\MockObject */
    private $logger;

    /** @var ConfigSplitter */
    private $splitter;

    protected function setUp(): void
    {
        $this->fileSystem = $this->createMock(FileSystemInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->splitter = new ConfigSplitter($this->fileSystem, $this->logger);
    }

    public function testSplitSingleSchema(): void
    {
        $config = [
            DumpConfig::KEY_FULL_EXPORT => [
                'public' => ['users', 'roles'],
            ],
            DumpConfig::KEY_PARTIAL_EXPORT => [
                'public' => [
                    'orders' => ['limit' => 500],
                ],
            ],
        ];

        $writtenFiles = [];
        $this->fileSystem->method('write')
            ->willReturnCallback(function ($path, $content) use (&$writtenFiles) {
                $writtenFiles[$path] = $content;
            });

        $this->splitter->split('/tmp/dump_config.yaml', $config);

        // Main config should have includes
        $this->assertArrayHasKey('/tmp/dump_config.yaml', $writtenFiles);
        $mainParsed = Yaml::parse($writtenFiles['/tmp/dump_config.yaml']);
        $this->assertArrayHasKey('includes', $mainParsed);
        $this->assertEquals('public.yaml', $mainParsed['includes']['public']);

        // Schema file should have flat structure
        $this->assertArrayHasKey('/tmp/public.yaml', $writtenFiles);
        $schemaParsed = Yaml::parse($writtenFiles['/tmp/public.yaml']);
        $this->assertContains('users', $schemaParsed[DumpConfig::KEY_FULL_EXPORT]);
        $this->assertArrayHasKey('orders', $schemaParsed[DumpConfig::KEY_PARTIAL_EXPORT]);
    }

    public function testSplitMultiSchema(): void
    {
        $config = [
            DumpConfig::KEY_FULL_EXPORT => [
                'public' => ['users'],
                'billing' => ['invoices'],
            ],
        ];

        $writtenFiles = [];
        $this->fileSystem->method('write')
            ->willReturnCallback(function ($path, $content) use (&$writtenFiles) {
                $writtenFiles[$path] = $content;
            });

        $this->splitter->split('/tmp/dump_config.yaml', $config);

        $this->assertArrayHasKey('/tmp/public.yaml', $writtenFiles);
        $this->assertArrayHasKey('/tmp/billing.yaml', $writtenFiles);

        $mainParsed = Yaml::parse($writtenFiles['/tmp/dump_config.yaml']);
        $this->assertEquals('public.yaml', $mainParsed['includes']['public']);
        $this->assertEquals('billing.yaml', $mainParsed['includes']['billing']);
    }

    public function testSplitWithFaker(): void
    {
        $config = [
            DumpConfig::KEY_FULL_EXPORT => [
                'public' => ['users'],
            ],
            DumpConfig::KEY_FAKER => [
                'public' => [
                    'users' => ['email' => 'email'],
                ],
            ],
        ];

        $writtenFiles = [];
        $this->fileSystem->method('write')
            ->willReturnCallback(function ($path, $content) use (&$writtenFiles) {
                $writtenFiles[$path] = $content;
            });

        $this->splitter->split('/tmp/dump_config.yaml', $config);

        $schemaParsed = Yaml::parse($writtenFiles['/tmp/public.yaml']);
        $this->assertArrayHasKey(DumpConfig::KEY_FAKER, $schemaParsed);
        $this->assertEquals('email', $schemaParsed[DumpConfig::KEY_FAKER]['users']['email']);
    }

    public function testSplitEmptyConfig(): void
    {
        $this->fileSystem->expects($this->once())->method('write');
        $this->splitter->split('/tmp/dump_config.yaml', []);
    }
}
