<?php

namespace Timbrs\DatabaseDumps\Service\Analysis;

use Symfony\Component\Yaml\Yaml;
use Timbrs\DatabaseDumps\Config\DumpConfig;
use Timbrs\DatabaseDumps\Config\TableConfig;
use Timbrs\DatabaseDumps\Contract\FileSystemInterface;
use Timbrs\DatabaseDumps\Contract\LoggerInterface;
use Timbrs\DatabaseDumps\Service\Ai\DbdumpConfigStore;
use Timbrs\DatabaseDumps\Service\ConfigGenerator\ConfigSplitter;

/**
 * Переносит принятые предложения из анализа кода (OPENCODE) в dump_config.yaml:
 *  - cascade_from (source: code) — связи без FK,
 *  - sample.criteria — именованные бизнес-сегменты.
 *
 * Пользовательские правки в приоритете: добавляется только отсутствующее
 * (cascade_from-рёбра по (parent, fk_column); критерии по name). Невалидные
 * предложения отбрасываются (проверка через TableConfig).
 *
 * Провенанс/уверенность фиксируются в REPORT.md (symfony/yaml не хранит
 * комментарии); значения попадают в YAML.
 */
class ConfigEnricher
{
    /**
     * Идентификатор схемы/таблицы: защита от path traversal и инъекций в YAML/SQL.
     * Имена из внешнего JSON (вывод OPENCODE) — НЕДОВЕРЕННЫЕ, валидируем перед
     * использованием как ключей конфига (которые могут стать путями файлов в ConfigSplitter).
     */
    private const IDENTIFIER_REGEX = '/^[\p{L}_][\p{L}\p{N}_$]*$/u';

    /** @var FileSystemInterface */
    private $fileSystem;

    /** @var ConfigSplitter */
    private $configSplitter;

    /** @var LoggerInterface */
    private $logger;

    /** @var string|null Корень хост-проекта (для каталога {data_dir}/analysis) */
    private $projectDir;

    /** @var DbdumpConfigStore|null */
    private $configStore;

    public function __construct(
        FileSystemInterface $fileSystem,
        ConfigSplitter $configSplitter,
        LoggerInterface $logger,
        ?string $projectDir = null,
        DbdumpConfigStore $configStore = null
    ) {
        $this->fileSystem = $fileSystem;
        $this->configSplitter = $configSplitter;
        $this->logger = $logger;
        $this->projectDir = $projectDir !== null ? rtrim($projectDir, '/\\') : null;
        $this->configStore = $configStore;
    }

    /**
     * @param array{cascade_from: array<int, array<string, mixed>>, sample_criteria: array<int, array<string, mixed>>} $ingested
     * @return array{cascade_added: int, criteria_added: int}
     *
     * @throws \RuntimeException если конфиг не найден
     */
    public function enrich(string $configPath, array $ingested): array
    {
        if (!$this->fileSystem->exists($configPath)) {
            throw new \RuntimeException(
                "dump_config.yaml не найден ({$configPath}). Сначала выполните prepare-config."
            );
        }

        $raw = Yaml::parse($this->fileSystem->read($configPath));
        if (!is_array($raw)) {
            $raw = [];
        }
        $wasSplit = $this->isSplit($raw);
        $config = $this->resolveIncludes($raw, dirname($configPath));

        $cascadeAdded = $this->applyCascade($config, $ingested['cascade_from']);
        $criteriaAdded = $this->applyCriteria($config, $ingested['sample_criteria']);

        if ($wasSplit) {
            $this->configSplitter->split($configPath, $config);
        } else {
            $this->fileSystem->write($configPath, Yaml::dump($config, 6, 2));
        }

        $this->appendReport($this->resolveAnalysisDir($configPath), $ingested, $cascadeAdded, $criteriaAdded);

        $this->logger->info(sprintf(
            'Конфиг обогащён из анализа кода: +%d cascade_from, +%d критериев',
            $cascadeAdded,
            $criteriaAdded
        ));

        return ['cascade_added' => $cascadeAdded, 'criteria_added' => $criteriaAdded];
    }

