<?php

namespace Timbrs\DatabaseDumps\Service\Analysis;

use Timbrs\DatabaseDumps\Contract\FileSystemInterface;
use Timbrs\DatabaseDumps\Contract\LoggerInterface;

/**
 * Цикл исправления вывода агента OPENCODE после первичного прогона.
 *
 * После того как агент записал database/analysis/out/<schema>.json, ПРОГОНЯЕМ каждый sample.criterion
 * в БД так же, как это сделает дампер (SELECT 1 FROM schema.table WHERE (<where>) LIMIT 1, см.
 * CriteriaSqlTester). Упавшие + реальную ошибку СУБД отдаём агенту КОРОТКИМ корректирующим промптом
 * и ПЕРЕЗАПУСКАЕМ его свежим прогоном (stateless — не зависит от resume/session opencode). Повторяем
 * до $maxAttempts раз. Что не исправлено — финальная сетка AnalysisIngestor отбросит на применении.
 *
 * Реальный прогон SQL точнее статической эвристики: ловит и алиасы t1./bind-параметры, и
 * несуществующие колонки (camelCase), и синтаксис — с настоящим текстом ошибки для агента.
 */
class AnalysisRepairLoop
{
    /** По умолчанию: до 2 корректирующих перепрогонов на схему. 0 — цикл выключен. */
    public const DEFAULT_MAX_ATTEMPTS = 2;

    /** Потолок на число колонок в подсказке промпту (чтобы не раздувать контекст). */
    private const MAX_PROMPT_COLUMNS = 40;

    /** @var OpencodeRunner */
    private $runner;

    /** @var FileSystemInterface */
    private $fileSystem;

    /** @var CriteriaSqlTester */
    private $sqlTester;

    /** @var LoggerInterface */
    private $logger;

    /** @var string */
    private $projectDir;

    public function __construct(
        OpencodeRunner $runner,
        FileSystemInterface $fileSystem,
        CriteriaSqlTester $sqlTester,
        LoggerInterface $logger,
        string $projectDir
    ) {
        $this->runner = $runner;
        $this->fileSystem = $fileSystem;
        $this->sqlTester = $sqlTester;
        $this->logger = $logger;
        $this->projectDir = rtrim($projectDir, '/\\');
    }

    /**
     * Прогнать цикл исправления по всем схемам.
     *
     * @param array<string, string> $schemaFiles schema => абсолютный путь пер-схемного инвентаря
     * @param int                   $maxAttempts  максимум корректирующих перепрогонов на схему
     * @param string|null           $connectionName подключение, в котором прогонять criteria
     */
    public function run(string $dataDir, array $schemaFiles, int $maxAttempts = self::DEFAULT_MAX_ATTEMPTS, ?string $connectionName = null): void
    {
        if ($maxAttempts < 1) {
            return;
        }

        foreach ($schemaFiles as $schema => $inventoryAbs) {
            $schema = (string) $schema;
            $this->repairSchema($schema, (string) $inventoryAbs, $dataDir, $maxAttempts, $connectionName);
        }
    }

    private function repairSchema(string $schema, string $inventoryAbs, string $dataDir, int $maxAttempts, ?string $connectionName): void
    {
        $outAbs = $this->projectDir . '/' . $dataDir . '/' . AnalysisPackageBuilder::OUT_DIR . '/' . $schema . '.json';
        $relInventory = $dataDir . '/' . AnalysisPackageBuilder::ANALYSIS_DIR . '/schema_inventory.' . $schema . '.json';

        if (!$this->fileSystem->exists($outAbs)) {
            // Первичный прогон не создал файл — исправлять нечего (ошибка уже залогирована выше).
            return;
        }

        $columns = $this->loadColumns($inventoryAbs);

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $problems = $this->validateOutFile($outAbs, $connectionName);
            if (empty($problems)) {
                if ($attempt > 1) {
                    $this->logger->info(sprintf('  <info>%s</info>: criteria исправлены после перепрогона', $schema));
                }
                return;
            }

            $this->logger->info(sprintf(
                '  <comment>%s</comment>: %d невалидных criteria — корректирующий перепрогон OPENCODE (попытка %d/%d)',
                $schema,
                count($problems),
                $attempt,
                $maxAttempts
            ));

            $prompt = $this->buildCorrectivePrompt($schema, $problems, $columns);
            $code = $this->runner->runAgent($this->projectDir, $relInventory, $prompt);
            if ($code !== 0) {
                $this->logger->warning(sprintf('  %s: корректирующий OPENCODE завершился с кодом %d', $schema, $code));
            }
        }

