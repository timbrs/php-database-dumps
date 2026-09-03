<?php

namespace Timbrs\DatabaseDumps\Tests\Unit\Service\Verification;

use PHPUnit\Framework\TestCase;
use Timbrs\DatabaseDumps\Config\DumpConfig;
use Timbrs\DatabaseDumps\Config\FakerConfig;
use Timbrs\DatabaseDumps\Config\TableConfig;
use Timbrs\DatabaseDumps\Service\Validation\Finding;
use Timbrs\DatabaseDumps\Service\Verification\DumpValueReader;
use Timbrs\DatabaseDumps\Service\Verification\DumpVerificationInput;
use Timbrs\DatabaseDumps\Service\Verification\DumpVerificationRunner;
use Timbrs\DatabaseDumps\Service\Verification\PiiLeakVerifier;

class PiiLeakVerifierTest extends TestCase
{
    /** @var string */
    private $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/pii_' . bin2hex(random_bytes(4));
        mkdir($this->root . '/public', 0777, true);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->root . '/public/*') ?: []);
        rmdir($this->root . '/public');
        rmdir($this->root);
    }

    public function testEmailValuesWithoutFakerAreAnErrorAndNeverQuoted(): void
    {
        $this->dump(12, function (int $i): string {
            return "'user{$i}@bank.ru'";
        });

        $findings = $this->verify(null);

        self::assertCount(1, $findings);
        $finding = $findings[0];
        self::assertSame(PiiLeakVerifier::CODE_PII_LEAK, $finding->getCode());
        self::assertSame(Finding::SEVERITY_ERROR, $finding->getSeverity());
        self::assertSame('contact', $finding->getColumn());
        self::assertSame('email', $finding->getSuggestion()['pattern']);
        self::assertSame('values', $finding->getSuggestion()['detected_by']);
        self::assertStringNotContainsString('bank.ru', (string) json_encode($finding->toArray()));
    }

    public function testColumnCoveredByFakerIsNotInspected(): void
    {
        $this->dump(12, function (int $i): string {
            return "'user{$i}@bank.ru'";
        });
        $faker = new FakerConfig(['public' => ['clients' => ['contact' => 'email']]]);

        $result = $this->runVerifier(new DumpConfig([], [], [], $faker));

        self::assertSame([], $result['findings']);
        // id проверен, contact — нет.
        self::assertSame(['columns_checked' => 1, 'leaks' => 0], $result['stats']['PiiLeakVerifier']);
    }

    public function testPiiNamedColumnWithFewValuesIsAWarning(): void
    {
        file_put_contents(
            $this->root . '/public/clients.sql',
            "INSERT INTO \"public\".\"clients\" (\"id\", \"last_name\") VALUES\n(1, 'Иванов'),\n(2, 'Петров');\n"
        );

        $findings = $this->verify(null);

        self::assertCount(1, $findings);
        self::assertSame(Finding::SEVERITY_WARNING, $findings[0]->getSeverity());
        self::assertSame('name', $findings[0]->getSuggestion()['detected_by']);
    }

    public function testCodesAreNotPersonalData(): void
    {
        $this->dump(12, function (int $i): string {
            return $i % 2 === 0 ? "'ACTIVE'" : "'CLOSED'";
        });

        self::assertSame([], $this->verify(null));
    }

    /**
     * @return array<int, Finding>
     */
    private function verify(?DumpConfig $dumpConfig): array
    {
        return $this->runVerifier($dumpConfig)['findings'];
    }

    /**
     * @return array{findings: array<int, Finding>, stats: array<string, array<string, int>>}
     */
    private function runVerifier(?DumpConfig $dumpConfig): array
    {
        $runner = new DumpVerificationRunner(new DumpValueReader(), [new PiiLeakVerifier()]);

        return $runner->run(new DumpVerificationInput($this->root, [new TableConfig('public', 'clients', 100)], null, $dumpConfig));
    }

    /**
     * @param callable(int): string $value SQL-литерал колонки contact
     */
    private function dump(int $rows, callable $value): void
    {
        $tuples = [];
        for ($i = 1; $i <= $rows; $i++) {
            $tuples[] = sprintf('(%d, %s)', $i, $value($i));
        }
        file_put_contents(
            $this->root . '/public/clients.sql',
            "INSERT INTO \"public\".\"clients\" (\"id\", \"contact\") VALUES\n" . implode(",\n", $tuples) . ";\n"
        );
    }
}
