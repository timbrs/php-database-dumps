<?php

namespace Timbrs\DatabaseDumps\Service\Validation;

use Timbrs\DatabaseDumps\Contract\FileSystemInterface;

/**
 * Чтение замороженного слепка схемы БД (`{data_dir}/analysis/schema_inventory.json`)
 * без обращения к самой базе.
 *
 * Слепок бывает в двух видах, и оба пишет AnalysisPackageBuilder:
 *  - монолитный `schema_inventory.json` — все схемы разом (на крупной БД это мегабайты);
 *  - пер-схемные `schema_inventory.<schema>.json` — тот же формат, но с одной схемой внутри.
 *
 * Читатель предпочитает пер-схемные файлы: тогда в память поднимается ровно та схема,
 * которую спросили, а не весь слепок. Пер-схемные берутся, только если их `generated_at`
 * совпадает с монолитом (иначе это остатки прошлого прогона — падаем обратно на монолит).
 * Монолит декодируется один раз и сразу сжимается до проекции (типы колонок, row_count,
 * нужные поля профилей, FK) — сырой массив не удерживается.
 */
class InventoryReader
{
    /** Имя монолитного слепка внутри {data_dir}/analysis. */
    public const DEFAULT_FILE = 'schema_inventory.json';

    /** @var FileSystemInterface */
    private $fileSystem;

    /** @var string Абсолютный (или относительный рабочему каталогу) путь к монолиту */
    private $path;

    /** @var string Каталог, в котором лежит слепок */
    private $dir;

    /** @var array<string, string>|null schema => путь к пер-схемному файлу (null — ещё не искали) */
    private $schemaFiles;

    /** @var array<string, string>|null Пер-схемные файлы, признанные пригодными */
    private $usableFiles;

    /** @var array<string, string|null> Кэш generated_at по файлам */
    private $stampCache = [];

    /** @var array<string, array<string, mixed>> Проекция по схемам: schema => tables */
    private $tablesBySchema = [];

    /** @var bool Монолит уже разобран целиком */
    private $monolithLoaded = false;

    /** @var string|null */
    private $generatedAt;

    /** @var bool */
    private $generatedAtResolved = false;

    public function __construct(FileSystemInterface $fileSystem, string $path)
    {
        $this->fileSystem = $fileSystem;
        $this->path = $path;
        $dir = dirname($path);
        $this->dir = $dir === '' ? '.' : $dir;
    }

    /**
     * Есть ли вообще слепок — монолит или хотя бы один пер-схемный файл.
     */
    public function exists(): bool
    {
        if ($this->fileSystem->exists($this->path)) {
            return true;
        }
        return !empty($this->discoverSchemaFiles());
    }

    public function getPath(): string
    {
        return $this->path;
    }

    /**
     * Отметка времени сборки слепка (`generated_at`, UTC ISO-8601) или null.
     */
    public function generatedAt(): ?string
    {
        if ($this->generatedAtResolved) {
            return $this->generatedAt;
        }
        $this->generatedAtResolved = true;

        if ($this->fileSystem->exists($this->path)) {
            $this->generatedAt = $this->peekGeneratedAt($this->path);
            if ($this->generatedAt !== null) {
                return $this->generatedAt;
            }
        }

        foreach ($this->discoverSchemaFiles() as $file) {
            $this->generatedAt = $this->peekGeneratedAt($file);
            break;
        }

        return $this->generatedAt;
    }

    /**
     * Все схемы слепка (отсортированы).
     *
     * @return array<int, string>
     */
    public function schemas(): array
    {
        $files = $this->usableSchemaFiles();
        if (!empty($files)) {
            $schemas = array_keys($files);
            sort($schemas);
            return $schemas;
        }

        $this->loadMonolith();
        $schemas = array_keys($this->tablesBySchema);
        sort($schemas);
        return $schemas;
    }

    public function hasSchema(string $schema): bool
    {
        return $this->schemaTables($schema) !== null;
    }

