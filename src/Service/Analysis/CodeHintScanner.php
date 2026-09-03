<?php

namespace Timbrs\DatabaseDumps\Service\Analysis;

use Symfony\Component\Finder\Finder;
use Timbrs\DatabaseDumps\Contract\LoggerInterface;

/**
 * Дешёвый grep-сканер исходников хост-проекта: собирает «стартовые точки» для внешнего
 * агента OPENCODE на этапе инвентаризации prepare-analysis.
 *
 * Для каждой таблицы БД ищет её упоминания в коде (.php/.yaml/.sql) и категоризирует найденное:
 *   entity / model / repository / sql / entity usage / model usage / repository usage.
 * Возвращает по таблице счётчики + до N сниппетов (путь + строка + контекст ±2 строки).
 *
 * Два прохода:
 *   1) упоминания имён таблиц (+ сбор class-mapping: Doctrine #[ORM\Table] → entity,
 *      repositoryClass:/конвенция {Entity}Repository → repository, Eloquent $table → model);
 *   2) использование обнаруженных классов (даёт счётчик «entity/model/repository usage»).
 *
 * Никаких значений данных БД не читается — только исходный код хоста. Enumerate/read вынесены
 * в protected-методы для подмены в юнит-тестах.
 *
 * PHP 7.2-совместимо (без typed properties / arrow fn / union types).
 */
class CodeHintScanner
{
    use CodeHints\TextHelperTrait;

    /** Максимум сниппетов в одной категории у таблицы (счётчики при этом полные). */
    const MAX_PER_CATEGORY = 10;

    /** Максимум сниппетов суммарно на таблицу (счётчики при этом полные). */
    const MAX_PER_TABLE = 30;

    /** Лимиты «точных» секций (при truncated НЕ сворачиваются). */
    const MAX_RELATIONSHIPS = 50;
    const MAX_CRITERIA = 20;
    const MAX_COLUMNS = 40;

    /** Суммарно хитов по таблице больше порога → truncated (детальные sql/usage не вкладываем). */
    const GENERIC_HIT_THRESHOLD = 150;

    /** Слишком короткое имя таблицы = вероятный шум → truncated. */
    const MIN_TABLE_NAME_LEN = 4;

    /** Не читаем файлы больше этого размера (крупные сгенерированные дампы/бандлы). */
    const MAX_FILE_SIZE = '< 512K';

    /**
     * Точные определения — их сниппеты остаются даже при truncated.
     *
     * @var array<int, string>
     */
    private static $DEF_CATEGORIES = ['entity', 'model', 'repository'];

    /**
     * Порядок укладки сниппетов при усечении до MAX_PER_TABLE: defs → sql → usage.
     *
     * @var array<int, string>
     */
    private static $SNIPPET_ORDER = ['entity', 'model', 'repository', 'sql', 'entity usage', 'model usage', 'repository usage'];

    /**
     * Порядок категорий в текстовой сводке.
     *
     * @var array<int, string>
     */
    private static $SUMMARY_ORDER = ['model', 'model usage', 'entity', 'entity usage', 'repository', 'repository usage', 'sql'];

    /**
     * Каталоги, которые не сканируем никогда.
     *
     * @var array<int, string>
     */
    private static $EXCLUDE_DIRS = ['vendor', 'node_modules', '.git', 'var', 'storage', 'public/build', '.opencode'];

    /** Сколько строк контекста добавлять сверху и снизу от совпадения. */
    const CONTEXT_LINES = 2;

    /** Максимальная длина одной строки сниппета (символов). */
    const MAX_SNIPPET_LINE = 200;

    /** @var string */
    private $projectDir;

    /** @var LoggerInterface */
    private $logger;

    /** @var array<string, array<string, int>> counts[schema.table][category] = int */
    private $counts;

    /** @var array<string, array<string, array<int, array<string, mixed>>>> buckets[schema.table][category] = hint[] */
    private $buckets;

    /**
     * classMap[classShortName] = ['keys' => string[], 'kind' => 'entity'|'model', 'def' => string(rel)]
     *
     * @var array<string, array<string, mixed>>
     */
    private $classMap;

    /** @var array<string, string> classShortName → относительный путь файла-определения */
    private $classDefFiles;

    /** @var array<string, array<int, array<string, mixed>>> relationships[schema.table] = candidate[] */
    private $relationships;

    /** @var array<string, array<int, array<string, mixed>>> criteria[schema.table] = candidate[] */
    private $criteria;

    /** @var array<string, array<string, array<string, mixed>>> columns[schema.table][col] = data */
    private $columns;

    /** @var array<string, array<string, bool>> tableFiles[schema.table][rel] = true */
    private $tableFiles;

    /** @var array<string, array{content: string, lines: array<int, string>}> кэш содержимого релевантных файлов */
    private $contentCache;

    /** @var array<string, array<int, string>> enumValues[enumFqcn|primaryClass] = values[] */
    private $enumValues;

    /** @var array<string, array<string, string>> enumCases[enumFqcn] = [CASE_NAME => value] */
    private $enumCases;

    /** @var array<string, string> enumBacking[enumFqcn] = 'int'|'string' */
    private $enumBacking;

    /** @var array<string, array<int, string>> shortName => FQCN[] (одноимённые enum'ы разных доменов) */
    private $enumByShortName;

    /** @var CodeHints\EnumBinder */
    private $enumBinder;

    /** @var array<int, array<string, mixed>> отложенные кандидаты связей: {source_class, cand} */
    private $pendingRel;

    /** @var array<int, array<string, mixed>> отложенные кандидаты сегментов: {source_class, cand} */
    private $pendingCriteria;

