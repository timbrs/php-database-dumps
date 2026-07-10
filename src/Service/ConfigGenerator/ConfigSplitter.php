<?php

namespace Timbrs\DatabaseDumps\Service\ConfigGenerator;

use Timbrs\DatabaseDumps\Config\DumpConfig;
use Timbrs\DatabaseDumps\Contract\FileSystemInterface;
use Timbrs\DatabaseDumps\Contract\LoggerInterface;
use Symfony\Component\Yaml\Yaml;

class ConfigSplitter
{
    /**
     * Подкаталог рядом с dump_config.yaml, куда складываются пер-схемные файлы.
     * Итог: {configDir}/dump-settings/{schema}.yaml, includes → dump-settings/{schema}.yaml.
     * Для подключений: {configDir}/dump-settings/{connName}/{schema}.yaml.
     */
    public const SETTINGS_SUBDIR = 'dump-settings';

    /** @var FileSystemInterface */
    private $fileSystem;

    /** @var LoggerInterface */
    private $logger;

    public function __construct(FileSystemInterface $fileSystem, LoggerInterface $logger)
    {
        $this->fileSystem = $fileSystem;
        $this->logger = $logger;
    }

    /**
     * Split config into per-schema files and write main config with includes.
     *
     * @param string $mainConfigPath path to the main dump_config.yaml
     * @param array<string, mixed> $config the full parsed config array
     */
    public function split(string $mainConfigPath, array $config): void
    {
        $configDir = dirname($mainConfigPath);
        $schemas = $this->collectSchemas($config);

        // Валидация имён схем/connection (защита от path traversal через имена в БД/YAML).
        foreach ($schemas as $schema) {
            $this->validateName('schema', $schema);
        }
        if (isset($config[DumpConfig::KEY_CONNECTIONS]) && is_array($config[DumpConfig::KEY_CONNECTIONS])) {
            foreach (array_keys($config[DumpConfig::KEY_CONNECTIONS]) as $connName) {
                $this->validateName('connection', (string) $connName);
                if (is_array($config[DumpConfig::KEY_CONNECTIONS][$connName])) {
                    foreach ($this->collectSchemas($config[DumpConfig::KEY_CONNECTIONS][$connName]) as $s) {
                        $this->validateName('schema', $s);
                    }
                }
            }
        }

        if (empty($schemas)) {
            $this->fileSystem->write($mainConfigPath, Yaml::dump($config, 4, 2));
            return;
        }

        $includes = [];
        $settingsDir = $configDir . '/' . self::SETTINGS_SUBDIR;

        foreach ($schemas as $schema) {
            $schemaConfig = $this->extractSchemaConfig($config, $schema);

            if (empty($schemaConfig)) {
                continue;
            }

            $relativePath = self::SETTINGS_SUBDIR . '/' . $schema . '.yaml';
            $schemaFilePath = $configDir . '/' . $relativePath;

            if (!$this->fileSystem->exists($settingsDir)) {
                $this->fileSystem->createDirectory($settingsDir);
            }

            $this->fileSystem->write($schemaFilePath, Yaml::dump($schemaConfig, 4, 2));
            $this->logger->info("Создан файл конфигурации схемы: {$relativePath}");

            // Префикс ./ — чтобы PhpStorm/IDE распознавали значение как ссылку на файл
            // (Ctrl+B / Cmd+B прыгает в файл). На резолв путей в загрузчике не влияет.
            $includes[$schema] = './' . $relativePath;
        }

        // Build main config with includes
        $mainConfig = [];

        if (!empty($includes)) {
            $mainConfig[DumpConfig::KEY_INCLUDES] = $includes;
        }

        // Keep connections section in main config, but split their schemas too
        if (isset($config[DumpConfig::KEY_CONNECTIONS]) && is_array($config[DumpConfig::KEY_CONNECTIONS])) {
            $connConfigs = [];
            foreach ($config[DumpConfig::KEY_CONNECTIONS] as $connName => $connData) {
                if (!is_array($connData)) {
                    continue;
                }

                $connSchemas = $this->collectSchemas($connData);
                $connIncludes = [];
                $connRelDir = self::SETTINGS_SUBDIR . '/' . $connName;
                $connDir = $configDir . '/' . $connRelDir;

                foreach ($connSchemas as $schema) {
                    $schemaConfig = $this->extractSchemaConfig($connData, $schema);
                    if (empty($schemaConfig)) {
                        continue;
                    }

                    $relativePath = $connRelDir . '/' . $schema . '.yaml';
                    $schemaFilePath = $configDir . '/' . $relativePath;

                    if (!$this->fileSystem->exists($connDir)) {
                        $this->fileSystem->createDirectory($connDir);
                    }

                    $this->fileSystem->write($schemaFilePath, Yaml::dump($schemaConfig, 4, 2));
                    $this->logger->info("Создан файл конфигурации: {$relativePath}");

                    $connIncludes[$schema] = './' . $relativePath;
                }

                if (!empty($connIncludes)) {
                    $connConfigs[$connName] = [DumpConfig::KEY_INCLUDES => $connIncludes];
                }
            }

            if (!empty($connConfigs)) {
                $mainConfig[DumpConfig::KEY_CONNECTIONS] = $connConfigs;
            }
        }

        $this->fileSystem->write($mainConfigPath, Yaml::dump($mainConfig, 4, 2));
    }

