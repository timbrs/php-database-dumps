<?php

namespace Timbrs\DatabaseDumps\Util;

use Timbrs\DatabaseDumps\Config\DumpConfig;
use Timbrs\DatabaseDumps\Config\FakerConfig;
use Timbrs\DatabaseDumps\Contract\ConfigLoaderInterface;
use Timbrs\DatabaseDumps\Exception\ConfigNotFoundException;
use Symfony\Component\Yaml\Yaml;

/**
 * Загрузчик конфигурации из YAML файла
 */
class YamlConfigLoader implements ConfigLoaderInterface
{
    public function load(string $path): DumpConfig
    {
        if (!file_exists($path)) {
            throw ConfigNotFoundException::fileNotFound($path);
        }

        $data = Yaml::parseFile($path);
        if (!is_array($data)) {
            $data = [];
        }

        $configDir = dirname($path);

        // Resolve includes (default connection)
        if (isset($data[DumpConfig::KEY_INCLUDES]) && is_array($data[DumpConfig::KEY_INCLUDES])) {
            $data = $this->resolveIncludes($data, $configDir);
        }

        // Resolve connection includes
        if (isset($data[DumpConfig::KEY_CONNECTIONS]) && is_array($data[DumpConfig::KEY_CONNECTIONS])) {
            foreach ($data[DumpConfig::KEY_CONNECTIONS] as $connName => &$connData) {
                if (is_array($connData) && isset($connData[DumpConfig::KEY_INCLUDES]) && is_array($connData[DumpConfig::KEY_INCLUDES])) {
                    $connData = $this->resolveIncludes($connData, $configDir);
                }
            }
            unset($connData);
        }

        // Parse faker config
        $fakerConfig = null;
        if (isset($data[DumpConfig::KEY_FAKER]) && is_array($data[DumpConfig::KEY_FAKER])) {
            $fakerConfig = new FakerConfig($data[DumpConfig::KEY_FAKER]);
        }

        // Connections
        $connections = [];
        if (isset($data[DumpConfig::KEY_CONNECTIONS]) && is_array($data[DumpConfig::KEY_CONNECTIONS])) {
            foreach ($data[DumpConfig::KEY_CONNECTIONS] as $connName => $connData) {
                if (is_array($connData)) {
                    $connFaker = null;
                    if (isset($connData[DumpConfig::KEY_FAKER]) && is_array($connData[DumpConfig::KEY_FAKER])) {
                        $connFaker = new FakerConfig($connData[DumpConfig::KEY_FAKER]);
                    }
                    $connections[(string) $connName] = new DumpConfig(
                        $connData[DumpConfig::KEY_FULL_EXPORT] ?? [],
                        $connData[DumpConfig::KEY_PARTIAL_EXPORT] ?? [],
                        [],
                        $connFaker
                    );
                }
            }
        }

        // Parse settings
        $settings = [];
        if (isset($data[DumpConfig::KEY_SETTINGS]) && is_array($data[DumpConfig::KEY_SETTINGS])) {
            $settings = $data[DumpConfig::KEY_SETTINGS];
        }

        return new DumpConfig(
            $data[DumpConfig::KEY_FULL_EXPORT] ?? [],
            $data[DumpConfig::KEY_PARTIAL_EXPORT] ?? [],
            $connections,
            $fakerConfig,
            $settings
        );
    }

    /**
     * Resolve includes: merge schema files into flat structure.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function resolveIncludes(array $data, string $configDir): array
    {
        foreach ($data[DumpConfig::KEY_INCLUDES] as $schema => $relativePath) {
            $includePath = $configDir . '/' . $relativePath;
            if (!file_exists($includePath)) {
                throw ConfigNotFoundException::fileNotFound($includePath);
            }
            $schemaData = Yaml::parseFile($includePath);
            if (!is_array($schemaData)) {
                continue;
            }

            if (isset($schemaData[DumpConfig::KEY_FULL_EXPORT])) {
                if (!isset($data[DumpConfig::KEY_FULL_EXPORT])) {
                    $data[DumpConfig::KEY_FULL_EXPORT] = [];
                }
                $data[DumpConfig::KEY_FULL_EXPORT][$schema] = $schemaData[DumpConfig::KEY_FULL_EXPORT];
            }
            if (isset($schemaData[DumpConfig::KEY_PARTIAL_EXPORT])) {
                if (!isset($data[DumpConfig::KEY_PARTIAL_EXPORT])) {
                    $data[DumpConfig::KEY_PARTIAL_EXPORT] = [];
                }
                $data[DumpConfig::KEY_PARTIAL_EXPORT][$schema] = $schemaData[DumpConfig::KEY_PARTIAL_EXPORT];
            }
            if (isset($schemaData[DumpConfig::KEY_FAKER])) {
                if (!isset($data[DumpConfig::KEY_FAKER])) {
                    $data[DumpConfig::KEY_FAKER] = [];
                }
                $data[DumpConfig::KEY_FAKER][$schema] = $schemaData[DumpConfig::KEY_FAKER];
            }
        }
        unset($data[DumpConfig::KEY_INCLUDES]);
        return $data;
    }
}