    /** @var array<string, string> migrationFiles[rel] = content */
    private $migrationFiles;

    /** @var array<string, array<int, string>> нормализованное имя → ключи schema.table */
    private $normToKeys;

    /** @var array<string, array<string, string>> нормализованное голое имя → (нормализ. схема → полный ключ) */
    private $schemaKeyByBare;

    /** @var array<string, array<int, string>> ключ → полный набор коллизирующих ключей (для ambiguous_with) */
    private $ambiguousInfo;

    /** @var array<string, array<int, string>> tableColumns[schema.table] = имена колонок */
    private $tableColumns;

    /** @var array<string, array<int, array<string, mixed>>> dbForeignKeys[schema.table] = fk[] */
    private $dbForeignKeys;

    public function __construct(string $projectDir, LoggerInterface $logger)
    {
        $this->projectDir = rtrim($projectDir, '/\\');
        $this->logger = $logger;
    }

    /**
     * Просканировать код по списку таблиц.
     *
     * @param array<int, string>                          $tableKeys     список ключей schema.table
     * @param string                                      $dataDir       относительный data_dir
     * @param array<string, array<int, string>>           $tableColumns  schema.table → имена колонок (для грепа колонок)
     * @param array<string, array<int, array<string, mixed>>> $dbForeignKeys schema.table → FK инвентаря (для in_db_fk)
     *
     * @return array<string, array<string, mixed>> только непустые таблицы (0 хитов → нет в карте).
     *         Запись: {summary, counts, categories, truncated} + опц. relationships/criteria/columns.
     */
    public function scan(array $tableKeys, string $dataDir, array $tableColumns = [], array $dbForeignKeys = []): array
    {
        $this->resetState($tableColumns, $dbForeignKeys);

        $bareNames = $this->indexTableKeys($tableKeys);
        if (empty($this->normToKeys)) {
            return [];
        }

        $files = $this->enumerateFiles($dataDir);
        $tableRegex = $this->buildAlternation($bareNames, true);

        // Проход 1 — упоминания таблиц + class-mapping + сырые кандидаты связей/сегментов/enum.
        $pending = $this->firstPass($files, $tableRegex);
        // Достроить classMap: def-файлы уже известны (все файлы пройдены).
        $this->buildClassMap($pending);
        // Проход 2 — использование обнаруженных классов (entity/model/repository usage).
        $this->secondPass($files);

        // Резолв отложенных кандидатов + миграции + использование колонок.
        $this->resolveRelationships();
        $this->resolveMigrations();
        $this->resolveCriteria();
        $this->buildColumnUsage();

        return $this->finalize($tableKeys);
    }

    /**
     * Обнулить накопители перед новым сканом.
     *
     * @param array<string, array<int, string>>                $tableColumns
     * @param array<string, array<int, array<string, mixed>>>  $dbForeignKeys
     */
    private function resetState(array $tableColumns, array $dbForeignKeys): void
    {
        $this->counts = [];
        $this->buckets = [];
        $this->classMap = [];
        $this->classDefFiles = [];
        $this->relationships = [];
        $this->criteria = [];
        $this->columns = [];
        $this->tableFiles = [];
        $this->contentCache = [];
        $this->enumValues = [];
        $this->enumCases = [];
        $this->enumBacking = [];
        $this->enumByShortName = [];
        $this->enumBinder = new CodeHints\EnumBinder();
        $this->pendingRel = [];
        $this->pendingCriteria = [];
        $this->migrationFiles = [];
        $this->normToKeys = [];
        $this->schemaKeyByBare = [];
        $this->ambiguousInfo = [];
        $this->tableColumns = $tableColumns;
        $this->dbForeignKeys = $dbForeignKeys;
    }

    /**
     * Построить $this->normToKeys (нормализованное имя → ключи schema.table) и индекс
     * коллизий $this->schemaKeyByBare (голое имя → схема → полный ключ) для развода дублей.
     *
     * @param array<int, string> $tableKeys
     * @return array<int, string> уникальные «голые» имена таблиц (для alternation-regex)
     */
    private function indexTableKeys(array $tableKeys): array
    {
        $bareNames = [];
        foreach ($tableKeys as $key) {
            $bare = $this->bareName($key);
            if ($bare === '') {
                continue;
            }
            $norm = $this->lower($bare);
            if (!isset($this->normToKeys[$norm])) {
                $this->normToKeys[$norm] = [];
                $bareNames[] = $bare;
            }
            $this->normToKeys[$norm][] = $key;
            // Индекс коллизий: схема (нормализованная) → полный ключ. Коллизия, если ключей > 1.
            $this->schemaKeyByBare[$norm][$this->lower($this->schemaOf($key))] = $key;
        }
        return $bareNames;
    }

    /**
     * Разрешить голое упоминание $normBare в строке кода до ключей schema.table.
     *
     * Неколлизирующее имя → единственный ключ (поведение как раньше). Коллизия (одно голое
     * имя в разных схемах): ищем в строке квалификатор схемы (точечная ссылка `schema.table`
     * или Doctrine-атрибут `schema: 'X'`) среди кандидатных схем. Совпало ≥1 — разводим точно;
     * ни одной — приписываем всем кандидатам с пометкой ambiguous (политика «обеим»).
     *
     * @return array{keys: array<int, string>, ambiguous: bool}
     */
    private function keysForMention(string $normBare, string $line): array
    {
        $keys = isset($this->normToKeys[$normBare]) ? $this->normToKeys[$normBare] : [];
        if (count($keys) <= 1) {
            return ['keys' => $keys, 'ambiguous' => false];
        }
        $matched = [];
        foreach ($this->schemaKeyByBare[$normBare] as $schemaNorm => $key) {
            if ($schemaNorm === '') {
                continue; // ключ без схемы квалифицировать в строке нечем
            }
            if ($this->lineQualifiesSchema($line, $schemaNorm, $normBare)) {
                $matched[] = $key;
            }
        }
        if (!empty($matched)) {
            return ['keys' => array_values(array_unique($matched)), 'ambiguous' => false];
        }
        return ['keys' => $keys, 'ambiguous' => true];
    }

