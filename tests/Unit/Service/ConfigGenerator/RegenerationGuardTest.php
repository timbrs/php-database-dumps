<?php

namespace Timbrs\DatabaseDumps\Tests\Unit\Service\ConfigGenerator;

use PHPUnit\Framework\TestCase;
use Timbrs\DatabaseDumps\Service\ConfigGenerator\ConfigGenerator;
use Timbrs\DatabaseDumps\Service\ConfigGenerator\RegenerationGuard;
use Timbrs\DatabaseDumps\Tests\Support\InMemoryFileSystem;

class RegenerationGuardTest extends TestCase
{
    private const CONFIG = '/app/database/dump_config.yaml';

    public function testBlocksFullRegenerationOverExistingConfig(): void
    {
        $guard = $this->guardWithConfig();

        $this->assertTrue($guard->blocks(ConfigGenerator::MODE_ALL, self::CONFIG, false));
    }

    public function testForceAllowsFullRegeneration(): void
    {
        $guard = $this->guardWithConfig();

        $this->assertFalse($guard->blocks(ConfigGenerator::MODE_ALL, self::CONFIG, true));
    }

    public function testFirstGenerationIsNotBlocked(): void
    {
        $guard = new RegenerationGuard(new InMemoryFileSystem());

        $this->assertFalse($guard->blocks(ConfigGenerator::MODE_ALL, self::CONFIG, false));
    }

    /**
     * Режимы, которые мёржат сгенерированное в существующий конфиг, а не затирают его.
     *
     * @dataProvider mergingModesProvider
     */
    public function testMergingModesAreNotBlocked(string $mode): void
    {
        $guard = $this->guardWithConfig();

        $this->assertFalse($guard->blocks($mode, self::CONFIG, false));
    }

    /**
     * @return array<int, array<int, string>>
     */
    public static function mergingModesProvider(): array
    {
        return [
            [ConfigGenerator::MODE_NEW],
            [ConfigGenerator::MODE_SCHEMA],
            [ConfigGenerator::MODE_TABLE],
        ];
    }

    public function testRefusalNamesPathAdaptationCommandsAndForce(): void
    {
        $guard = $this->guardWithConfig();

        $text = implode("\n", $guard->getRefusalLines(self::CONFIG, 'app:dbdump:'));

        $this->assertStringContainsString(self::CONFIG, $text);
        $this->assertStringContainsString('app:dbdump:prepare-config new', $text);
        $this->assertStringContainsString('app:dbdump:prepare-config schema=<name>', $text);
        $this->assertStringContainsString('app:dbdump:prepare-config table=<s.t>', $text);
        $this->assertStringContainsString('app:dbdump:repair-configs', $text);
        $this->assertStringContainsString('app:dbdump:validate', $text);
        $this->assertStringContainsString('--force', $text);
    }

    public function testRefusalUsesBridgeCommandPrefix(): void
    {
        $guard = $this->guardWithConfig();

        $text = implode("\n", $guard->getRefusalLines(self::CONFIG, 'dbdump:'));

        $this->assertStringContainsString('dbdump:prepare-config new', $text);
        $this->assertStringNotContainsString('app:dbdump:', $text);
    }

    private function guardWithConfig(): RegenerationGuard
    {
        return new RegenerationGuard(new InMemoryFileSystem([self::CONFIG => "includes: []\n"]));
    }
}
