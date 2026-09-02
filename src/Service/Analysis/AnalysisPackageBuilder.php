<?php

namespace Timbrs\DatabaseDumps\Service\Analysis;

use Timbrs\DatabaseDumps\Contract\ConnectionRegistryInterface;
use Timbrs\DatabaseDumps\Contract\FileSystemInterface;
use Timbrs\DatabaseDumps\Contract\LoggerInterface;
use Timbrs\DatabaseDumps\Platform\PlatformFactory;
use Timbrs\DatabaseDumps\Service\Ai\DbdumpConfigStore;
use Timbrs\DatabaseDumps\Service\ConfigGenerator\ColumnProfile;
use Timbrs\DatabaseDumps\Service\ConfigGenerator\ColumnStatisticsInspector;
use Timbrs\DatabaseDumps\Service\ConfigGenerator\ServiceTableFilter;
use Timbrs\DatabaseDumps\Service\ConfigGenerator\TableInspector;
use Timbrs\DatabaseDumps\Service\Graph\TableDependencyResolver;

/**
 * Готовит пакет для анализа кода хоста агентом OPENCODE (dbdump-mapper):
 *  - .opencode/agents/dbdump-mapper.md      — готовый агент (read-only код + запись в out/)
 *  - .opencode/commands/dbdump-map.md       — слэш-команда (опционально)
 *  - {data_dir}/analysis/schema_inventory.json — вход (схемы/таблицы/колонки/FK/профили, БЕЗ значений данных)
 *  - {data_dir}/analysis/output_schema.json    — JSON-контракт вывода
 *  - {data_dir}/analysis/RUN.md                — инструкции запуска
 *  - {data_dir}/analysis/out/                — каталог для результатов агента
 *
 * Сам прогон OPENCODE пользователь запускает вручную по RUN.md; модуль его не вызывает.
 * Sample-значения PII в инвентарь НЕ кладутся (только типы/кардинальность).
 */
class AnalysisPackageBuilder
{
    /** Суффиксы каталогов анализа относительно data_dir (полный путь: {data_dir}/analysis). */
    public const ANALYSIS_DIR = 'analysis';
    public const OUT_DIR = 'analysis/out';
    public const OPENCODE_AGENTS_DIR = '.opencode/agents';
    public const OPENCODE_COMMANDS_DIR = '.opencode/commands';

    /** @var FileSystemInterface */
    private $fileSystem;

    /** @var ConnectionRegistryInterface */
    private $registry;

    /** @var TableInspector */
    private $inspector;

    /** @var ServiceTableFilter */
    private $filter;

    /** @var TableDependencyResolver */
    private $dependencyResolver;

    /** @var ColumnStatisticsInspector */
    private $statisticsInspector;

    /** @var LoggerInterface */
    private $logger;

    /** @var string */
    private $projectDir;

    /** @var CodeHintScanner */
    private $codeHintScanner;

    /** @var DbdumpConfigStore|null */
    private $configStore;

    public function __construct(
        FileSystemInterface $fileSystem,
        ConnectionRegistryInterface $registry,
        TableInspector $inspector,
        ServiceTableFilter $filter,
        TableDependencyResolver $dependencyResolver,
        ColumnStatisticsInspector $statisticsInspector,
        LoggerInterface $logger,
        string $projectDir,
        CodeHintScanner $codeHintScanner,
        DbdumpConfigStore $configStore = null
    ) {
        $this->fileSystem = $fileSystem;
        $this->registry = $registry;
        $this->inspector = $inspector;
        $this->filter = $filter;
        $this->dependencyResolver = $dependencyResolver;
        $this->statisticsInspector = $statisticsInspector;
        $this->logger = $logger;
        $this->projectDir = rtrim($projectDir, '/\\');
        $this->codeHintScanner = $codeHintScanner;
        $this->configStore = $configStore;
    }

    /**
     * Базовый каталог данных (относительный): из store, иначе DbdumpConfigStore::DEFAULT_DATA_DIR.
     */
    private function dataDir(): string
    {
        return $this->configStore !== null
            ? $this->configStore->getDataDir($this->projectDir)
            : DbdumpConfigStore::DEFAULT_DATA_DIR;
    }