    /**
     * Квалифицирует ли строка кода голое имя таблицы конкретной схемой? Две формы:
     *  1) точечная ссылка `schema.table` (кавычки/бэктики опциональны, граница перед схемой) —
     *     напр. SQL `FROM clients.phones`, Postgres `"user"."phones"`;
     *  2) Doctrine-атрибут `schema: 'X'` / `schema = "X"` со схемой-кандидатом — напр.
     *     `#[ORM\Table(name: 'phones', schema: 'user')]`.
     *
     * $schema/$bare уже нормализованы (нижний регистр); сопоставление — case-insensitive.
     */
    private function lineQualifiesSchema(string $line, string $schema, string $bare): bool
    {
        $s = preg_quote($schema, '/');
        $b = preg_quote($bare, '/');
        $q = '["\'`]?'; // опциональная кавычка/апостроф/бэктик вокруг идентификатора
        // 1) schema.table
        if (preg_match('/(?<![\p{L}\p{N}_])' . $q . $s . $q . '\s*\.\s*' . $q . $b . $q . '(?![\p{L}\p{N}_])/iu', $line) === 1) {
            return true;
        }
        // 2) Doctrine schema: 'X'
        if (preg_match('/\bschema\s*[:=]\s*' . $q . $s . $q . '(?![\p{L}\p{N}_])/iu', $line) === 1) {
            return true;
        }
        return false;
    }

    /**
     * Проход 1: по каждому файлу — class-mapping, детекторы, упоминания имён таблиц.
     *
     * @param array<int, string> $files
     * @param string|null        $tableRegex
     * @return array<int, array<string, mixed>> pending для buildClassMap()
     */
    private function firstPass(array $files, $tableRegex): array
    {
        $relDetector = new CodeHints\RelationshipDetector();
        $critDetector = new CodeHints\CriteriaDetector();

        $pending = [];
        foreach ($files as $absPath) {
            $file = $this->loadFile($absPath);
            if ($file === null) {
                continue;
            }
            $rel = $file['rel'];
            $content = $file['content'];
            $lines = $file['lines'];

            // Реестр «класс → файл-определение» (для пропуска def-файла в проходе 2).
            $primaryClass = $this->primaryClassName($content);
            if ($primaryClass !== null && !isset($this->classDefFiles[$primaryClass])) {
                $this->classDefFiles[$primaryClass] = $rel;
            }

            $this->collectClassMappings($content, $primaryClass, $this->normToKeys, $pending);

            // На уже прочитанном контенте — детекторы (regex дёшев, повторного чтения нет).
            foreach ($relDetector->detect($content, $lines, $rel) as $cand) {
                $this->pendingRel[] = ['source_class' => $primaryClass, 'cand' => $cand];
            }
            $isRepo = strpos($rel, 'Repository') !== false;
            foreach ($critDetector->detect($content, $lines, $rel, $isRepo) as $cand) {
                $this->routeCriteria($primaryClass, $cand);
            }

            if ($this->isMigrationPath($rel)) {
                $this->migrationFiles[$rel] = $content;
            }

            if ($this->collectTableMentions($rel, $lines, $tableRegex)) {
                $this->cacheContent($rel, $content, $lines);
            }
        }
        return $pending;
    }

    /**
     * Упоминания имён таблиц в файле → hint'ы по категориям. true, если было хотя бы одно.
     *
     * @param array<int, string> $lines
     * @param string|null        $tableRegex
     */
    private function collectTableMentions(string $rel, array $lines, $tableRegex): bool
    {
        if ($tableRegex === null) {
            return false;
        }
        $isSqlFile = substr($rel, -4) === '.sql';
        $hadHit = false;
        foreach ($lines as $idx => $line) {
            if (!preg_match_all($tableRegex, $line, $mm)) {
                continue;
            }
            $seen = [];
            foreach ($mm[1] as $matched) {
                $norm = $this->lower($matched);
                if (isset($seen[$norm]) || !isset($this->normToKeys[$norm])) {
                    continue;
                }
                $seen[$norm] = true;
                $category = $this->categorize($line, $rel, $isSqlFile);
                if ($category === null) {
                    continue;
                }
                $resolved = $this->keysForMention($norm, $line);
                $this->addHint($resolved['keys'], $category, $this->makeHint($lines, $idx, $rel));
                if ($resolved['ambiguous']) {
                    foreach ($resolved['keys'] as $ambKey) {
                        $this->ambiguousInfo[$ambKey] = $this->normToKeys[$norm];
                    }
                }
                $hadHit = true;
            }
        }
        return $hadHit;
    }