    /**
     * @param array<string, mixed> &$config
     * @param array<int, array<string, mixed>> $cascadeEntries
     */
    private function applyCascade(array &$config, array $cascadeEntries): int
    {
        $added = 0;
        foreach ($cascadeEntries as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $schema = (string) ($entry['schema'] ?? '');
            $table = (string) ($entry['table'] ?? '');

            // НЕДОВЕРЕННЫЙ ввод: schema/table могут стать ключами конфига и путями файлов.
            if (!$this->isIdentifier($schema) || !$this->isIdentifier($table)) {
                $this->logger->warning('Отброшен cascade_from с некорректным schema/table.');
                continue;
            }

            $cascade = [
                'parent' => (string) ($entry['parent'] ?? ''),
                'fk_column' => (string) ($entry['fk_column'] ?? ''),
                'parent_column' => (string) ($entry['parent_column'] ?? ''),
            ];

            // Берём текущее состояние БЕЗ мутации конфига (мутируем только после валидации).
            $existing = $this->currentCascade($config, $schema, $table);

            // Дедуп по (parent, fk_column) — пользовательские/FK-записи в приоритете.
            $duplicate = false;
            foreach ($existing as $e) {
                if (is_array($e)
                    && ($e['parent'] ?? null) === $cascade['parent']
                    && ($e['fk_column'] ?? null) === $cascade['fk_column']) {
                    $duplicate = true;
                    break;
                }
            }
            if ($duplicate) {
                continue;
            }

            $candidate = $existing;
            $candidate[] = $cascade;
            if (!$this->validatesCascade($schema, $table, $candidate)) {
                $this->logger->warning("Отброшен невалидный cascade_from для {$schema}.{$table}");
                continue;
            }

            // Мутируем конфиг только после успешной валидации предложения.
            $this->ensureTablePresent($config, $schema, $table);
            $config[DumpConfig::KEY_PARTIAL_EXPORT][$schema][$table][TableConfig::KEY_CASCADE_FROM] = $candidate;
            $added++;
        }
        return $added;
    }

    /**
     * @param array<string, mixed> &$config
     * @param array<int, array<string, mixed>> $criteria
     */
    private function applyCriteria(array &$config, array $criteria): int
    {
        $added = 0;
        foreach ($criteria as $crit) {
            if (!is_array($crit)) {
                continue;
            }
            $schema = (string) ($crit['schema'] ?? '');
            $table = (string) ($crit['table'] ?? '');

            // НЕДОВЕРЕННЫЙ ввод: schema/table могут стать ключами конфига и путями файлов.
            if (!$this->isIdentifier($schema) || !$this->isIdentifier($table)) {
                $this->logger->warning('Отброшен criterion с некорректным schema/table.');
                continue;
            }

            $name = (string) ($crit['name'] ?? '');
            $where = (string) ($crit['where'] ?? '');
            $limit = isset($crit['limit']) && $crit['limit'] !== null
                ? (int) $crit['limit']
                : TableConfig::DEFAULT_PER_VALUE;
            if ($limit < 1) {
                $limit = TableConfig::DEFAULT_PER_VALUE;
            }

            // Текущая секция sample без мутации конфига.
            $sample = $this->currentSample($config, $schema, $table);
            $criteriaList = $sample[TableConfig::SAMPLE_KEY_CRITERIA] ?? [];
            if (!is_array($criteriaList)) {
                $criteriaList = [];
            }

            // Пропускаем, если критерий с таким name уже есть.
            $duplicate = false;
            foreach ($criteriaList as $existingCrit) {
                if (is_array($existingCrit) && ($existingCrit['name'] ?? null) === $name) {
                    $duplicate = true;
                    break;
                }
            }
            if ($duplicate) {
                continue;
            }

            $newCriterion = ['name' => $name, 'where' => $where, 'limit' => $limit];
            $candidateList = $criteriaList;
            $candidateList[] = $newCriterion;
            $candidateSample = $sample;
            $candidateSample[TableConfig::SAMPLE_KEY_CRITERIA] = $candidateList;

            if (!$this->validatesSample($schema, $table, $candidateSample)) {
                $this->logger->warning("Отброшен невалидный criterion '{$name}' для {$schema}.{$table}");
                continue;
            }

            // Мутируем конфиг только после успешной валидации предложения.
            $this->ensureTablePresent($config, $schema, $table);
            $config[DumpConfig::KEY_PARTIAL_EXPORT][$schema][$table][TableConfig::KEY_SAMPLE] = $candidateSample;
            $added++;
        }
        return $added;
    }

    /**
     * Гарантировать наличие записи таблицы в partial_export (перенос из full_export при необходимости).
     *
     * @param array<string, mixed> &$config
     */
    private function ensureTablePresent(array &$config, string $schema, string $table): void
    {
        if (isset($config[DumpConfig::KEY_PARTIAL_EXPORT][$schema][$table])
            && is_array($config[DumpConfig::KEY_PARTIAL_EXPORT][$schema][$table])) {
            return;
        }

        // Если таблица в full_export — переносим в partial_export (выборка станет управляемой).
        if (isset($config[DumpConfig::KEY_FULL_EXPORT][$schema])
            && is_array($config[DumpConfig::KEY_FULL_EXPORT][$schema])) {
            $idx = array_search($table, $config[DumpConfig::KEY_FULL_EXPORT][$schema], true);
            if ($idx !== false) {
                array_splice($config[DumpConfig::KEY_FULL_EXPORT][$schema], (int) $idx, 1);
                if (empty($config[DumpConfig::KEY_FULL_EXPORT][$schema])) {
                    unset($config[DumpConfig::KEY_FULL_EXPORT][$schema]);
                }
            }
        }

        if (!isset($config[DumpConfig::KEY_PARTIAL_EXPORT][$schema])) {
            $config[DumpConfig::KEY_PARTIAL_EXPORT][$schema] = [];
        }
        if (!isset($config[DumpConfig::KEY_PARTIAL_EXPORT][$schema][$table])) {
            $config[DumpConfig::KEY_PARTIAL_EXPORT][$schema][$table] = [];
        }
    }