    /**
     * Сформировать пакет. Возвращает список записанных путей и карту пер-схемных
     * инвентарей (для запуска OPENCODE по чанку на схему).
     *
     * @return array{paths: array<int, string>, tables: int, schema_files: array<string, string>}
     */
    public function build(?string $connectionName = null): array
    {
        $dataDir = $this->dataDir();
        $analysisDir = $this->projectDir . '/' . $dataDir . '/' . self::ANALYSIS_DIR;
        $outDir = $this->projectDir . '/' . $dataDir . '/' . self::OUT_DIR;
        $agentsDir = $this->projectDir . '/' . self::OPENCODE_AGENTS_DIR;
        $commandsDir = $this->projectDir . '/' . self::OPENCODE_COMMANDS_DIR;

        foreach ([$analysisDir, $outDir, $agentsDir, $commandsDir] as $dir) {
            $this->ensureDir($dir);
        }

        $inventory = $this->buildInventory($connectionName);
        $tableCount = $this->countTables($inventory);

        // Скан кода хоста: стартовые точки для агента OPENCODE (grep использований таблиц).
        // Мутирует $inventory (ключ code_hints по таблицам) ДО json_encode — попадает и в
        // монолитный, и в пер-схемные инвентари. Новых обращений к БД нет — вход из инвентаря.
        $this->injectCodeHints($inventory, $dataDir);

        $paths = [];

        $jsonFlags = JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

        // Полный инвентарь (обзор / маленькие БД).
        $inventoryJson = json_encode($inventory, $jsonFlags);
        $invPath = $analysisDir . '/schema_inventory.json';
        $this->fileSystem->write($invPath, $inventoryJson === false ? '{}' : $inventoryJson);
        $paths[] = $invPath;

        // Пер-схемный инвентарь — чтобы прогонять OPENCODE по чанку на схему и не
        // переполнять контекст 128k на больших БД (агент получает -f только своей схемы).
        $schemaFiles = [];
        $schemas = isset($inventory['schemas']) && is_array($inventory['schemas']) ? $inventory['schemas'] : [];
        foreach ($schemas as $schemaName => $schemaData) {
            $schemaName = (string) $schemaName;
            // Защита от path traversal: имя схемы из БД используется как часть имени файла.
            // Unicode-буквы разрешены (кириллица), но разделители/точки — нет.
            if (!preg_match('/^[\p{L}_][\p{L}\p{N}_$]*$/u', $schemaName)) {
                $this->logger->warning("Пропущен пер-схемный инвентарь: небезопасное имя схемы '{$schemaName}'");
                continue;
            }
            $schemaInventory = $inventory;
            $schemaInventory['schemas'] = [$schemaName => $schemaData];
            $schemaJson = json_encode($schemaInventory, $jsonFlags);
            $p = $analysisDir . '/schema_inventory.' . $schemaName . '.json';
            $this->fileSystem->write($p, $schemaJson === false ? '{}' : $schemaJson);
            $paths[] = $p;
            $schemaFiles[$schemaName] = $p;
        }

        $contract = $this->renderResource('output_schema.json', $dataDir);
        if ($contract !== null) {
            $p = $analysisDir . '/output_schema.json';
            $this->fileSystem->write($p, $contract);
            $paths[] = $p;
        }

        $agent = $this->renderResource('dbdump-mapper.md', $dataDir);
        if ($agent !== null) {
            $p = $agentsDir . '/dbdump-mapper.md';
            $this->fileSystem->write($p, $agent);
            $paths[] = $p;
        }

        $command = $this->renderResource('dbdump-map.command.md', $dataDir);
        if ($command !== null) {
            $p = $commandsDir . '/dbdump-map.md';
            $this->fileSystem->write($p, $command);
            $paths[] = $p;
        }

        $run = $this->renderResource('RUN.md', $dataDir);
        if ($run !== null) {
            $p = $analysisDir . '/RUN.md';
            $this->fileSystem->write($p, $run);
            $paths[] = $p;
        }

        // .gitkeep, чтобы каталог out/ существовал в репозитории до прогона агента.
        $this->fileSystem->write($outDir . '/.gitkeep', '');

        $this->logger->info(sprintf('Пакет анализа подготовлен: %d таблиц, %d файлов', $tableCount, count($paths)));

        return ['paths' => $paths, 'tables' => $tableCount, 'schema_files' => $schemaFiles];
    }