    /**
     * Проход 2: использование обнаруженных классов (entity/model/repository usage).
     *
     * @param array<int, string> $files
     */
    private function secondPass(array $files): void
    {
        if (empty($this->classMap)) {
            return;
        }
        $classRegex = $this->buildAlternation(array_keys($this->classMap), false);
        if ($classRegex === null) {
            return;
        }
        foreach ($files as $absPath) {
            $file = $this->loadFile($absPath);
            if ($file === null) {
                continue;
            }
            $rel = $file['rel'];
            $hadHit = false;
            foreach ($file['lines'] as $idx => $line) {
                if (!preg_match_all($classRegex, $line, $mm)) {
                    continue;
                }
                $seen = [];
                foreach ($mm[1] as $matched) {
                    if (isset($seen[$matched]) || !isset($this->classMap[$matched])) {
                        continue;
                    }
                    $seen[$matched] = true;
                    $info = $this->classMap[$matched];
                    // Файл-определение класса пропускаем целиком.
                    if ($rel === $info['def']) {
                        continue;
                    }
                    $this->addHint($info['keys'], $info['kind'] . ' usage', $this->makeHint($file['lines'], $idx, $rel));
                    $hadHit = true;
                }
            }
            if ($hadHit) {
                $this->cacheContent($rel, $file['content'], $file['lines']);
            }
        }
    }

    /**
     * Закэшировать содержимое файла для целевого прохода по колонкам (идемпотентно).
     *
     * @param array<int, string> $lines
     */
    private function cacheContent(string $rel, string $content, array $lines): void
    {
        if (!isset($this->contentCache[$rel])) {
            $this->contentCache[$rel] = ['content' => $content, 'lines' => $lines];
        }
    }

    /**
     * Роутинг кандидата CriteriaDetector: enum/const → в карту значений; where-сегменты → отложенно.
     *
     * @param string|null          $primaryClass
     * @param array<string, mixed> $cand
     */
    private function routeCriteria($primaryClass, array $cand): void
    {
        $origin = isset($cand['origin']) ? $cand['origin'] : '';
        if ($origin === 'enum' || $origin === 'const') {
            if (empty($cand['values'])) {
                return;
            }
            // Ключ — FQCN: одноимённые enum'ы разных доменов не должны сливать значения.
            $key = (string) $primaryClass;
            if ($origin === 'enum' && !empty($cand['enum_fqcn'])) {
                $key = (string) $cand['enum_fqcn'];
            } elseif ($origin === 'enum' && !empty($cand['enum_type'])) {
                $key = (string) $cand['enum_type'];
            }
            if ($key === '') {
                return;
            }
            $existing = isset($this->enumValues[$key]) ? $this->enumValues[$key] : [];
            $this->enumValues[$key] = array_values(array_unique(array_merge($existing, $cand['values'])));

            if ($origin === 'enum') {
                if (!empty($cand['cases']) && is_array($cand['cases'])) {
                    $existingCases = isset($this->enumCases[$key]) ? $this->enumCases[$key] : [];
                    $this->enumCases[$key] = array_merge($existingCases, $cand['cases']);
                }
                if (!empty($cand['backing'])) {
                    $this->enumBacking[$key] = (string) $cand['backing'];
                }
                $short = CodeHints\UseStatementResolver::shortName($key);
                if (!isset($this->enumByShortName[$short])) {
                    $this->enumByShortName[$short] = [];
                }
                if (!in_array($key, $this->enumByShortName[$short], true)) {
                    $this->enumByShortName[$short][] = $key;
                }
            }
            return;
        }
        $this->pendingCriteria[] = ['source_class' => $primaryClass, 'cand' => $cand];
    }

    /**
     * Путь миграции? Каталог migrations/ (Doctrine Version*.php или Laravel database/migrations).
     */
    private function isMigrationPath(string $rel): bool
    {
        return preg_match('#(^|/)migrations/#i', $rel) === 1;
    }

    /**
     * Резолв ORM-кандидатов связей: source из classMap текущего файла, target class/литерал → ключи;
     * проставляет in_db_fk сверкой с FK-графом инвентаря.
     */
    private function resolveRelationships(): void
    {
        foreach ($this->pendingRel as $e) {
            $sourceClass = $e['source_class'];
            if ($sourceClass === null || !isset($this->classMap[$sourceClass])) {
                continue;
            }
            $cand = $e['cand'];
            $sourceKeys = $this->classMap[$sourceClass]['keys'];
            $targetKeys = $this->resolveTargetKeys($cand);
            if (empty($targetKeys)) {
                continue;
            }
            foreach ($sourceKeys as $sourceKey) {
                foreach ($targetKeys as $targetKey) {
                    $this->addRelationship($sourceKey, [
                        'source_column' => $cand['source_column'],
                        'target_table'  => $targetKey,
                        'target_column' => $cand['target_column'],
                        'kind'          => $cand['kind'],
                        'origin'        => $cand['origin'],
                        'file'          => $cand['file'],
                        'line'          => $cand['line'],
                    ]);
                }
            }
        }
    }

    /**
     * Ключи target: class → classMap, иначе литеральное имя → normToKeys.
     *
     * @param array<string, mixed> $cand
     * @return array<int, string>
     */
    private function resolveTargetKeys(array $cand): array
    {
        if (!empty($cand['target_class']) && isset($this->classMap[$cand['target_class']])) {
            return $this->classMap[$cand['target_class']]['keys'];
        }
        if (!empty($cand['target_table'])) {
            $raw = (string) $cand['target_table'];
            $norm = $this->lower($this->bareName($raw));
            if (isset($this->normToKeys[$norm])) {
                // Точечный литерал schema.table → развести точно; голый коллизирующий → все кандидаты.
                if (strpos($raw, '.') !== false) {
                    $schemaNorm = $this->lower($this->schemaOf($raw));
                    if (isset($this->schemaKeyByBare[$norm][$schemaNorm])) {
                        return [$this->schemaKeyByBare[$norm][$schemaNorm]];
                    }
                }
                return $this->normToKeys[$norm];
            }
        }
        return [];
    }