    /**
     * Текущий cascade_from таблицы БЕЗ мутации конфига.
     *
     * @param array<string, mixed> $config
     * @return array<int, mixed>
     */
    private function currentCascade(array $config, string $schema, string $table): array
    {
        $tableConf = $config[DumpConfig::KEY_PARTIAL_EXPORT][$schema][$table] ?? null;
        if (!is_array($tableConf)) {
            return [];
        }
        $existing = $tableConf[TableConfig::KEY_CASCADE_FROM] ?? [];
        return is_array($existing) ? $existing : [];
    }

    /**
     * Текущая секция sample таблицы БЕЗ мутации конфига.
     *
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private function currentSample(array $config, string $schema, string $table): array
    {
        $tableConf = $config[DumpConfig::KEY_PARTIAL_EXPORT][$schema][$table] ?? null;
        if (!is_array($tableConf)) {
            return [];
        }
        $sample = $tableConf[TableConfig::KEY_SAMPLE] ?? [];
        return is_array($sample) ? $sample : [];
    }

    /**
     * @param mixed $value
     */
    private function isIdentifier($value): bool
    {
        return is_string($value) && $value !== '' && (bool) preg_match(self::IDENTIFIER_REGEX, $value);
    }

    /**
     * @param array<int, mixed> $cascade
     */
    private function validatesCascade(string $schema, string $table, array $cascade): bool
    {
        try {
            new TableConfig($schema, $table, null, null, null, null, $cascade);
            return true;
        } catch (\InvalidArgumentException $e) {
            return false;
        }
    }

    /**
     * @param array<string, mixed> $sample
     */
    private function validatesSample(string $schema, string $table, array $sample): bool
    {
        try {
            new TableConfig($schema, $table, null, null, null, null, null, null, $sample);
            return true;
        } catch (\InvalidArgumentException $e) {
            return false;
        }
    }

