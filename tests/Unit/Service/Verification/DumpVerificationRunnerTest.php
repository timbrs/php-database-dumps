<?php

namespace Timbrs\DatabaseDumps\Tests\Unit\Service\Verification;

use PHPUnit\Framework\TestCase;
use Timbrs\DatabaseDumps\Service\Validation\Finding;
use Timbrs\DatabaseDumps\Service\Verification\DumpColumnStore;
use Timbrs\DatabaseDumps\Service\Verification\DumpValueReader;
use Timbrs\DatabaseDumps\Service\Verification\DumpVerificationInput;
use Timbrs\DatabaseDumps\Service\Verification\DumpVerificationRunner;
use Timbrs\DatabaseDumps\Service\Verification\DumpVerifierInterface;

class DumpVerificationRunnerTest extends TestCase
{
    public function testPlansEveryVerifierBeforeCheckingAndDeduplicatesFindings(): void
    {
        $log = [];
        $first = $this->verifier('first', $log, [
            Finding::warning('V-2', 'нет файла', 'public', 'orders'),
            Finding::error('V-1', 'сироты', 'public', 'items', 'order_id'),
        ]);
        $second = $this->verifier('second', $log, [
            Finding::warning('V-2', 'нет файла (снова)', 'public', 'orders'),
            Finding::note('V-9', 'заметка', 'public', 'orders'),
        ]);

        $result = (new DumpVerificationRunner(new DumpValueReader(), [$first, $second]))
            ->run(new DumpVerificationInput(sys_get_temp_dir(), []));

        self::assertSame(['plan:first', 'plan:second', 'check:first', 'check:second'], $log);
        self::assertSame(['V-1', 'V-2', 'V-9'], array_map(function (Finding $f) {
            return $f->getCode();
        }, $result['findings']));
        // Дубль V-2 по той же таблице от второй проверки схлопнут; остался первый текст.
        self::assertSame('нет файла', $result['findings'][1]->getMessage());
        // Счётчики ключуются коротким именем класса проверки; у двух анонимных классов оно одно.
        self::assertSame([['seen' => 1]], array_values($result['stats']));
    }

    /**
     * @param array<int, string>  $log
     * @param array<int, Finding> $findings
     */
    private function verifier(string $name, array &$log, array $findings): DumpVerifierInterface
    {
        return new class($name, $log, $findings) implements DumpVerifierInterface {
            /** @var string */
            private $name;
            /** @var array<int, string> */
            private $log;
            /** @var array<int, Finding> */
            private $findings;

            /**
             * @param array<int, string>  $log
             * @param array<int, Finding> $findings
             */
            public function __construct(string $name, array &$log, array $findings)
            {
                $this->name = $name;
                $this->log = &$log;
                $this->findings = $findings;
            }

            public function plan(DumpVerificationInput $input, DumpColumnStore $store): void
            {
                $this->log[] = 'plan:' . $this->name;
            }

            public function check(DumpVerificationInput $input, DumpColumnStore $store): array
            {
                $this->log[] = 'check:' . $this->name;

                return $this->findings;
            }

            public function stats(): array
            {
                // К моменту stats() в общем журнале уже есть и plan, и check обеих проверок.
                return ['seen' => count($this->log) >= 4 ? 1 : 0];
            }
        };
    }
}