    /**
     * Replay миграций → выжившие FK как кандидаты origin: migration (дедуп с ORM, проставление in_db_fk).
     */
    private function resolveMigrations(): void
    {
        if (empty($this->migrationFiles)) {
            return;
        }
        $resolver = new CodeHints\MigrationFkResolver();
        foreach ($resolver->resolve($this->migrationFiles) as $edge) {
            $srcNorm = $this->lower($this->bareName($edge['source_table']));
            $tgtNorm = $this->lower($this->bareName($edge['target_table']));
            if (!isset($this->normToKeys[$srcNorm]) || !isset($this->normToKeys[$tgtNorm])) {
                continue;
            }
            foreach ($this->normToKeys[$srcNorm] as $sourceKey) {
                foreach ($this->normToKeys[$tgtNorm] as $targetKey) {
                    $this->addRelationship($sourceKey, [
                        'source_column' => $edge['source_column'],
                        'target_table'  => $targetKey,
                        'target_column' => $edge['target_column'],
                        'kind'          => $edge['kind'],
                        'origin'        => 'migration',
                        'file'          => $edge['file'],
                        'line'          => $edge['line'],
                    ]);
                }
            }
        }
    }

    /**
     * Добавить кандидата связи (дедуп по source_column|target_table|kind), проставить in_db_fk.
     *
     * @param array<string, mixed> $rel
     */
    private function addRelationship(string $sourceKey, array $rel): void
    {
        if (!isset($this->relationships[$sourceKey])) {
            $this->relationships[$sourceKey] = [];
        }
        $dedup = $rel['source_column'] . '|' . $rel['target_table'] . '|' . $rel['kind'];
        foreach ($this->relationships[$sourceKey] as $existing) {
            $ek = $existing['source_column'] . '|' . $existing['target_table'] . '|' . $existing['kind'];
            if ($ek === $dedup) {
                return;
            }
        }
        // in_db_fk — только когда известна колонка-источник.
        if ($rel['source_column'] !== '') {
            $rel['in_db_fk'] = $this->fkExists($sourceKey, (string) $rel['source_column'], (string) $rel['target_table']);
        }
        $this->relationships[$sourceKey][] = $rel;
    }

    /**
     * Есть ли такой FK в БД (по FK-графу инвентаря).
     */
    private function fkExists(string $sourceKey, string $sourceColumn, string $targetKey): bool
    {
        if (empty($this->dbForeignKeys[$sourceKey])) {
            return false;
        }
        foreach ($this->dbForeignKeys[$sourceKey] as $fk) {
            $col = isset($fk['column']) ? (string) $fk['column'] : '';
            $ref = isset($fk['references_table']) ? (string) $fk['references_table'] : '';
            if ($col === $sourceColumn && $ref === $targetKey) {
                return true;
            }
        }
        return false;
    }

    /**
     * Резолв сегментов: source-таблица из classMap текущего файла.
     */
    private function resolveCriteria(): void
    {
        foreach ($this->pendingCriteria as $e) {
            $sourceClass = $e['source_class'];
            if ($sourceClass === null || !isset($this->classMap[$sourceClass])) {
                continue;
            }
            $cand = $e['cand'];
            foreach ($this->classMap[$sourceClass]['keys'] as $key) {
                $this->addCriterion($key, $cand);
            }
        }
    }

    /**
     * @param array<string, mixed> $cand
     */
    private function addCriterion(string $key, array $cand): void
    {
        if (!isset($this->criteria[$key])) {
            $this->criteria[$key] = [];
        }
        $entry = [
            'name'       => isset($cand['name']) ? $cand['name'] : '',
            'where'      => isset($cand['where']) ? $cand['where'] : '',
            'origin'     => isset($cand['origin']) ? $cand['origin'] : '',
            'confidence' => isset($cand['confidence']) ? $cand['confidence'] : 'low',
            'file'       => isset($cand['file']) ? $cand['file'] : '',
            'line'       => isset($cand['line']) ? $cand['line'] : 0,
        ];
        if (isset($cand['snippet']) && $cand['snippet'] !== '') {
            $entry['snippet'] = $cand['snippet'];
        }
        $dedup = $entry['name'] . '|' . $entry['where'];
        foreach ($this->criteria[$key] as $existing) {
            if (($existing['name'] . '|' . $existing['where']) === $dedup) {
                return;
            }
        }
        $this->criteria[$key][] = $entry;
    }

