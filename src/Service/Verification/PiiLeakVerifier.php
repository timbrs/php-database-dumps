<?php

namespace Timbrs\DatabaseDumps\Service\Verification;

use Timbrs\DatabaseDumps\Config\TableConfig;
use Timbrs\DatabaseDumps\Service\Faker\PatternDetector;
use Timbrs\DatabaseDumps\Service\Validation\Finding;
use Timbrs\DatabaseDumps\Service\Verification\Sink\SampleSink;

/**
 * V-7: персональные данные в готовом дампе.
 *
 * Faker настраивается по колонкам, и колонка без faker — единственный путь ПД в дамп.
 * Здесь проверяются значения, которые реально легли в файл: первые SAMPLE_SIZE непустых
 * значений каждой колонки без faker прогоняются через те же регулярные выражения, что и
 * детектор ПД при генерации конфига. Совпадение — ошибка: дамп с такими данными отдавать
 * нельзя. Колонка с явным ПД-именем, для которой значений мало, — предупреждение.
 *
 * Ни одно значение в находку не попадает — только имя колонки и найденный паттерн.
 */
class PiiLeakVerifier implements DumpVerifierInterface
{
    /** В дампе колонка с персональными данными без faker. */
    public const CODE_PII_LEAK = 'V-7';

    public const SAMPLE_SIZE = 2000;

    /** Меньше значений — регулярные выражения не показательны, решает имя колонки. */
    private const MIN_VALUES = 10;

    /** @var array<string, array<string, array{sink: SampleSink, path: string}>> */
    private $planned = [];

    /** @var array<string, int> */
    private $stats = ['columns_checked' => 0, 'leaks' => 0];

    public function plan(DumpVerificationInput $input, DumpColumnStore $store): void
    {
        $this->planned = [];
        $this->stats = ['columns_checked' => 0, 'leaks' => 0];

        foreach ($input->getTables() as $config) {
            $path = $input->pathFor($config);
            if (!is_file($path)) {
                continue;
            }
            $key = $config->getFullTableName();
            $faker = $input->fakerFor($config);
            $store->requestAll($path, function (string $column) use ($key, $path, $faker) {
                if (isset($faker[$column])) {
                    return null;
                }
                $sink = new SampleSink(self::SAMPLE_SIZE);
                $this->planned[$key][$column] = ['sink' => $sink, 'path' => $path];

                return $sink;
            });
        }
    }

    public function check(DumpVerificationInput $input, DumpColumnStore $store): array
    {
        $findings = [];

        foreach ($input->getTables() as $config) {
            $key = $config->getFullTableName();
            foreach ($this->planned[$key] ?? [] as $column => $plan) {
                $this->stats['columns_checked']++;
                $finding = $this->inspect($config, (string) $column, $plan['sink']);
                if ($finding !== null) {
                    $this->stats['leaks']++;
                    $findings[] = $finding;
                }
            }
        }

        return $findings;
    }

    public function stats(): array
    {
        return $this->stats;
    }

    private function inspect(TableConfig $config, string $column, SampleSink $sink): ?Finding
    {
        $values = $sink->values();
        $suggestion = [
            'column' => $column,
            'values_checked' => count($values),
            'sampled' => $sink->isSampled(),
        ];

        if (count($values) >= self::MIN_VALUES) {
            $pattern = PatternDetector::detectPatternInValues($column, $values);
            if ($pattern !== null) {
                $suggestion['pattern'] = $pattern;
                $suggestion['detected_by'] = 'values';

                return Finding::error(
                    self::CODE_PII_LEAK,
                    sprintf(
                        'в дампе %s.%s колонка %s без faker, а её значения выглядят как %s — персональные данные ушли в файл',
                        $config->getSchema(),
                        $config->getTable(),
                        $column,
                        $pattern
                    ),
                    $config->getSchema(),
                    $config->getTable(),
                    $column,
                    false,
                    $suggestion
                );
            }

            return null;
        }

        if (PatternDetector::hintsPiiStrict($column)) {
            $suggestion['detected_by'] = 'name';

            return Finding::warning(
                self::CODE_PII_LEAK,
                sprintf(
                    'в дампе %s.%s колонка %s без faker: имя говорит о персональных данных, значений для проверки по содержимому мало (%d)',
                    $config->getSchema(),
                    $config->getTable(),
                    $column,
                    count($values)
                ),
                $config->getSchema(),
                $config->getTable(),
                $column,
                false,
                $suggestion
            );
        }

        return null;
    }
}