    /**
     * Построить инвентарь схемы (БЕЗ значений данных).
     *
     * @return array<string, mixed>
     */
    public function buildInventory(?string $connectionName = null): array
    {
        $connection = $this->registry->getConnection($connectionName);
        $platform = PlatformFactory::canonicalize($connection->getPlatformName());

        $graph = $this->dependencyResolver->getDependencyGraph($connectionName);
        $tables = $this->inspector->listTables($connectionName);

        $total = count($tables);
        $this->logger->info(sprintf(
            'Сбор инвентаря БД: %d таблиц (подсчёт строк + профилирование колонок — на больших БД это долго)',
            $total
        ));
        $current = 0;

        $schemas = [];
        foreach ($tables as $tableInfo) {
            $current++;
            $schema = $tableInfo['table_schema'];
            $table = $tableInfo['table_name'];

            if ($this->filter->shouldIgnore($table)) {
                $this->logger->info("[{$current}/{$total}] {$schema}.{$table} ... SKIP (служебная)");
                continue;
            }

            $this->logger->info("[{$current}/{$total}] {$schema}.{$table} ... инвентаризация");

            $rowCount = $this->inspector->countRows($schema, $table, $connectionName);
            // rowCount → профайлер выбирает дешёвую нативную выборку на больших таблицах.
            $profiles = $this->statisticsInspector->profileTable($schema, $table, $connectionName, $rowCount);

            if (!isset($schemas[$schema])) {
                $schemas[$schema] = ['tables' => []];
            }

            $schemas[$schema]['tables'][$table] = [
                'row_count' => $rowCount,
                'columns' => $this->buildColumns($profiles),
                'foreign_keys' => $this->buildForeignKeys($graph, $schema . '.' . $table),
                'profiles' => $this->buildSafeProfiles($profiles),
            ];
        }

        $this->logInventorySummary($schemas);

        return [
            'generated_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'database_platform' => $platform,
            'connection' => $connectionName ?? 'default',
            'note' => 'Значения данных (PII) не включены — только типы, кардинальность и категориальность.',
            'schemas' => $schemas,
        ];
    }

    /**
     * Итог сбора инвентаря: что именно проанализировано и какие «выводы» для следующих
     * этапов. Категориальные колонки (мало разных значений) — кандидаты на именованные
     * сегменты выборки/enum; внешние ключи — основа каскадов. Значения данных (PII) не
     * читались — только метаданные и кардинальность. Печатается в конце фазы, перед
     * сканом кода хоста (grep).
     *
     * @param array<string, mixed> $schemas
     */
    private function logInventorySummary(array $schemas): void
    {
        $tables = 0;
        $columns = 0;
        $categorical = 0;
        $foreignKeys = 0;
        foreach ($schemas as $schemaData) {
            if (!isset($schemaData['tables']) || !is_array($schemaData['tables'])) {
                continue;
            }
            foreach ($schemaData['tables'] as $tableData) {
                $tables++;
                if (isset($tableData['columns']) && is_array($tableData['columns'])) {
                    $columns += count($tableData['columns']);
                }
                if (isset($tableData['profiles']) && is_array($tableData['profiles'])) {
                    foreach ($tableData['profiles'] as $prof) {
                        if (!empty($prof['categorical'])) {
                            $categorical++;
                        }
                    }
                }
                if (isset($tableData['foreign_keys']) && is_array($tableData['foreign_keys'])) {
                    $foreignKeys += count($tableData['foreign_keys']);
                }
            }
        }

        $this->logger->info(sprintf(
            'Инвентарь собран: %d таблиц, %d колонок; из них %d категориальных (кандидаты на сегменты выборки) '
            . 'и %d внешних ключей (каскады). Значения данных (PII) не читались.',
            $tables,
            $columns,
            $categorical,
            $foreignKeys
        ));
    }

    /**
     * @param array<int, ColumnProfile> $profiles
     * @return array<int, array{name: string, type: string, nullable: bool}>
     */
    private function buildColumns(array $profiles): array
    {
        $columns = [];
        foreach ($profiles as $p) {
            $columns[] = [
                'name' => $p->getColumn(),
                'type' => $p->getDataType(),
                'nullable' => $p->isNullable(),
            ];
        }
        return $columns;
    }

    /**
     * Безопасные ключи профиля — только метаданные (тип/кардинальность/категориальность),
     * БЕЗ значений данных. КЛЮЧЕВАЯ ГАРАНТИЯ: PII (top_values и любые иные сырые значения)
     * НЕ уходят во внешний агент OPENCODE.
     *
     * Используется whitelist (а не unset top_values): даже если ColumnProfile::toArray()
     * в будущем начнёт отдавать новые value-несущие ключи, они не утекут в инвентарь.
     */
    private const SAFE_PROFILE_KEYS = [
        'column',
        'data_type',
        'nullable',
        'null_fraction',
        'distinct_count',
        'distinct_capped',
        'categorical',
    ];