    /**
     * Целевой проход по колонкам: только union файлов, связанных с таблицей (гасит шум коротких имён).
     */
    private function buildColumnUsage(): void
    {
        if (empty($this->tableColumns)) {
            return;
        }
        $detector = new CodeHints\ColumnUsageDetector();
        foreach ($this->tableColumns as $key => $columns) {
            if (empty($this->counts[$key]) || empty($columns)) {
                continue; // таблицы вне карты и без колонок пропускаем
            }
            $files = [];
            if (!empty($this->tableFiles[$key])) {
                foreach (array_keys($this->tableFiles[$key]) as $rel) {
                    if (isset($this->contentCache[$rel])) {
                        $files[] = [
                            'rel'     => $rel,
                            'content' => $this->contentCache[$rel]['content'],
                            'lines'   => $this->contentCache[$rel]['lines'],
                        ];
                    }
                }
            }
            if (empty($files)) {
                continue;
            }
            $cols = $detector->detect($columns, $files);

            // Три моста «enum ↔ колонка»: атрибут даёт сам детектор, а сеттеры, DQL, сырой SQL
            // и конвенцию имён — EnumBinder. Без него 91 enum хоста остаётся вне карты, и
            // «в enum есть, а в дампе нет» проверить нечем.
            $parts = explode('.', (string) $key, 2);
            $bindings = $this->enumBinder->bind(
                $parts[0],
                isset($parts[1]) ? $parts[1] : '',
                $columns,
                $files,
                $this->enumMaps()
            );
            foreach ($bindings as $col => $binding) {
                if (!isset($cols[$col])) {
                    $cols[$col] = ['usages' => [], 'count' => 0];
                }
                // Явный атрибут/каст сильнее любого моста по коду: он объявлен, а не выведен.
                $existing = isset($cols[$col]['enum']) ? $cols[$col]['enum'] : null;
                if ($existing !== null && isset($existing['confidence']) && $existing['confidence'] === 'high') {
                    $cols[$col]['enum'] = array_merge($binding, array_filter([
                        'class' => isset($existing['fqcn']) ? $existing['fqcn'] : null,
                        'bridge' => isset($existing['bridge']) ? $existing['bridge'] : null,
                        'confidence' => 'high',
                    ]));
                    continue;
                }
                $cols[$col]['enum'] = $binding;
            }
            // Подставить значения enum из глобальной карты: сначала по FQCN (если детектор
            // сумел его собрать), потом по короткому имени — но только когда оно однозначно.
            foreach ($cols as $col => &$data) {
                if (!isset($data['enum']['type'])) {
                    continue;
                }
                $fqcn = $this->resolveEnumKey($data['enum']);
                if ($fqcn === null) {
                    if (isset($data['enum']['fqcn'])) {
                        $data['enum']['ambiguous'] = true;
                    }
                    continue;
                }
                $data['enum']['fqcn'] = $fqcn;
                if (isset($this->enumValues[$fqcn])) {
                    $data['enum']['values'] = $this->enumValues[$fqcn];
                }
                if (isset($this->enumCases[$fqcn])) {
                    $data['enum']['cases'] = $this->enumCases[$fqcn];
                }
                if (isset($this->enumBacking[$fqcn])) {
                    $data['enum']['backing'] = $this->enumBacking[$fqcn];
                }
            }
            unset($data);
            if (!empty($cols)) {
                $this->columns[$key] = $cols;
            }
        }
    }

    /**
     * FQCN enum'а колонки: как записал детектор (fqcn из use-импортов файла) либо по короткому
     * имени, если такое имя в проекте одно. Два одноимённых enum'а — null: лучше молчание,
     * чем чужие case'ы (их разберёт EnumBinder по домену).
     *
     * @param array<string, mixed> $enum
     * @return string|null
     */
    private function resolveEnumKey(array $enum)
    {
        if (!empty($enum['fqcn']) && isset($this->enumValues[$enum['fqcn']])) {
            return (string) $enum['fqcn'];
        }
        $short = (string) $enum['type'];
        if (isset($this->enumValues[$short])) {
            return $short;
        }
        if (isset($this->enumByShortName[$short]) && count($this->enumByShortName[$short]) === 1) {
            return $this->enumByShortName[$short][0];
        }

        return null;
    }

    /**
     * Карты enum'ов проекта для внешних потребителей (EnumBinder, досье).
     *
     * @return array{values: array<string, array<int, string>>, cases: array<string, array<string, string>>, backing: array<string, string>, by_short_name: array<string, array<int, string>>}
     */
    public function enumMaps(): array
    {
        return [
            'values' => $this->enumValues,
            'cases' => $this->enumCases,
            'backing' => $this->enumBacking,
            'by_short_name' => $this->enumByShortName,
        ];
    }

    /**
     * Собрать итоговую карту: сводка, полные счётчики, усечённые сниппеты, флаг truncated.
     *
     * @param array<int, string> $tableKeys
     * @return array<string, array<string, mixed>>
     */
    private function finalize(array $tableKeys): array
    {
        $map = [];
        foreach ($tableKeys as $key) {
            if (empty($this->counts[$key])) {
                continue; // таблица без хитов в карту не попадает
            }
            $counts = $this->counts[$key];
            $total = array_sum($counts);
            $bareLen = $this->length($this->bareName($key));
            $truncated = ($total > self::GENERIC_HIT_THRESHOLD) || ($bareLen < self::MIN_TABLE_NAME_LEN);

            $categories = [];
            $budget = self::MAX_PER_TABLE;
            foreach (self::$SNIPPET_ORDER as $cat) {
                if (empty($this->buckets[$key][$cat])) {
                    continue;
                }
                $isDef = in_array($cat, self::$DEF_CATEGORIES, true);
                if ($truncated && !$isDef) {
                    continue; // массовые sql/usage сворачиваем в счётчик
                }
                if ($budget <= 0) {
                    break;
                }
                $snips = $this->buckets[$key][$cat];
                if (count($snips) > $budget) {
                    $snips = array_slice($snips, 0, $budget);
                }
                $budget -= count($snips);
                $categories[$cat] = $snips;
            }

            $entry = [
                'summary' => $this->summarize($counts),
                'counts' => $counts,
                'categories' => $categories,
                'truncated' => $truncated,
            ];

            // «Точные» секции — вкладываются даже при truncated (по своим лимитам).
            if (!empty($this->relationships[$key])) {
                $entry['relationships'] = array_slice($this->relationships[$key], 0, self::MAX_RELATIONSHIPS);
            }
            if (!empty($this->criteria[$key])) {
                $entry['criteria'] = array_slice($this->criteria[$key], 0, self::MAX_CRITERIA);
            }
            if (!empty($this->columns[$key])) {
                $entry['columns'] = array_slice($this->columns[$key], 0, self::MAX_COLUMNS, true);
            }

            // Коллизия голого имени: часть счётчиков могла прийти от чужой схемы (политика «обеим»).
            if (isset($this->ambiguousInfo[$key])) {
                $entry['ambiguous'] = true;
                $entry['ambiguous_with'] = array_values($this->ambiguousInfo[$key]);
            }

            $map[$key] = $entry;
        }
        return $map;
    }

