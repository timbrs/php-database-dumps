<?php

namespace Timbrs\DatabaseDumps\Service\Verification;

use Timbrs\DatabaseDumps\Contract\FileSystemInterface;
use Timbrs\DatabaseDumps\Service\Validation\Finding;

/**
 * V-6: что дала выборка по корзинам — читает `analysis/sample-report.json`, который пишет
 * экспорт.
 *
 * Единственная проверка, которая видит **пустую корзину**. Ни файл дампа, ни счётчик строк
 * этого не скажут: критерий отработал, ошибки не было, просто ничего не нашлось — и вида
 * данных, ради которого корзину заводили, в дампе нет. Остальные проверки увидят ровный дамп
 * нужного размера и промолчат.
 *
 * Три повода:
 *  - корзина вернула ноль строк без ошибки — вид данных отсутствует, критерий не ловит ничего;
 *  - корзина упала — SQL корзины неверен, но экспорт этого не заметил (сборщик запросов
 *    пропускает такую корзину и идёт дальше);
 *  - выборка усечена потолком `limit` — часть корзин урезана, и покрытие ниже задуманного.
 *
 * Значения корзин здесь не появляются: корзина названа колонкой и номером, а коды показывает
 * только тот, кто прошёл PII-шлюз, — эта проверка ничего к отчёту не добавляет от себя.
 *
 * PHP 7.2-совместимо.
 */
class SampleReportVerifier implements DumpVerifierInterface
{
    public const CODE = 'V-6';

    public const REPORT_FILE = 'sample-report.json';

    /** @var FileSystemInterface */
    private $fileSystem;

    /** @var string|null Явный путь к отчёту; null — рядом с дампами, в ../analysis/ */
    private $reportPath;

    /** @var array<string, int> */
    private $stats = [];

    public function __construct(FileSystemInterface $fileSystem, ?string $reportPath = null)
    {
        $this->fileSystem = $fileSystem;
        $this->reportPath = $reportPath;
    }

    /**
     * Колонок дампа проверке не нужно: она читает отчёт выборки, а не файлы.
     */
    public function plan(DumpVerificationInput $input, DumpColumnStore $store): void
    {
    }

    /**
     * @return array<int, Finding>
     */
    public function check(DumpVerificationInput $input, DumpColumnStore $store): array
    {
        $this->stats = ['tables' => 0, 'empty_buckets' => 0, 'failed_buckets' => 0, 'truncated' => 0];

        $report = $this->load($input);
        if ($report === null) {
            return [];
        }

        $tables = isset($report['tables']) && is_array($report['tables']) ? $report['tables'] : [];
        $wanted = $this->wantedKeys($input);

        $findings = [];
        foreach ($tables as $key => $entry) {
            $key = (string) $key;
            if (!is_array($entry) || ($wanted !== null && !isset($wanted[$key]))) {
                continue;
            }
            $this->stats['tables']++;
            list($schema, $table) = $this->split($key);

            foreach (isset($entry['buckets']) && is_array($entry['buckets']) ? $entry['buckets'] : [] as $bucket) {
                if (!is_array($bucket)) {
                    continue;
                }
                $name = isset($bucket['name']) ? (string) $bucket['name'] : '?';

                if (isset($bucket['error']) && $bucket['error'] !== null && $bucket['error'] !== '') {
                    $this->stats['failed_buckets']++;
                    $findings[] = Finding::error(
                        self::CODE,
                        sprintf('корзина «%s» упала при выборке: %s', $name, (string) $bucket['error']),
                        $schema,
                        $table,
                        null,
                        false,
                        [
                            'bucket' => $name,
                            'error' => (string) $bucket['error'],
                            'hint' => 'экспорт такую корзину пропускает молча — проверьте её условие '
                                . 'на стадии live',
                        ]
                    );
                    continue;
                }

                if (isset($bucket['rows']) && (int) $bucket['rows'] === 0) {
                    $this->stats['empty_buckets']++;
                    $findings[] = Finding::warning(
                        self::CODE,
                        sprintf('корзина «%s» пуста: вида данных, ради которого её завели, в дампе нет', $name),
                        $schema,
                        $table,
                        null,
                        false,
                        [
                            'bucket' => $name,
                            'quota' => isset($bucket['limit']) ? (int) $bucket['limit'] : null,
                            'hint' => 'либо условие корзины не описывает ни одной строки в базе, либо '
                                . 'значение действительно не встречается — второе стоит подтвердить по коду',
                        ]
                    );
                }
            }

            if (!empty($entry['truncated_by_cap'])) {
                $this->stats['truncated']++;
                $findings[] = Finding::warning(
                    self::CODE,
                    sprintf(
                        'выборка усечена потолком limit: отобрано %d из %d, квота на корзину уменьшена',
                        isset($entry['selected']) ? (int) $entry['selected'] : 0,
                        isset($entry['before_cap']) ? (int) $entry['before_cap'] : 0
                    ),
                    $schema,
                    $table,
                    null,
                    false,
                    [
                        'cap' => isset($entry['cap']) ? $entry['cap'] : null,
                        'selected' => isset($entry['selected']) ? (int) $entry['selected'] : 0,
                        'before_cap' => isset($entry['before_cap']) ? (int) $entry['before_cap'] : 0,
                        'hint' => 'покрытие сохранено (корзины объединяются по кругу), но строк в каждой '
                            . 'меньше задуманного: поднимите limit или снизьте per_value осознанно',
                    ]
                );
            }
        }

        return $findings;
    }

    /**
     * @return array<string, int>
     */
    public function stats(): array
    {
        return $this->stats;
    }

    /**
     * Отчёт выборки. Нет файла — проверка молчит: выгрузка могла идти без корзин вовсе,
     * и находка «отчёта нет» была бы шумом на каждом полном экспорте.
     *
     * @return array<string, mixed>|null
     */
    private function load(DumpVerificationInput $input): ?array
    {
        $path = $this->reportPath !== null
            ? $this->reportPath
            : dirname($input->getDumpsRoot()) . '/analysis/' . self::REPORT_FILE;

        if (!$this->fileSystem->exists($path)) {
            return null;
        }
        try {
            $decoded = json_decode($this->fileSystem->read($path), true);
        } catch (\Throwable $e) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Таблицы прогона: отчёт лежит от предыдущего экспорта целиком, а проверять просили
     * только эти. `null` — фильтра нет.
     *
     * @return array<string, true>|null
     */
    private function wantedKeys(DumpVerificationInput $input): ?array
    {
        $tables = $input->getTables();
        if ($tables === []) {
            return null;
        }
        $keys = [];
        foreach ($tables as $config) {
            $keys[$config->getSchema() . '.' . $config->getTable()] = true;
        }

        return $keys;
    }

    /**
     * @return array<int, string>
     */
    private function split(string $key): array
    {
        $pos = strpos($key, '.');

        return $pos === false ? ['', $key] : [substr($key, 0, $pos), substr($key, $pos + 1)];
    }
}
