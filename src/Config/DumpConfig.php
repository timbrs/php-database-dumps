<?php

namespace Timbrs\DatabaseDumps\Config;

/**
 * DTO для конфигурации экспорта дампов
 */
class DumpConfig
{
    public const KEY_FULL_EXPORT = 'full_export';
    public const KEY_PARTIAL_EXPORT = 'partial_export';
    public const KEY_CONNECTIONS = 'connections';
    public const KEY_INCLUDES = 'includes';
    public const KEY_FAKER = 'faker';
    public const KEY_SETTINGS = 'settings';
    public const DUMPS_DIR = 'database/dumps';

    public const SETTING_BATCH_SIZE = 'batch_size';
    public const SETTING_SAMPLE_SIZE = 'sample_size';
    public const SETTING_MAX_CASCADE_DEPTH = 'max_cascade_depth';

    public const DEFAULT_BATCH_SIZE = 1000;
    public const DEFAULT_SAMPLE_SIZE = 200;
    public const DEFAULT_MAX_CASCADE_DEPTH = 10;

    /**
     * @var array<string, array<string>> Полный экспорт по схемам
     */
    private $fullExport;

    /**
     * @var array<string, array<string, array<string, mixed>>> Частичный экспорт с условиями
     */
    private $partialExport;

    /**
     * @var array<string, DumpConfig> Конфигурации дополнительных подключений
     */
    private $connections;

    /** @var FakerConfig|null */
    private $fakerConfig;

    /** @var array<string, mixed> */
    private $settings;

    /**
     * @param array<string, array<string>> $fullExport
     * @param array<string, array<string, array<string, mixed>>> $partialExport
     * @param array<string, DumpConfig> $connections
     * @param FakerConfig|null $fakerConfig
     * @param array<string, mixed> $settings
     */
    public function __construct(
        array $fullExport,
        array $partialExport,
        array $connections = [],
        ?FakerConfig $fakerConfig = null,
        array $settings = []
    ) {
        $this->fullExport = $fullExport;
        $this->partialExport = $partialExport;
        $this->connections = $connections;
        $this->fakerConfig = $fakerConfig;
        $this->settings = $settings;
    }

    /**
     * Получить таблицы для полного экспорта из схемы
     *
     * @return array<string>
     */
    public function getFullExportTables(string $schema): array
    {
        return $this->fullExport[$schema] ?? [];
    }

    /**
     * Получить таблицы для частичного экспорта из схемы
     *
     * @return array<string, array<string, mixed>>
     */
    public function getPartialExportTables(string $schema): array
    {
        return $this->partialExport[$schema] ?? [];
    }

    /**
     * Получить все схемы для полного экспорта
     *
     * @return array<string>
     */
    public function getAllFullExportSchemas(): array
    {
        return array_keys($this->fullExport);
    }

    /**
     * Получить все схемы для частичного экспорта
     *
     * @return array<string>
     */
    public function getAllPartialExportSchemas(): array
    {
        return array_keys($this->partialExport);
    }

    /**
     * Получить конфигурацию для конкретной таблицы
     *
     * @return array<string, mixed>|null
     */
    public function getTableConfig(string $schema, string $table): ?array
    {
        // Проверка в partial_export
        if (isset($this->partialExport[$schema][$table])) {
            return $this->partialExport[$schema][$table];
        }

        // Проверка в full_export
        if (isset($this->fullExport[$schema]) && in_array($table, $this->fullExport[$schema], true)) {
            return [];
        }

        return null;
    }

    /**
     * Получить конфигурации дополнительных подключений
     *
     * @return array<string, DumpConfig>
     */
    public function getConnectionConfigs(): array
    {
        return $this->connections;
    }

    /**
     * Получить конфигурацию конкретного подключения
     */
    public function getConnectionConfig(string $name): ?DumpConfig
    {
        return $this->connections[$name] ?? null;
    }

    /**
     * Есть ли дополнительные подключения
     */
    public function isMultiConnection(): bool
    {
        return !empty($this->connections);
    }

    /**
     * Получить конфигурацию фейкера
     */
    public function getFakerConfig(): FakerConfig
    {
        if ($this->fakerConfig === null) {
            return new FakerConfig();
        }

        return $this->fakerConfig;
    }

    /**
     * Получить все настройки
     *
     * @return array<string, mixed>
     */
    public function getSettings(): array
    {
        return $this->settings;
    }

    /**
     * Получить размер батча для INSERT
     */
    public function getBatchSize(): int
    {
        return isset($this->settings[self::SETTING_BATCH_SIZE])
            ? (int) $this->settings[self::SETTING_BATCH_SIZE]
            : self::DEFAULT_BATCH_SIZE;
    }

    /**
     * Получить размер выборки для PatternDetector
     */
    public function getSampleSize(): int
    {
        return isset($this->settings[self::SETTING_SAMPLE_SIZE])
            ? (int) $this->settings[self::SETTING_SAMPLE_SIZE]
            : self::DEFAULT_SAMPLE_SIZE;
    }

    /**
     * Получить максимальную глубину каскадных подзапросов
     */
    public function getMaxCascadeDepth(): int
    {
        return isset($this->settings[self::SETTING_MAX_CASCADE_DEPTH])
            ? (int) $this->settings[self::SETTING_MAX_CASCADE_DEPTH]
            : self::DEFAULT_MAX_CASCADE_DEPTH;
    }
}