    /**
     * Категория строки прохода 1 (приоритет по плану). null — голое совпадение без контекста (шум).
     *
     * @return string|null
     */
    private function categorize(string $line, string $file, bool $isSqlFile)
    {
        if (stripos($line, 'ORM\\Table') !== false) {
            return 'entity';
        }
        if (preg_match('/\$table\s*=\s*[\'"]/', $line)) {
            return 'model';
        }
        if (strpos($file, 'Repository') !== false) {
            return 'repository';
        }
        if ($isSqlFile
            || preg_match('/\b(FROM|JOIN|INTO|UPDATE|TABLE)\b/i', $line)
            || strpos($line, 'DB::table') !== false
            || preg_match('/->from\s*\(/', $line)
            || strpos($line, 'createQueryBuilder') !== false
        ) {
            return 'sql';
        }
        return null;
    }

    /**
     * Имя первого класса в файле (для реестра def-файлов и mapping). null — класса нет.
     *
     * @return string|null
     */
    private function primaryClassName(string $content)
    {
        if (preg_match('/\bclass\s+([A-Za-z_\x{0080}-\x{FFFF}][A-Za-z0-9_\x{0080}-\x{FFFF}]*)/u', $content, $cm)) {
            return $cm[1];
        }
        return null;
    }

    /**
     * Собрать «класс → таблица» из файла (в $pending, classMap строится после прохода 1):
     *  - Doctrine #[ORM\Table(name:'T')] / @ORM\Table(name="T") → класс = entity;
     *    + repositoryClass: XxxRepository::class и конвенция {Entity}Repository → repository;
     *  - Eloquent $table = 'T' → класс = model.
     *
     * @param string|null                       $class      имя первичного класса файла
     * @param array<string, array<int, string>> $normToKeys
     * @param array<int, array<string, mixed>>  $pending    аккумулятор (по ссылке)
     */
    private function collectClassMappings(string $content, $class, array $normToKeys, array &$pending): void
    {
        // Doctrine (приоритет над Eloquent).
        if (preg_match('/ORM\\\\Table\s*\(\s*name\s*[:=]\s*[\'"]([^\'"]+)[\'"]/', $content, $tm)) {
            $norm = $this->lower($tm[1]);
            if (isset($normToKeys[$norm])) {
                // Схема из того же атрибута (порядок schema:/name: любой — отдельный match): при
                // коллизии голого имени сужаем ключи класса до конкретной схемы (разводит и usage).
                $keys = $this->narrowKeysBySchema($content, $norm);
                if ($class !== null) {
                    $pending[] = ['class' => $class, 'kind' => 'entity', 'keys' => $keys, 'conventional' => false];
                    // Репозиторий по конвенции Symfony: {Entity}Repository (регистрируем, только если такой класс есть).
                    $pending[] = ['class' => $class . 'Repository', 'kind' => 'repository', 'keys' => $keys, 'conventional' => true];
                }
                // Явный repositoryClass в атрибуте/аннотации #[ORM\Entity(repositoryClass: XxxRepository::class)].
                if (preg_match('/repositoryClass\s*[:=]\s*([A-Za-z_\x{0080}-\x{FFFF}][A-Za-z0-9_\x{0080}-\x{FFFF}]*)::class/u', $content, $rm)) {
                    $pending[] = ['class' => $rm[1], 'kind' => 'repository', 'keys' => $keys, 'conventional' => false];
                }
                return;
            }
        }

        // Eloquent.
        if ($class !== null && preg_match('/\$table\s*=\s*[\'"]([^\'"]+)[\'"]/', $content, $em)) {
            $raw = $em[1];
            $norm = $this->lower($this->bareName($raw));
            if (isset($normToKeys[$norm])) {
                $keys = $normToKeys[$norm];
                // Точечный $table (clients.phones) при коллизии → сузить до конкретного ключа.
                if (strpos($raw, '.') !== false) {
                    $schemaNorm = $this->lower($this->schemaOf($raw));
                    if (isset($this->schemaKeyByBare[$norm][$schemaNorm])) {
                        $keys = [$this->schemaKeyByBare[$norm][$schemaNorm]];
                    }
                }
                $pending[] = ['class' => $class, 'kind' => 'model', 'keys' => $keys, 'conventional' => false];
            }
        }
    }

    /**
     * Сузить кандидатные ключи Doctrine-класса до конкретной схемы, если у атрибута ORM\Table
     * задан `schema: 'X'`. Нет коллизии, нет схемы или schema.name не в индексе → все кандидаты
     * по голому имени (прежнее поведение). schema: ищется отдельным match (порядок с name: любой).
     *
     * @return array<int, string>
     */
    private function narrowKeysBySchema(string $content, string $normBare): array
    {
        $all = $this->normToKeys[$normBare];
        if (count($all) <= 1) {
            return $all;
        }
        if (preg_match('/ORM\\\\Table\s*\([^)]*\bschema\s*[:=]\s*[\'"]([^\'"]+)[\'"]/', $content, $sm)) {
            $schemaNorm = $this->lower($sm[1]);
            if (isset($this->schemaKeyByBare[$normBare][$schemaNorm])) {
                return [$this->schemaKeyByBare[$normBare][$schemaNorm]];
            }
        }
        return $all;
    }