    /**
     * Профили только из безопасных метаданных (whitelist). Значения данных (top_values
     * и любые прочие) НЕ выгружаются.
     *
     * @param array<int, ColumnProfile> $profiles
     * @return array<int, array<string, mixed>>
     */
    private function buildSafeProfiles(array $profiles): array
    {
        $result = [];
        foreach ($profiles as $p) {
            $arr = $p->toArray();
            $safe = [];
            foreach (self::SAFE_PROFILE_KEYS as $key) {
                if (array_key_exists($key, $arr)) {
                    $safe[$key] = $arr[$key];
                }
            }
            $result[] = $safe;
        }
        return $result;
    }

    /**
     * @param array<string, array<string, array{source_column: string, target_column: string}>> $graph
     * @return array<int, array{column: string, references_table: string, references_column: string}>
     */
    private function buildForeignKeys(array $graph, string $tableKey): array
    {
        if (!isset($graph[$tableKey])) {
            return [];
        }
        $fks = [];
        foreach ($graph[$tableKey] as $parentKey => $columns) {
            $fks[] = [
                'column' => $columns['source_column'],
                'references_table' => $parentKey,
                'references_column' => $columns['target_column'],
            ];
        }
        return $fks;
    }

    /**
     * Порядок категорий в итоговой сводке подсказок по коду — паритет с
     * CodeHintScanner::summarize() ($SUMMARY_ORDER).
     *
     * @var array<int, string>
     */
    private const CODE_HINT_SUMMARY_ORDER = [
        'model', 'model usage', 'entity', 'entity usage', 'repository', 'repository usage', 'sql',
    ];

    /**
     * Прогнать CodeHintScanner по собранному инвентарю и вложить его результат в каждую
     * таблицу ключом `code_hints` (стартовые точки для агента OPENCODE: file/line упоминаний +
     * предварительные relationships/criteria/columns). Данные для запуска берутся из инвентаря
     * 1:1 — новых обращений к БД нет. Значения данных БД в подсказки не попадают (только код хоста).
     *
     * @param array<string, mixed> $inventory  мутируется по ссылке (ключ code_hints по таблицам)
     * @param string               $dataDir    относительный data_dir (исключается из обхода сканером)
     */
    private function injectCodeHints(array &$inventory, string $dataDir): void
    {
        $schemas = isset($inventory['schemas']) && is_array($inventory['schemas']) ? $inventory['schemas'] : [];
        if (empty($schemas)) {
            return;
        }

        // Входы сканера собираем из инвентаря (совпадают 1:1 по форме).
        $tableKeys = [];
        $tableColumns = [];
        $dbForeignKeys = [];
        $keyMap = []; // schema.table → [schema, table] (обратный маппинг без explode по точке)
        foreach ($schemas as $schemaName => $schemaData) {
            if (!isset($schemaData['tables']) || !is_array($schemaData['tables'])) {
                continue;
            }
            foreach ($schemaData['tables'] as $tableName => $tableData) {
                $key = $schemaName . '.' . $tableName;
                $tableKeys[] = $key;
                $keyMap[$key] = [(string) $schemaName, (string) $tableName];
                $tableColumns[$key] = isset($tableData['columns']) && is_array($tableData['columns'])
                    ? array_column($tableData['columns'], 'name')
                    : [];
                $dbForeignKeys[$key] = isset($tableData['foreign_keys']) && is_array($tableData['foreign_keys'])
                    ? $tableData['foreign_keys']
                    : [];
            }
        }

        if (empty($tableKeys)) {
            return;
        }

        // Заголовок фазы: возможно долгий двухпроходный grep по всему коду хоста.
        $this->logger->info('Скан кода хоста по таблицам (grep использований)…');

        $hints = $this->codeHintScanner->scan($tableKeys, $dataDir, $tableColumns, $dbForeignKeys);

        // Аккумулятор итога по категориям + вывод счётчиков по таблицам с хитами.
        $catTotals = [];
        $tablesWithHints = 0;
        $ambiguousTables = 0;
        foreach ($hints as $key => $entry) {
            if (!isset($keyMap[$key])) {
                continue;
            }
            [$schemaName, $tableName] = $keyMap[$key];
            $inventory['schemas'][$schemaName]['tables'][$tableName]['code_hints'] = $entry;
            $tablesWithHints++;

            $summary = isset($entry['summary']) ? (string) $entry['summary'] : '';
            $suffix = !empty($entry['truncated']) ? ' <comment>(усечено)</comment>' : '';
            if (!empty($entry['ambiguous'])) {
                $ambiguousTables++;
                $schemas = $this->ambiguousSchemas(isset($entry['ambiguous_with']) ? $entry['ambiguous_with'] : []);
                $suffix .= sprintf(' <comment>(неоднозначно: %s)</comment>', $schemas);
            }
            $this->logger->info(sprintf('  <info>%s</info>: %s%s', $key, $summary, $suffix));

            if (isset($entry['counts']) && is_array($entry['counts'])) {
                foreach ($entry['counts'] as $cat => $n) {
                    $catTotals[(string) $cat] = (isset($catTotals[(string) $cat]) ? $catTotals[(string) $cat] : 0) + (int) $n;
                }
            }
        }

        if ($tablesWithHints > 0) {
            $tail = $ambiguousTables > 0 ? sprintf('; неоднозначных: %d', $ambiguousTables) : '';
            $this->logger->info(sprintf(
                'Подсказки по коду: %d таблиц с упоминаниями (%s)%s',
                $tablesWithHints,
                $this->summarizeCodeHintTotals($catTotals),
                $tail
            ));
        } else {
            $this->logger->info('Подсказки по коду: упоминаний таблиц в коде хоста не найдено');
        }
    }