    /**
     * Таблицы схемы (отсортированы). Пустой массив, если схемы нет.
     *
     * @return array<int, string>
     */
    public function tables(string $schema): array
    {
        $tables = $this->schemaTables($schema);
        if ($tables === null) {
            return [];
        }
        $names = array_keys($tables);
        sort($names);
        return $names;
    }

    public function hasTable(string $schema, string $table): bool
    {
        $tables = $this->schemaTables($schema);
        return $tables !== null && isset($tables[$table]);
    }

    /**
     * Имена колонок таблицы в порядке слепка. Пустой массив, если таблицы нет.
     *
     * @return array<int, string>
     */
    public function columns(string $schema, string $table): array
    {
        $entry = $this->tableEntry($schema, $table);
        return $entry === null ? [] : array_keys($entry['columns']);
    }

    /**
     * Тип колонки как его отдаёт БД («timestamp without time zone», «character varying»).
     */
    public function columnType(string $schema, string $table, string $column): ?string
    {
        $entry = $this->tableEntry($schema, $table);
        if ($entry === null || !isset($entry['columns'][$column])) {
            return null;
        }
        return $entry['columns'][$column];
    }

    public function hasColumn(string $schema, string $table, string $column): bool
    {
        $entry = $this->tableEntry($schema, $table);
        return $entry !== null && isset($entry['columns'][$column]);
    }

    /**
     * Число строк на момент сборки слепка (null — таблицы нет или счёт не снимался).
     */
    public function rowCount(string $schema, string $table): ?int
    {
        $entry = $this->tableEntry($schema, $table);
        return $entry === null ? null : $entry['row_count'];
    }

    /**
     * Профиль колонки: distinct_count / null_fraction / categorical / data_type.
     *
     * @return array<string, mixed>|null
     */
    public function profile(string $schema, string $table, string $column): ?array
    {
        $entry = $this->tableEntry($schema, $table);
        if ($entry === null || !isset($entry['profiles'][$column])) {
            return null;
        }
        return $entry['profiles'][$column];
    }

    /**
     * Внешние ключи таблицы из слепка. В базах без FK-констрейнтов список пуст —
     * именно из-за этого порядок экспорта вырождается в алфавитный (см. CascadeGraphRule).
     *
     * @return array<int, array{column: string, references_table: string, references_column: string}>
     */
    public function foreignKeys(string $schema, string $table): array
    {
        $entry = $this->tableEntry($schema, $table);
        return $entry === null ? [] : $entry['foreign_keys'];
    }

    /**
     * Общее число таблиц во всех схемах слепка.
     */
    public function countTables(): int
    {
        $count = 0;
        foreach ($this->schemas() as $schema) {
            $count += count($this->tables($schema));
        }
        return $count;
    }

    /**
     * @return array{row_count: int|null, columns: array<string, string>, profiles: array<string, array<string, mixed>>, foreign_keys: array<int, array{column: string, references_table: string, references_column: string}>}|null
     */
    private function tableEntry(string $schema, string $table): ?array
    {
        $tables = $this->schemaTables($schema);
        if ($tables === null || !isset($tables[$table])) {
            return null;
        }
        /** @var array{row_count: int|null, columns: array<string, string>, profiles: array<string, array<string, mixed>>, foreign_keys: array<int, array{column: string, references_table: string, references_column: string}>} $entry */
        $entry = $tables[$table];
        return $entry;
    }