    /**
     * Построить classMap из собранных mapping'ов: разрешить def-файлы, отбросить несуществующие
     * конвенциональные репозитории, слить ключи при совпадении имени класса.
     *
     * @param array<int, array<string, mixed>> $pending
     */
    private function buildClassMap(array $pending): void
    {
        foreach ($pending as $e) {
            /** @var string $class */
            $class = $e['class'];
            /** @var array<int, string> $keys */
            $keys = $e['keys'];
            // Конвенциональный репозиторий регистрируем, только если такой класс реально существует.
            if (!empty($e['conventional']) && !isset($this->classDefFiles[$class])) {
                continue;
            }
            if (isset($this->classMap[$class])) {
                $this->classMap[$class]['keys'] = array_values(array_unique(
                    array_merge($this->classMap[$class]['keys'], $keys)
                ));
                continue;
            }
            $this->classMap[$class] = [
                'keys' => $keys,
                'kind' => $e['kind'],
                'def' => isset($this->classDefFiles[$class]) ? $this->classDefFiles[$class] : '',
            ];
        }
    }

    /**
     * Добавить hint во все ключи (счётчик всегда, сниппет — до MAX_PER_CATEGORY).
     *
     * @param array<int, string>   $keys
     * @param array<string, mixed> $hint
     */
    private function addHint(array $keys, string $category, array $hint): void
    {
        foreach ($keys as $key) {
            if (!isset($this->counts[$key][$category])) {
                $this->counts[$key][$category] = 0;
            }
            $this->counts[$key][$category]++;
            if (!isset($this->buckets[$key][$category])) {
                $this->buckets[$key][$category] = [];
            }
            if (count($this->buckets[$key][$category]) < self::MAX_PER_CATEGORY) {
                $this->buckets[$key][$category][] = $hint;
            }
            if (isset($hint['file'])) {
                $this->tableFiles[$key][(string) $hint['file']] = true;
            }
        }
    }

    /**
     * Hint = путь + номер строки + сниппет с контекстом ±CONTEXT_LINES.
     *
     * @param array<int, string> $lines
     * @return array{file: string, line: int, snippet: string}
     */
    private function makeHint(array $lines, int $idx, string $rel): array
    {
        return [
            'file' => $rel,
            'line' => $idx + 1,
            'snippet' => $this->snippet($lines, $idx, self::CONTEXT_LINES, self::CONTEXT_LINES, self::MAX_SNIPPET_LINE),
        ];
    }

    /**
     * @param array<string, int> $counts
     */
    private function summarize(array $counts): string
    {
        $parts = [];
        foreach (self::$SUMMARY_ORDER as $cat) {
            if (!empty($counts[$cat])) {
                $parts[] = $counts[$cat] . ' ' . $cat;
            }
        }
        return implode(', ', $parts);
    }

    /**
     * Alternation-regex по именам (сорт по длине убыв. — длинные раньше). null, если имён нет.
     *
     * @param array<int, string> $names
     * @return string|null
     */
    private function buildAlternation(array $names, bool $caseInsensitive)
    {
        $names = array_values(array_unique($names));
        if (empty($names)) {
            return null;
        }
        usort($names, function ($a, $b) {
            return strlen($b) - strlen($a);
        });
        $quoted = [];
        foreach ($names as $n) {
            $quoted[] = preg_quote($n, '/');
        }
        $flags = $caseInsensitive ? 'iu' : 'u';
        return '/\b(' . implode('|', $quoted) . ')\b/' . $flags;
    }

    private function relPath(string $absPath): string
    {
        $rel = $absPath;
        foreach (['/', DIRECTORY_SEPARATOR] as $sep) {
            $prefix = $this->projectDir . $sep;
            if (strpos($absPath, $prefix) === 0) {
                $rel = substr($absPath, strlen($prefix));
                break;
            }
        }
        return str_replace('\\', '/', $rel);
    }

    /**
     * Прочитать файл и разбить на строки. null — пустой/нечитаемый файл (пропускаем).
     *
     * @return array{rel: string, content: string, lines: array<int, string>}|null
     */
    private function loadFile(string $absPath)
    {
        $content = $this->readFile($absPath);
        if (!is_string($content) || $content === '') {
            return null;
        }
        $lines = preg_split('/\R/u', $content);
        if ($lines === false) {
            $lines = explode("\n", $content);
        }
        return ['rel' => $this->relPath($absPath), 'content' => $content, 'lines' => $lines];
    }

    /**
     * Перечислить файлы-кандидаты через Symfony Finder. Protected — для подмены в тестах.
     *
     * @return array<int, string> абсолютные пути
     */
    protected function enumerateFiles(string $dataDir): array
    {
        if (!is_dir($this->projectDir)) {
            return [];
        }
        try {
            $finder = new Finder();
            $finder->files()
                ->in($this->projectDir)
                ->name(['*.php', '*.yaml', '*.yml', '*.sql'])
                ->exclude(self::$EXCLUDE_DIRS)
                ->notPath('#(^|/)' . preg_quote($dataDir, '#') . '/(dumps|analysis)(/|$)#')
                ->size(self::MAX_FILE_SIZE)
                ->ignoreUnreadableDirs();
            $paths = [];
            foreach ($finder as $file) {
                $paths[] = $file->getPathname();
            }
            return $paths;
        } catch (\Exception $e) {
            $this->logger->warning('CodeHintScanner: перечисление файлов не удалось: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Прочитать файл. Protected — для подмены в тестах.
     *
     * @return string|false
     */
    protected function readFile(string $path)
    {
        return @file_get_contents($path);
    }
}
