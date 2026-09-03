<?php

namespace Timbrs\DatabaseDumps\Service\Verification;

use Timbrs\DatabaseDumps\Service\Validation\Finding;

/**
 * Проверка выгруженных дампов в два шага: заявить нужные колонки (plan), после общего
 * прохода по файлам — вынести находки (check). Между ними DumpColumnStore читает каждый
 * файл один раз для всех проверок.
 */
interface DumpVerifierInterface
{
    public function plan(DumpVerificationInput $input, DumpColumnStore $store): void;

    /**
     * @return array<int, Finding>
     */
    public function check(DumpVerificationInput $input, DumpColumnStore $store): array;

    /**
     * Счётчики прогона для отчёта.
     *
     * @return array<string, int>
     */
    public function stats(): array;
}