    /**
     * Список схем коллизии для консоли: схема каждого ключа ambiguous_with (часть ДО последней
     * точки), уникально и по порядку. Напр. ['clients.phones','user.phones'] → 'clients, user'.
     *
     * @param array<int, string> $ambiguousWith
     */
    private function ambiguousSchemas(array $ambiguousWith): string
    {
        $schemas = [];
        foreach ($ambiguousWith as $k) {
            $k = (string) $k;
            $pos = strrpos($k, '.');
            $schema = $pos === false ? $k : substr($k, 0, $pos);
            if ($schema !== '' && !in_array($schema, $schemas, true)) {
                $schemas[] = $schema;
            }
        }
        return implode(', ', $schemas);
    }

    /**
     * Свести итоговые счётчики в строку «X model, Y entity, …» в порядке summarize().
     *
     * @param array<string, int> $catTotals
     */
    private function summarizeCodeHintTotals(array $catTotals): string
    {
        $parts = [];
        foreach (self::CODE_HINT_SUMMARY_ORDER as $cat) {
            if (!empty($catTotals[$cat])) {
                $parts[] = $catTotals[$cat] . ' ' . $cat;
            }
        }
        // Неизвестные категории (не в фиксированном порядке) — в конец, чтобы ничего не потерять.
        foreach ($catTotals as $cat => $n) {
            if (!empty($n) && !in_array($cat, self::CODE_HINT_SUMMARY_ORDER, true)) {
                $parts[] = $n . ' ' . $cat;
            }
        }
        return implode(', ', $parts);
    }

    /**
     * @param array<string, mixed> $inventory
     */
    private function countTables(array $inventory): int
    {
        $count = 0;
        if (isset($inventory['schemas']) && is_array($inventory['schemas'])) {
            foreach ($inventory['schemas'] as $schema) {
                if (isset($schema['tables']) && is_array($schema['tables'])) {
                    $count += count($schema['tables']);
                }
            }
        }
        return $count;
    }

    private function ensureDir(string $dir): void
    {
        if (!$this->fileSystem->exists($dir)) {
            $this->fileSystem->createDirectory($dir);
        }
    }

    /**
     * Ресурс с подставленным data_dir. В шаблонах пути к анализу записаны как
     * `{data_dir}/analysis/...`: агенту OPENCODE и человеку нужен реальный путь, а он
     * зависит от настройки, поэтому раскрываем его в момент записи файла в проект.
     *
     * @return string|null
     */
    protected function renderResource(string $name, string $dataDir)
    {
        $content = $this->loadResource($name);
        return $content === null ? null : str_replace('{data_dir}', $dataDir, $content);
    }

    /**
     * @return string|null
     */
    protected function loadResource(string $name)
    {
        $path = dirname(__DIR__, 2) . '/Resources/opencode/' . $name;
        if (!is_file($path)) {
            return null;
        }
        $content = @file_get_contents($path);
        return $content === false ? null : $content;
    }
}
