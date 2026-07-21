<?php

namespace Timbrs\DatabaseDumps\Service\Analysis;

use Timbrs\DatabaseDumps\Contract\FileSystemInterface;
use Timbrs\DatabaseDumps\Contract\LoggerInterface;

/**
 * Цикл исправления вывода агента OPENCODE после первичного прогона.
 *
 * После того как агент записал database/analysis/out/<schema>.json, валидируем sample.criteria
 * (синтаксис + сверка колонок с инвентарём). Если есть невалидные — формируем КОРРЕКТИРУЮЩИЙ
 * промпт (конкретные битые criteria + причины + реальные колонки) и ПЕРЕЗАПУСКАЕМ агента по этой
 * схеме свежим прогоном (stateless — не зависит от resume/session opencode). Повторяем до
 * $maxAttempts раз, перепроверяя результат. Что не удалось исправить — финальная сетка
 * AnalysisIngestor отбросит на применении (в dump_config.yaml мусор не попадёт).
 *
 * Экспорт-модель дампера: SELECT <pk> FROM schema.table WHERE (base) AND (<sql_where>) — без
 * алиасов/JOIN/параметров, поэтому WHERE из ORM/DQL напрямую непригоден (см. CriteriaValidator).
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

    /** @var LoggerInterface */
    private $logger;

    /** @var string */
    private $projectDir;

    /** @var CriteriaValidator */
    private $validator;

    public function __construct(
        OpencodeRunner $runner,
        FileSystemInterface $fileSystem,
        LoggerInterface $logger,
        string $projectDir
    ) {
        $this->runner = $runner;
        $this->fileSystem = $fileSystem;
        $this->logger = $logger;
        $this->projectDir = rtrim($projectDir, '/\\');
        $this->validator = new CriteriaValidator();
    }

    /**
     * Прогнать цикл исправления по всем схемам.
     *
     * @param array<string, string> $schemaFiles schema => абсолютный путь пер-схемного инвентаря
     * @param int                   $maxAttempts  максимум корректирующих перепрогонов на схему
     */
    public function run(string $dataDir, array $schemaFiles, int $maxAttempts = self::DEFAULT_MAX_ATTEMPTS): void
    {
        if ($maxAttempts < 1) {
            return;
        }

        foreach ($schemaFiles as $schema => $inventoryAbs) {
            $schema = (string) $schema;
            $this->repairSchema($schema, (string) $inventoryAbs, $dataDir, $maxAttempts);
        }
    }

    private function repairSchema(string $schema, string $inventoryAbs, string $dataDir, int $maxAttempts): void
    {
        $outAbs = $this->projectDir . '/' . $dataDir . '/' . AnalysisPackageBuilder::OUT_DIR . '/' . $schema . '.json';
        $relInventory = $dataDir . '/' . AnalysisPackageBuilder::ANALYSIS_DIR . '/schema_inventory.' . $schema . '.json';

        if (!$this->fileSystem->exists($outAbs)) {
            // Первичный прогон не создал файл — исправлять нечего (ошибка уже залогирована выше).
            return;
        }

        $columns = $this->loadColumns($inventoryAbs);

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $problems = $this->validateOutFile($outAbs, $columns);
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
        $left = $this->validateOutFile($outAbs, $columns);
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
     * Проверить criteria в out/<schema>.json. Возвращает список проблем по критериям.
     *
     * @param array<string, array<int, string>> $columns  schema.table => [колонки]
     * @return array<int, array{table: string, name: string, where: string, issues: array<int, string>}>
     */
    private function validateOutFile(string $outAbs, array $columns): array
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
            if ($where === '') {
                continue;
            }

            $issues = $this->validator->problems($where, $columns[$table] ?? []);
            if (!empty($issues)) {
                $problems[] = ['table' => $table, 'name' => $name, 'where' => $where, 'issues' => $issues];
            }
        }

        return $problems;
    }

    /**
     * Корректирующий промпт: перечисляем битые criteria с причинами и реальными колонками,
     * просим переписать ТОЛЬКО их (или удалить неисправимые), сохранив остальное.
     *
     * @param array<int, array{table: string, name: string, where: string, issues: array<int, string>}> $problems
     * @param array<string, array<int, string>> $columns
     */
    private function buildCorrectivePrompt(string $schema, array $problems, array $columns): string
    {
        $lines = [];
        $lines[] = sprintf(
            'В файле database/analysis/out/%s.json часть sql_where НЕВАЛИДНА для дампера. Он выполняет '
            . 'ОДНОТАБЛИЧНЫЙ запрос: SELECT <pk> FROM schema.table WHERE (base) AND (<sql_where>) — без алиасов '
            . 'таблицы, без JOIN и без bind-параметров. Исправь ТОЛЬКО перечисленные ниже criteria и перезапиши '
            . 'файл через write (остальное содержимое сохрани как есть):',
            $schema
        );

        foreach ($problems as $p) {
            $cols = isset($columns[$p['table']]) ? $columns[$p['table']] : [];
            $colHint = empty($cols)
                ? ''
                : ' Колонки ' . $p['table'] . ': ' . implode(', ', array_slice($cols, 0, self::MAX_PROMPT_COLUMNS))
                    . (count($cols) > self::MAX_PROMPT_COLUMNS ? ', …' : '') . '.';
            $lines[] = sprintf(
                "- %s / '%s': %s.%s Текущий (битый) WHERE: %s",
                $p['table'],
                $p['name'],
                implode('; ', $p['issues']),
                $colHint,
                $p['where']
            );
        }

        $lines[] = 'Правила: без алиасов (t1./t2./u.), без bind-параметров (:name/?), только реальные имена '
            . 'колонок из списков выше (обычно snake_case) и литеральные значения (NOW()/CURRENT_TIMESTAMP для '
            . '«сейчас», enum-статус строкой или числом). Если сегмент нельзя выразить статическим SQL '
            . '(нужен id/логин текущего пользователя, список id из рантайма) — УДАЛИ его из массива criteria.';

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
     * @param array<int, array{table: string, name: string, where: string, issues: array<int, string>}> $problems
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