    /**
     * Collect all unique schema names from config.
     *
     * @param array<string, mixed> $config
     * @return array<string>
     */
    private function collectSchemas(array $config): array
    {
        $schemas = [];

        if (isset($config[DumpConfig::KEY_FULL_EXPORT]) && is_array($config[DumpConfig::KEY_FULL_EXPORT])) {
            foreach (array_keys($config[DumpConfig::KEY_FULL_EXPORT]) as $schema) {
                $schemas[$schema] = true;
            }
        }

        if (isset($config[DumpConfig::KEY_PARTIAL_EXPORT]) && is_array($config[DumpConfig::KEY_PARTIAL_EXPORT])) {
            foreach (array_keys($config[DumpConfig::KEY_PARTIAL_EXPORT]) as $schema) {
                $schemas[$schema] = true;
            }
        }

        if (isset($config[DumpConfig::KEY_FAKER]) && is_array($config[DumpConfig::KEY_FAKER])) {
            foreach (array_keys($config[DumpConfig::KEY_FAKER]) as $schema) {
                $schemas[$schema] = true;
            }
        }

        return array_keys($schemas);
    }

    /**
     * Защита от path traversal: имена схем/подключений должны быть простыми идентификаторами.
     */
    private function validateName(string $context, string $name): void
    {
        // Unicode-буквы/цифры разрешены (кириллица в именах схем), но разделители,
        // точки и '..' — нет: имя становится сегментом пути к файлу конфига схемы.
        if ($name === '' || !preg_match('/^[\p{L}_][\p{L}\p{N}_$]*$/u', $name)) {
            throw new \InvalidArgumentException(
                sprintf('ConfigSplitter: invalid %s name "%s" (must match [\\p{L}_][\\p{L}\\p{N}_$]*)', $context, $name)
            );
        }
    }

    /**
     * Extract config for a specific schema.
     *
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private function extractSchemaConfig(array $config, string $schema): array
    {
        $schemaConfig = [];

        if (isset($config[DumpConfig::KEY_FULL_EXPORT][$schema])) {
            $schemaConfig[DumpConfig::KEY_FULL_EXPORT] = $config[DumpConfig::KEY_FULL_EXPORT][$schema];
        }

        if (isset($config[DumpConfig::KEY_PARTIAL_EXPORT][$schema])) {
            $schemaConfig[DumpConfig::KEY_PARTIAL_EXPORT] = $config[DumpConfig::KEY_PARTIAL_EXPORT][$schema];
        }

        if (isset($config[DumpConfig::KEY_FAKER][$schema])) {
            $schemaConfig[DumpConfig::KEY_FAKER] = $config[DumpConfig::KEY_FAKER][$schema];
        }

        return $schemaConfig;
    }
}