        // Финальная проверка после исчерпания попыток.
        $left = $this->validateOutFile($outAbs, $connectionName);
        if (!empty($left)) {
            $this->logger->warning(sprintf(
                '  %s: осталось %d невалидных criteria после %d попыток — будут отброшены при применении (%s)',
                $schema,
                count($left),
                $maxAttempts,
                $this->summarizeProblemNames($left)
            ));
        }
    }

    /**
     * Прогнать каждый criterion из out/<schema>.json в БД (как дампер). Возвращает список упавших
     * с реальной ошибкой СУБД.
     *
     * @return array<int, array{table: string, name: string, where: string, error: string}>
     */
    private function validateOutFile(string $outAbs, ?string $connectionName): array
    {
        try {
            $raw = $this->fileSystem->read($outAbs);
        } catch (\Throwable $e) {
            return [];
        }

        $data = json_decode($raw, true);
        if (!is_array($data) || !isset($data['criteria']) || !is_array($data['criteria'])) {
            return [];
        }

        $problems = [];
        foreach ($data['criteria'] as $crit) {
            if (!is_array($crit)) {
                continue;
            }
            $table = isset($crit['table']) && is_string($crit['table']) ? $crit['table'] : '';
            $name = isset($crit['name']) && is_string($crit['name']) ? $crit['name'] : '';
            $where = isset($crit['sql_where']) && is_string($crit['sql_where']) ? $crit['sql_where'] : '';
            if ($where === '' || strpos($table, '.') === false) {
                continue;
            }
            list($schema, $tableName) = explode('.', $table, 2);

            $error = $this->sqlTester->test($schema, $tableName, $where, $connectionName);
            if ($error !== null) {
                $problems[] = ['table' => $table, 'name' => $name, 'where' => $where, 'error' => $error];
            }
        }

        return $problems;
    }

    /**
     * Короткий корректирующий промпт: эти criteria упали в БД (реальная ошибка) — исправь или удали.
     *
     * @param array<int, array{table: string, name: string, where: string, error: string}> $problems
     * @param array<string, array<int, string>> $columns
     */
    private function buildCorrectivePrompt(string $schema, array $problems, array $columns): string
    {
        $lines = [];
        $lines[] = sprintf(
            'Эти criteria в database/analysis/out/%s.json падают в БД (дампер выполняет SELECT ... FROM '
            . 'таблица WHERE (sql_where) — без алиасов, JOIN и bind-параметров). Сначала read этого файла, '
            . 'исправь ТОЛЬКО их и перезапиши write (остальное сохрани):',
            $schema
        );

        foreach ($problems as $p) {
            $cols = isset($columns[$p['table']]) ? $columns[$p['table']] : [];
            $colHint = empty($cols)
                ? ''
                : ' Колонки: ' . implode(', ', array_slice($cols, 0, self::MAX_PROMPT_COLUMNS))
                    . (count($cols) > self::MAX_PROMPT_COLUMNS ? ', …' : '') . '.';
            $lines[] = sprintf("- %s/'%s': %s%s", $p['table'], $p['name'], $p['error'], $colHint);
        }

        $lines[] = 'Используй реальные имена колонок и литералы (NOW() для «сейчас», enum строкой/числом). '
            . 'Если нужен рантайм-параметр (id/логин текущего пользователя) — просто УДАЛИ criterion.';

        return implode("\n", $lines);
    }

    /**
     * Прочитать карту колонок из пер-схемного инвентаря: schema.table => [имена колонок].
     *
     * @return array<string, array<int, string>>
     */
    private function loadColumns(string $inventoryAbs): array
    {
        if (!$this->fileSystem->exists($inventoryAbs)) {
            return [];
        }
        try {
            $raw = $this->fileSystem->read($inventoryAbs);
        } catch (\Throwable $e) {
            return [];
        }

        $data = json_decode($raw, true);
        if (!is_array($data) || !isset($data['schemas']) || !is_array($data['schemas'])) {
            return [];
        }

        $map = [];
        foreach ($data['schemas'] as $schemaName => $schemaData) {
            if (!is_array($schemaData) || !isset($schemaData['tables']) || !is_array($schemaData['tables'])) {
                continue;
            }
            foreach ($schemaData['tables'] as $tableName => $tableData) {
                if (!is_array($tableData) || !isset($tableData['columns']) || !is_array($tableData['columns'])) {
                    continue;
                }
                $names = [];
                foreach ($tableData['columns'] as $col) {
                    if (is_array($col) && isset($col['name']) && is_string($col['name'])) {
                        $names[] = $col['name'];
                    }
                }
                $map[$schemaName . '.' . $tableName] = $names;
            }
        }

        return $map;
    }

    /**
     * Свести имена оставшихся битых критериев в строку для лога.
     *
     * @param array<int, array{table: string, name: string, where: string, error: string}> $problems
     */
    private function summarizeProblemNames(array $problems): string
    {
        $names = [];
        foreach ($problems as $p) {
            $names[] = $p['table'] . "/'" . $p['name'] . "'";
        }
        return implode(', ', $names);
    }
}
