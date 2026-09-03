<?php

namespace Timbrs\DatabaseDumps\Service\Verification;

use Timbrs\DatabaseDumps\Service\Validation\Finding;

/**
 * Прогоняет все проверки дампов над одним проходом по файлам.
 *
 * Одинаковые находки от разных проверок (например, V-2 «файла нет» замечают и каскад,
 * и счётчик строк) схлопываются по коду и адресу — в отчёте каждая появляется один раз.
 */
class DumpVerificationRunner
{
    /** @var DumpValueReader */
    private $reader;

    /** @var array<int, DumpVerifierInterface> */
    private $verifiers;

    /**
     * @param array<int, DumpVerifierInterface> $verifiers
     */
    public function __construct(DumpValueReader $reader, array $verifiers)
    {
        $this->reader = $reader;
        $this->verifiers = array_values($verifiers);
    }

    /**
     * @return array{findings: array<int, Finding>, stats: array<string, array<string, int>>}
     */
    public function run(DumpVerificationInput $input): array
    {
        $store = new DumpColumnStore($this->reader);

        foreach ($this->verifiers as $verifier) {
            $verifier->plan($input, $store);
        }
        $store->load();

        $findings = [];
        $seen = [];
        $stats = [];
        foreach ($this->verifiers as $verifier) {
            foreach ($verifier->check($input, $store) as $finding) {
                $key = $finding->getCode() . '|' . $finding->getTarget();
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $findings[] = $finding;
            }
            $stats[$this->shortName($verifier)] = $verifier->stats();
        }

        usort($findings, function (Finding $a, Finding $b): int {
            $bySeverity = $a->severityRank() <=> $b->severityRank();
            if ($bySeverity !== 0) {
                return $bySeverity;
            }
            $byCode = strcmp($a->getCode(), $b->getCode());

            return $byCode !== 0 ? $byCode : strcmp($a->getTarget(), $b->getTarget());
        });

        return ['findings' => $findings, 'stats' => $stats];
    }

    private function shortName(DumpVerifierInterface $verifier): string
    {
        $class = get_class($verifier);
        $pos = strrpos($class, '\\');

        return $pos === false ? $class : substr($class, $pos + 1);
    }
}