    /**
     * Таблицы схемы (ленивая загрузка). null — схемы в слепке нет.
     *
     * @return array<string, array<string, mixed>>|null
     */
    private function schemaTables(string $schema): ?array
    {
        if (isset($this->tablesBySchema[$schema])) {
            /** @var array<string, array<string, mixed>> $tables */
            $tables = $this->tablesBySchema[$schema];
            return $tables;
        }

        $files = $this->usableSchemaFiles();
        if (isset($files[$schema])) {
            $data = $this->decodeFile($files[$schema]);
            $this->absorb($data);
            if (isset($this->tablesBySchema[$schema])) {
                /** @var array<string, array<string, mixed>> $tables */
                $tables = $this->tablesBySchema[$schema];
                return $tables;
            }
            return null;
        }

        if (!empty($files)) {
            // Пер-схемные файлы есть, но этой схемы среди них нет — в слепке её нет.
            return null;
        }

        $this->loadMonolith();
        if (isset($this->tablesBySchema[$schema])) {
            /** @var array<string, array<string, mixed>> $tables */
            $tables = $this->tablesBySchema[$schema];
            return $tables;
        }
        return null;
    }

    private function loadMonolith(): void
    {
        if ($this->monolithLoaded) {
            return;
        }
        $this->monolithLoaded = true;

        if (!$this->fileSystem->exists($this->path)) {
            return;
        }
        $this->absorb($this->decodeFile($this->path));
    }

    /**
     * Пер-схемные файлы, пригодные к использованию: они существуют и (если монолит тоже
     * есть) собраны в том же прогоне. Иначе — пустой массив, работаем по монолиту.
     *
     * @return array<string, string>
     */
    private function usableSchemaFiles(): array
    {
        if ($this->usableFiles !== null) {
            return $this->usableFiles;
        }

        $files = $this->discoverSchemaFiles();
        $this->usableFiles = $files;

        if (empty($files) || !$this->fileSystem->exists($this->path)) {
            return $this->usableFiles;
        }

        $monolithStamp = $this->peekGeneratedAt($this->path);
        if ($monolithStamp === null) {
            return $this->usableFiles;
        }
        foreach ($files as $file) {
            $stamp = $this->peekGeneratedAt($file);
            if ($stamp !== null && $stamp !== $monolithStamp) {
                // Пер-схемные файлы от другого прогона — доверяем монолиту.
                $this->usableFiles = [];
                break;
            }
        }
        return $this->usableFiles;
    }

    /**
     * Найти `schema_inventory.<schema>.json` рядом с монолитом.
     *
     * @return array<string, string> schema => абсолютный путь
     */
    private function discoverSchemaFiles(): array
    {
        if ($this->schemaFiles !== null) {
            return $this->schemaFiles;
        }
        $this->schemaFiles = [];

        if (!$this->fileSystem->isDirectory($this->dir)) {
            return $this->schemaFiles;
        }

        $base = basename($this->path, '.json');
        foreach ($this->fileSystem->findFiles($this->dir, $base . '.*.json') as $file) {
            $name = basename($file, '.json');
            $schema = substr($name, strlen($base) + 1);
            if ($schema === '') {
                continue;
            }
            // Имя схемы становится частью пути — тот же фильтр, что и при записи слепка.
            if (!preg_match('/^[\p{L}_][\p{L}\p{N}_$]*$/u', $schema)) {
                continue;
            }
            $this->schemaFiles[$schema] = $file;
        }

        return $this->schemaFiles;
    }

