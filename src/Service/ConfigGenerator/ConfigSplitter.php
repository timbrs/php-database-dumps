<?php

namespace Timbrs\DatabaseDumps\Service\ConfigGenerator;

use Timbrs\DatabaseDumps\Config\DumpConfig;
use Timbrs\DatabaseDumps\Contract\FileSystemInterface;
use Timbrs\DatabaseDumps\Contract\LoggerInterface;
use Symfony\Component\Yaml\Yaml;

class ConfigSplitter
{
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

        if (empty($schemas)) {
            $this->fileSystem->write($mainConfigPath, Yaml::dump($config, 4, 2));
            return;
        }

        $includes = [];

        foreach ($schemas as $schema) {
            $schemaConfig = $this->extractSchemaConfig($config, $schema);

            if (empty($schemaConfig)) {
                continue;
            }

            $schemaFileName = $schema . '.yaml';
            $schemaFilePath = $configDir . '/' . $schemaFileName;

            $this->fileSystem->write($schemaFilePath, Yaml::dump($schemaConfig, 4, 2));
            $this->logger->info("Создан файл конфигурации схемы: {$schemaFileName}");

            $includes[$schema] = $schemaFileName;
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
                $connDir = $configDir . '/' . $connName;

                foreach ($connSchemas as $schema) {
                    $schemaConfig = $this->extractSchemaConfig($connData, $schema);
                    if (empty($schemaConfig)) {
                        continue;
                    }

                    $schemaFileName = $schema . '.yaml';
                    $schemaFilePath = $connDir . '/' . $schemaFileName;

                    if (!$this->fileSystem->exists($connDir)) {
                        $this->fileSystem->createDirectory($connDir);
                    }

                    $this->fileSystem->write($schemaFilePath, Yaml::dump($schemaConfig, 4, 2));
                    $this->logger->info("Создан файл конфигурации: {$connName}/{$schemaFileName}");

                    $connIncludes[$schema] = $connName . '/' . $schemaFileName;
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
