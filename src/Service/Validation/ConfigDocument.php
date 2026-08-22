<?php

namespace Timbrs\DatabaseDumps\Service\Validation;

use Symfony\Component\Yaml\Yaml;
use Timbrs\DatabaseDumps\Config\DumpConfig;
use Timbrs\DatabaseDumps\Contract\FileSystemInterface;
use Timbrs\DatabaseDumps\Util\YamlConfigLoader;

/**
 * Конфиг выгрузки, разобранный «как есть» — со всеми ошибками, а не вместо них.
 *
 * YamlConfigLoader строит DumpConfig и по дороге падает на первой же беде (неизвестный
 * faker-паттерн валит FakerConfig, кривая таблица — TableConfig), поэтому для аудита он
 * не годится: нужно собрать ВСЕ проблемы разом. Здесь читается тот же YAML и теми же
 * правилами (включая защиту от path traversal в `includes:` — YamlConfigLoader::resolveIncludePath),
 * но результат — сырые массивы плюс список того, что не прочиталось.
 *
 * Дополнительно запоминается, из какого файла пришла каждая схема: правки --fix пишутся
 * ровно в него, а не в общий dump_config.yaml.
 */
class ConfigDocument
{
    /** @var FileSystemInterface */
    private $fileSystem;

    /** @var string */
    private $configPath;

    /** @var array<string, mixed> Содержимое главного файла (includes ещё не развёрнуты) */
    private $mainRaw = [];

    /** @var array<string, string> schema => относительный путь из includes: */
    private $includes = [];

    /** @var array<string, string> schema => абсолютный путь к файлу настроек схемы */
    private $schemaFiles = [];

    /** @var array<string, array<string, mixed>> schema => содержимое её файла целиком */
    private $schemaRaw = [];

    /** @var array<int, array{file: string, schema: string|null, message: string}> Что не прочиталось (код S-1) */
    private $loadErrors = [];

    /** @var array<string, array<int, string>> schema => full_export */
    private $fullExport = [];

    /** @var array<string, array<string, array<string, mixed>>> schema => partial_export */
    private $partialExport = [];

    /** @var array<string, array<string, array<string, string>>> schema => faker */
    private $faker = [];

    /** @var array<string, mixed> */
    private $settings = [];

    private function __construct(FileSystemInterface $fileSystem, string $configPath)
    {
        $this->fileSystem = $fileSystem;
        $this->configPath = $configPath;
    }

    /**
     * Прочитать конфиг вместе со всеми пер-схемными файлами.
     *
     * Отсутствие главного файла — не исключение, а запись в loadErrors: аудит должен
     * доложить об этом находкой, а не падением.
     */
    public static function load(FileSystemInterface $fileSystem, string $configPath): self
    {
        $doc = new self($fileSystem, $configPath);
        $doc->read();
        return $doc;
    }

    public function getConfigPath(): string
    {
        return $this->configPath;
    }

    /**
     * Разбит ли конфиг на пер-схемные файлы (`includes:`).
     */
    public function isSplit(): bool
    {
        return !empty($this->includes);
    }

    /**
     * @return array<string, string> schema => относительный путь из includes:
     */
    public function getIncludes(): array
    {
        return $this->includes;
    }

    /**
     * Файл, в который надо писать правки по схеме: её собственный или главный конфиг.
     */
    public function getSourceFile(string $schema): string
    {
        return isset($this->schemaFiles[$schema]) ? $this->schemaFiles[$schema] : $this->configPath;
    }

    /**
     * Все схемы конфига (full_export ∪ partial_export ∪ faker ∪ includes), отсортированы.
     *
     * @return array<int, string>
     */
    public function getSchemas(): array
    {
        $schemas = [];
        foreach ([$this->fullExport, $this->partialExport, $this->faker, $this->includes] as $source) {
            foreach (array_keys($source) as $schema) {
                $schemas[(string) $schema] = true;
            }
        }
        $names = array_keys($schemas);
        sort($names);
        return $names;
    }