    /**
     * Прочитать `generated_at` из головы файла, не декодируя его целиком.
     */
    private function peekGeneratedAt(string $file): ?string
    {
        if (array_key_exists($file, $this->stampCache)) {
            return $this->stampCache[$file];
        }
        $this->stampCache[$file] = null;

        // Голова файла — 512 байт хватает на generated_at. Читаем напрямую, чтобы не
        // поднимать в память многомегабайтный слепок ради одной строки; если файл живёт
        // не на диске (тесты с in-memory ФС) — обычное чтение через абстракцию.
        $head = @file_get_contents($file, false, null, 0, 512);
        if ($head === false) {
            try {
                $head = substr($this->fileSystem->read($file), 0, 512);
            } catch (\Throwable $e) {
                return null;
            }
        }
        if (preg_match('/"generated_at"\s*:\s*"([^"]*)"/', $head, $m) === 1) {
            $this->stampCache[$file] = $m[1];
        }
        return $this->stampCache[$file];
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeFile(string $file): array
    {
        try {
            $content = $this->fileSystem->read($file);
        } catch (\Throwable $e) {
            return [];
        }
        $data = json_decode($content, true);
        unset($content);
        return is_array($data) ? $data : [];
    }

    /**
     * Сжать разобранный слепок до проекции и запомнить по схемам.
     *
     * @param array<string, mixed> $data
     */
    private function absorb(array $data): void
    {
        if (!$this->generatedAtResolved && isset($data['generated_at']) && is_string($data['generated_at'])) {
            $this->generatedAt = $data['generated_at'];
            $this->generatedAtResolved = true;
        }

        if (!isset($data['schemas']) || !is_array($data['schemas'])) {
            return;
        }

        foreach ($data['schemas'] as $schema => $schemaData) {
            $schema = (string) $schema;
            if (!is_array($schemaData) || !isset($schemaData['tables']) || !is_array($schemaData['tables'])) {
                $this->tablesBySchema[$schema] = [];
                continue;
            }
            $tables = [];
            foreach ($schemaData['tables'] as $table => $tableData) {
                $tables[(string) $table] = $this->projectTable(is_array($tableData) ? $tableData : []);
            }
            $this->tablesBySchema[$schema] = $tables;
        }
    }

    /**
     * Оставить от таблицы только то, что нужно правилам, — иначе 5-мегабайтный слепок
     * висит в памяти целиком.
     *
     * @param array<string, mixed> $tableData
     * @return array{row_count: int|null, columns: array<string, string>, profiles: array<string, array<string, mixed>>, foreign_keys: array<int, array{column: string, references_table: string, references_column: string}>}
     */
    private function projectTable(array $tableData): array
    {
        $columns = [];
        if (isset($tableData['columns']) && is_array($tableData['columns'])) {
            foreach ($tableData['columns'] as $column) {
                if (!is_array($column) || !isset($column['name'])) {
                    continue;
                }
                $type = isset($column['type']) && is_scalar($column['type']) ? (string) $column['type'] : '';
                $columns[(string) $column['name']] = $type;
            }
        }

        $profiles = [];
        if (isset($tableData['profiles']) && is_array($tableData['profiles'])) {
            foreach ($tableData['profiles'] as $profile) {
                if (!is_array($profile) || !isset($profile['column'])) {
                    continue;
                }
                $name = (string) $profile['column'];
                $profiles[$name] = [
                    'data_type' => isset($profile['data_type']) && is_scalar($profile['data_type'])
                        ? (string) $profile['data_type'] : null,
                    'nullable' => isset($profile['nullable']) ? (bool) $profile['nullable'] : null,
                    'null_fraction' => isset($profile['null_fraction']) && is_numeric($profile['null_fraction'])
                        ? (float) $profile['null_fraction'] : null,
                    'distinct_count' => isset($profile['distinct_count']) && is_numeric($profile['distinct_count'])
                        ? (int) $profile['distinct_count'] : null,
                    'distinct_capped' => isset($profile['distinct_capped']) ? (bool) $profile['distinct_capped'] : null,
                    'categorical' => isset($profile['categorical']) ? (bool) $profile['categorical'] : null,
                ];
            }
        }

        $foreignKeys = [];
        if (isset($tableData['foreign_keys']) && is_array($tableData['foreign_keys'])) {
            foreach ($tableData['foreign_keys'] as $fk) {
                if (!is_array($fk) || !isset($fk['column'], $fk['references_table'], $fk['references_column'])) {
                    continue;
                }
                $foreignKeys[] = [
                    'column' => (string) $fk['column'],
                    'references_table' => (string) $fk['references_table'],
                    'references_column' => (string) $fk['references_column'],
                ];
            }
        }

        return [
            'row_count' => isset($tableData['row_count']) && is_numeric($tableData['row_count'])
                ? (int) $tableData['row_count'] : null,
            'columns' => $columns,
            'profiles' => $profiles,
            'foreign_keys' => $foreignKeys,
        ];
    }
}