    /**
     * @param array<string, mixed> $config
     */
    private function isSplit(array $config): bool
    {
        if (isset($config[DumpConfig::KEY_INCLUDES])) {
            return true;
        }
        if (isset($config[DumpConfig::KEY_CONNECTIONS]) && is_array($config[DumpConfig::KEY_CONNECTIONS])) {
            foreach ($config[DumpConfig::KEY_CONNECTIONS] as $connData) {
                if (is_array($connData) && isset($connData[DumpConfig::KEY_INCLUDES])) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Резолвить includes в плоскую структуру (как ConfigGenerator).
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function resolveIncludes(array $data, string $configDir): array
    {
        if (isset($data[DumpConfig::KEY_INCLUDES]) && is_array($data[DumpConfig::KEY_INCLUDES])) {
            foreach ($data[DumpConfig::KEY_INCLUDES] as $schema => $relativePath) {
                $filePath = $configDir . '/' . $relativePath;
                if (!$this->fileSystem->exists($filePath)) {
                    continue;
                }
                $schemaData = Yaml::parse($this->fileSystem->read($filePath));
                if (!is_array($schemaData)) {
                    continue;
                }
                foreach ([DumpConfig::KEY_FULL_EXPORT, DumpConfig::KEY_PARTIAL_EXPORT, DumpConfig::KEY_FAKER] as $key) {
                    if (isset($schemaData[$key])) {
                        if (!isset($data[$key])) {
                            $data[$key] = [];
                        }
                        $data[$key][$schema] = $schemaData[$key];
                    }
                }
            }
            unset($data[DumpConfig::KEY_INCLUDES]);
        }

        if (isset($data[DumpConfig::KEY_CONNECTIONS]) && is_array($data[DumpConfig::KEY_CONNECTIONS])) {
            foreach ($data[DumpConfig::KEY_CONNECTIONS] as $connName => $connData) {
                if (is_array($connData)) {
                    $data[DumpConfig::KEY_CONNECTIONS][$connName] = $this->resolveIncludes($connData, $configDir);
                }
            }
        }

        return $data;
    }

    /**
     * Каталог анализа: projectDir/database/analysis (как у prepare-analysis), иначе —
     * сиблинг конфига (BC, когда projectDir не задан).
     */
    private function resolveAnalysisDir(string $configPath): string
    {
        if ($this->projectDir !== null) {
            $dataDir = $this->configStore !== null
                ? $this->configStore->getDataDir($this->projectDir)
                : DbdumpConfigStore::DEFAULT_DATA_DIR;
            return $this->projectDir . '/' . $dataDir . '/' . AnalysisPackageBuilder::ANALYSIS_DIR;
        }
        return dirname($configPath) . '/analysis';
    }

    /**
     * Дополнить REPORT.md секцией анализа кода (идемпотентно через маркеры) и
     * записать ту же информацию в analysis_result.json (ключ code_analysis),
     * не затирая секцию данных.
     *
     * @param array<string, mixed> $ingested
     */
    private function appendReport(string $analysisDir, array $ingested, int $cascadeAdded, int $criteriaAdded): void
    {
        if (!$this->fileSystem->exists($analysisDir)) {
            $this->fileSystem->createDirectory($analysisDir);
        }
        $reportPath = $analysisDir . '/' . AnalysisReportWriter::REPORT_FILE;
        $jsonPath = $analysisDir . '/' . AnalysisReportWriter::JSON_FILE;

        // --- Markdown: заменяем ТОЛЬКО секцию кода, секция данных сохраняется ---
        $existing = $this->fileSystem->exists($reportPath) ? $this->fileSystem->read($reportPath) : '';
        $body = $this->renderCodeSection($ingested, $cascadeAdded, $criteriaAdded);
        $report = MarkdownReport::upsertSection($existing, MarkdownReport::SECTION_CODE, $body);
        $this->fileSystem->write($reportPath, $report);

        // --- JSON: пишем code_analysis, сохраняя секцию данных (tables/generated_at) ---
        $result = [];
        if ($this->fileSystem->exists($jsonPath)) {
            $decoded = json_decode($this->fileSystem->read($jsonPath), true);
            if (is_array($decoded)) {
                $result = $decoded;
            }
        }
        $result['code_analysis'] = [
            'generated_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'cascade_added' => $cascadeAdded,
            'criteria_added' => $criteriaAdded,
            'cascade_from' => $ingested['cascade_from'] ?? [],
            'sample_criteria' => $ingested['sample_criteria'] ?? [],
        ];
        $flags = JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR;
        $json = json_encode($result, $flags);
        $this->fileSystem->write($jsonPath, $json === false ? '{}' : $json);
    }

    /**
     * Тело секции «Анализ кода (OPENCODE)» (без файлового заголовка — его держит MarkdownReport).
     *
     * @param array<string, mixed> $ingested
     */
    private function renderCodeSection(array $ingested, int $cascadeAdded, int $criteriaAdded): string
    {
        $section = "## Анализ кода (OPENCODE)\n\n";
        $section .= "_Дополнено: " . gmdate('Y-m-d\TH:i:s\Z') . "_\n\n";
        $section .= "Применено: cascade_from (source: code) — {$cascadeAdded}; sample.criteria — {$criteriaAdded}.\n\n";

        $cascade = $ingested['cascade_from'] ?? [];
        if (is_array($cascade) && !empty($cascade)) {
            $section .= "### Связи из кода (cascade_from)\n\n";
            $section .= "| таблица | parent | fk_column | parent_column | kind | confidence |\n";
            $section .= "|---------|--------|-----------|---------------|------|-----------|\n";
            foreach ($cascade as $c) {
                if (!is_array($c)) {
                    continue;
                }
                $section .= sprintf(
                    "| %s.%s | %s | %s | %s | %s | %s |\n",
                    $c['schema'] ?? '',
                    $c['table'] ?? '',
                    $c['parent'] ?? '',
                    $c['fk_column'] ?? '',
                    $c['parent_column'] ?? '',
                    $c['kind'] ?? '',
                    $c['confidence'] ?? ''
                );
            }
            $section .= "\n";
        }

        $criteria = $ingested['sample_criteria'] ?? [];
        if (is_array($criteria) && !empty($criteria)) {
            $section .= "### Критерии из кода (sample.criteria)\n\n";
            $section .= "| таблица | name | limit | confidence | where |\n";
            $section .= "|---------|------|-------|-----------|-------|\n";
            foreach ($criteria as $c) {
                if (!is_array($c)) {
                    continue;
                }
                $where = str_replace('|', '\\|', (string) ($c['where'] ?? ''));
                $section .= sprintf(
                    "| %s.%s | %s | %s | %s | `%s` |\n",
                    $c['schema'] ?? '',
                    $c['table'] ?? '',
                    $c['name'] ?? '',
                    $c['limit'] ?? '',
                    $c['confidence'] ?? '',
                    $where
                );
            }
            $section .= "\n";
        }

        return $section;
    }
}