    /**
     * @return array<int, string>
     */
    public function getFullExport(string $schema): array
    {
        return isset($this->fullExport[$schema]) ? $this->fullExport[$schema] : [];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function getPartialExport(string $schema): array
    {
        return isset($this->partialExport[$schema]) ? $this->partialExport[$schema] : [];
    }

    /**
     * @return array<string, array<string, string>> table => column => pattern
     */
    public function getFaker(string $schema): array
    {
        return isset($this->faker[$schema]) ? $this->faker[$schema] : [];
    }

    /**
     * @return array<string, mixed>
     */
    public function getSettings(): array
    {
        return $this->settings;
    }

    public function getMaxCascadeDepth(): int
    {
        return isset($this->settings[DumpConfig::SETTING_MAX_CASCADE_DEPTH])
            ? (int) $this->settings[DumpConfig::SETTING_MAX_CASCADE_DEPTH]
            : DumpConfig::DEFAULT_MAX_CASCADE_DEPTH;
    }

    /**
     * Сырое содержимое файла схемы (для поиска пустых секций и для правок).
     *
     * @return array<string, mixed>
     */
    public function getSchemaRaw(string $schema): array
    {
        return isset($this->schemaRaw[$schema]) ? $this->schemaRaw[$schema] : [];
    }

    /**
     * @return array<string, mixed>
     */
    public function getMainRaw(): array
    {
        return $this->mainRaw;
    }

    /**
     * Ошибки чтения конфига: не разобранный YAML, пропавший include-файл.
     *
     * @return array<int, array{file: string, schema: string|null, message: string}>
     */
    public function getLoadErrors(): array
    {
        return $this->loadErrors;
    }

    /**
     * Все таблицы конфига схемы: table => 'full'|'partial'. Таблица, попавшая в обе
     * секции, отмечается как 'both' (находка S-3).
     *
     * @return array<string, string>
     */
    public function getTableModes(string $schema): array
    {
        $modes = [];
        foreach ($this->getFullExport($schema) as $table) {
            $modes[$table] = 'full';
        }
        foreach (array_keys($this->getPartialExport($schema)) as $table) {
            $modes[$table] = isset($modes[$table]) ? 'both' : 'partial';
        }
        return $modes;
    }

    /**
     * Все выгружаемые таблицы во всех схемах: «schema.table» => true.
     *
     * @return array<string, bool>
     */
    public function getExportedTableKeys(): array
    {
        $keys = [];
        foreach ($this->getSchemas() as $schema) {
            foreach (array_keys($this->getTableModes($schema)) as $table) {
                $keys[$schema . '.' . $table] = true;
            }
        }
        return $keys;
    }

    private function read(): void
    {
        if (!$this->fileSystem->exists($this->configPath)) {
            $this->loadErrors[] = [
                'file' => $this->configPath,
                'schema' => null,
                'message' => 'файл конфигурации не найден',
            ];
            return;
        }

        $main = $this->parseFile($this->configPath);
        if ($main === null) {
            return;
        }
        $this->mainRaw = $main;

        if (isset($main[DumpConfig::KEY_SETTINGS]) && is_array($main[DumpConfig::KEY_SETTINGS])) {
            $this->settings = $main[DumpConfig::KEY_SETTINGS];
        }

        // Секции, заданные прямо в главном файле (конфиг без разбиения).
        $this->absorbSections($main, null);

        if (!isset($main[DumpConfig::KEY_INCLUDES]) || !is_array($main[DumpConfig::KEY_INCLUDES])) {
            return;
        }

        $configDir = YamlConfigLoader::configDirOf($this->configPath);
        foreach ($main[DumpConfig::KEY_INCLUDES] as $schema => $relativePath) {
            $schema = (string) $schema;
            if (!is_string($relativePath)) {
                $this->loadErrors[] = [
                    'file' => $this->configPath,
                    'schema' => $schema,
                    'message' => sprintf('includes.%s: путь должен быть строкой', $schema),
                ];
                continue;
            }
            $this->includes[$schema] = $relativePath;

            try {
                $path = YamlConfigLoader::resolveIncludePath($configDir, $relativePath);
            } catch (\InvalidArgumentException $e) {
                $this->loadErrors[] = [
                    'file' => $this->configPath,
                    'schema' => $schema,
                    'message' => sprintf('includes.%s: %s', $schema, $e->getMessage()),
                ];
                continue;
            }

            if (!$this->fileSystem->exists($path)) {
                $this->loadErrors[] = [
                    'file' => $path,
                    'schema' => $schema,
                    'message' => sprintf(
                        'includes.%s указывает на отсутствующий файл — схема не войдёт в дамп',
                        $schema
                    ),
                ];
                continue;
            }

            $schemaData = $this->parseFile($path, $schema);
            if ($schemaData === null) {
                continue;
            }

            $this->schemaFiles[$schema] = $path;
            $this->schemaRaw[$schema] = $schemaData;
            $this->absorbSections($schemaData, $schema);
        }
    }

    /**
     * Разложить секции файла по схемам.
     *
     * @param array<string, mixed> $data
     * @param string|null $schema имя схемы для пер-схемного файла; null — секции главного
     *                            файла, где схема стоит ключом внутри секции
     */
    private function absorbSections(array $data, ?string $schema): void
    {
        $sections = [
            DumpConfig::KEY_FULL_EXPORT => 'fullExport',
            DumpConfig::KEY_PARTIAL_EXPORT => 'partialExport',
            DumpConfig::KEY_FAKER => 'faker',
        ];

        foreach ($sections as $key => $property) {
            if (!array_key_exists($key, $data)) {
                continue;
            }
            $value = $data[$key];
            if (!is_array($value)) {
                continue;
            }

            if ($schema !== null) {
                $this->assign($property, $schema, $value);
                continue;
            }

            foreach ($value as $schemaName => $schemaValue) {
                if (is_array($schemaValue)) {
                    $this->assign($property, (string) $schemaName, $schemaValue);
                }
            }
        }

        if ($schema !== null) {
            return;
        }

        // Схемы, объявленные прямо в главном файле, правятся в нём же.
        foreach ([DumpConfig::KEY_FULL_EXPORT, DumpConfig::KEY_PARTIAL_EXPORT, DumpConfig::KEY_FAKER] as $key) {
            if (!isset($data[$key]) || !is_array($data[$key])) {
                continue;
            }
            foreach (array_keys($data[$key]) as $schemaName) {
                $schemaName = (string) $schemaName;
                if (!isset($this->schemaRaw[$schemaName])) {
                    $this->schemaRaw[$schemaName] = [];
                }
            }
        }
    }

    /**
     * @param array<mixed, mixed> $value
     */
    private function assign(string $property, string $schema, array $value): void
    {
        if ($property === 'fullExport') {
            $tables = [];
            foreach ($value as $table) {
                if (is_string($table) || is_int($table)) {
                    $tables[] = (string) $table;
                }
            }
            $this->fullExport[$schema] = $tables;
            return;
        }

        if ($property === 'partialExport') {
            $tables = [];
            foreach ($value as $table => $conf) {
                $tables[(string) $table] = is_array($conf) ? $conf : [];
            }
            $this->partialExport[$schema] = $tables;
            return;
        }

        $tables = [];
        foreach ($value as $table => $columns) {
            if (!is_array($columns)) {
                continue;
            }
            $map = [];
            foreach ($columns as $column => $pattern) {
                $map[(string) $column] = is_scalar($pattern) ? (string) $pattern : '';
            }
            $tables[(string) $table] = $map;
        }
        $this->faker[$schema] = $tables;
    }

    /**
     * @return array<string, mixed>|null null — файл не разобрался (ошибка записана)
     */
    private function parseFile(string $path, ?string $schema = null): ?array
    {
        try {
            $parsed = Yaml::parse($this->fileSystem->read($path));
        } catch (\Throwable $e) {
            $this->loadErrors[] = [
                'file' => $path,
                'schema' => $schema,
                'message' => 'YAML не разобран: ' . $e->getMessage(),
            ];
            return null;
        }

        if ($parsed === null) {
            return [];
        }
        if (!is_array($parsed)) {
            $this->loadErrors[] = [
                'file' => $path,
                'schema' => $schema,
                'message' => 'ожидался YAML-объект, получено ' . gettype($parsed),
            ];
            return null;
        }

        /** @var array<string, mixed> $parsed */
        return $parsed;
    }
}
