<?php

namespace Timbrs\DatabaseDumps\Util;

use Symfony\Component\Yaml\Yaml;
use Timbrs\DatabaseDumps\Config\DumpConfig;
use Timbrs\DatabaseDumps\Config\FakerConfig;
use Timbrs\DatabaseDumps\Contract\ConfigLoaderInterface;
use Timbrs\DatabaseDumps\Contract\LoggerInterface;
use Timbrs\DatabaseDumps\Exception\ConfigNotFoundException;

/**
 * Загрузчик конфигурации из YAML файла.
 *
 * Защиты:
 * - includes: запрещены абсолютные пути и пути с .. (path traversal protection).
 *   Все включаемые файлы должны быть в поддереве директории основного конфига.
 * - Yaml::parse вызывается без флага PARSE_OBJECT — встроенная защита от
 *   гаджет-десериализации.
 *
 * Устойчивость: ОТСУТСТВУЮЩИЙ include-файл не валит загрузку целиком (иначе одна пропавшая
 * пер-схемная настройка бракует весь конфиг → в Symfony это роняет регистрацию всех
 * DB-команд). Такой include пропускается с warning; сама схема просто не попадёт в дамп.
 */
class YamlConfigLoader implements ConfigLoaderInterface
{
    /** @var LoggerInterface|null */
    private $logger;

    public function __construct(LoggerInterface $logger = null)
    {
        $this->logger = $logger;
    }

    public function load(string $path): DumpConfig
    {
        if (!file_exists($path)) {
            throw ConfigNotFoundException::fileNotFound($path);
        }

        $data = Yaml::parseFile($path);
        if (!is_array($data)) {
            $data = [];
        }

        $configDir = $this->resolveRealDir($path);

        if (isset($data[DumpConfig::KEY_INCLUDES]) && is_array($data[DumpConfig::KEY_INCLUDES])) {
            $data = $this->resolveIncludes($data, $configDir);
        }

        if (isset($data[DumpConfig::KEY_CONNECTIONS]) && is_array($data[DumpConfig::KEY_CONNECTIONS])) {
            foreach ($data[DumpConfig::KEY_CONNECTIONS] as $connName => &$connData) {
                if (is_array($connData) && isset($connData[DumpConfig::KEY_INCLUDES])
                    && is_array($connData[DumpConfig::KEY_INCLUDES])) {
                    $connData = $this->resolveIncludes($connData, $configDir);
                }
            }
            unset($connData);
        }

        $fakerConfig = null;
        if (isset($data[DumpConfig::KEY_FAKER]) && is_array($data[DumpConfig::KEY_FAKER])) {
            $fakerConfig = new FakerConfig($data[DumpConfig::KEY_FAKER]);
        }

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
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function resolveIncludes(array $data, string $configDir): array
    {
        foreach ($data[DumpConfig::KEY_INCLUDES] as $schema => $relativePath) {
            if (!is_string($relativePath)) {
                throw new \InvalidArgumentException(
                    "Include path for schema '{$schema}' must be string"
                );
            }

            $includePath = $this->resolveSafeIncludePath($configDir, $relativePath);

            if (!file_exists($includePath)) {
                // Пропавший include не должен ронять весь конфиг (и все команды в Symfony) —
                // пропускаем схему с предупреждением. Основной конфиг отсутствует → см. load() (throw).
                $this->warnMissingInclude((string) $schema, $includePath);
                continue;
            }
            $schemaData = Yaml::parseFile($includePath);
            if (!is_array($schemaData)) {
                continue;
            }

            if (isset($schemaData[DumpConfig::KEY_FULL_EXPORT])) {
                $data[DumpConfig::KEY_FULL_EXPORT][$schema] = $schemaData[DumpConfig::KEY_FULL_EXPORT];
            }
            if (isset($schemaData[DumpConfig::KEY_PARTIAL_EXPORT])) {
                $data[DumpConfig::KEY_PARTIAL_EXPORT][$schema] = $schemaData[DumpConfig::KEY_PARTIAL_EXPORT];
            }
            if (isset($schemaData[DumpConfig::KEY_FAKER])) {
                $data[DumpConfig::KEY_FAKER][$schema] = $schemaData[DumpConfig::KEY_FAKER];
            }
        }
        unset($data[DumpConfig::KEY_INCLUDES]);
        return $data;
    }

    /**
     * Сообщить о пропущенном include-файле: через логгер (если есть), иначе E_USER_WARNING —
     * чтобы предупреждение было видно и в консоли, и в логах, даже когда логгер не прокинут.
     *
     * @param string $schema имя схемы из ключа includes
     */
    private function warnMissingInclude(string $schema, string $includePath): void
    {
        $message = sprintf(
            "Пропущен отсутствующий include-файл конфигурации для схемы '%s': %s — схема не войдёт "
            . 'в дамп. Уберите запись из includes основного конфига или восстановите файл.',
            $schema,
            $includePath
        );
        if ($this->logger !== null) {
            $this->logger->warning($message);
        } else {
            trigger_error($message, E_USER_WARNING);
        }
    }

    /**
     * Защита от path traversal: include-путь должен оставаться в configDir
     * (или его поддиректории). Абсолютные пути запрещены.
     */
    private function resolveSafeIncludePath(string $configDir, string $relativePath): string
    {
        if ($relativePath === '') {
            throw new \InvalidArgumentException('Include path must be non-empty');
        }

        // Запрет абсолютных путей (Unix /foo, Windows C:\foo, UNC \\server\share, scheme://)
        $isAbs = (substr($relativePath, 0, 1) === '/')
            || preg_match('#^[a-zA-Z]:[\\\\/]#', $relativePath)
            || preg_match('#^\\\\#', $relativePath)
            || preg_match('#^[a-z][a-z0-9+\-.]*://#i', $relativePath);

        if ($isAbs) {
            throw new \InvalidArgumentException(
                "Absolute paths are forbidden in includes (got: {$relativePath})"
            );
        }

        if (strpos($relativePath, '..') !== false) {
            throw new \InvalidArgumentException(
                "Path traversal ('..') is forbidden in includes (got: {$relativePath})"
            );
        }

        $combined = $configDir . DIRECTORY_SEPARATOR . $relativePath;
        $real = realpath($combined);
        if ($real === false) {
            // Файл может не существовать ещё — проверим на отсутствие traversal в нормализованном пути.
            $real = $this->normalizePath($combined);
        }

        $configReal = realpath($configDir);
        if ($configReal === false) {
            $configReal = $this->normalizePath($configDir);
        }

        // Проверяем что итоговый путь начинается с configDir
        $configRealNorm = rtrim($configReal, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        if (strpos($real . DIRECTORY_SEPARATOR, $configRealNorm) !== 0) {
            throw new \InvalidArgumentException(
                "Include path '{$relativePath}' is outside config directory"
            );
        }

        return $real;
    }

    private function resolveRealDir(string $configPath): string
    {
        $dir = dirname($configPath);
        $real = realpath($dir);
        return $real !== false ? $real : $this->normalizePath($dir);
    }

    private function normalizePath(string $path): string
    {
        // Простая нормализация без обращения к ФС.
        $path = str_replace('\\', '/', $path);
        $parts = explode('/', $path);
        $stack = [];
        foreach ($parts as $segment) {
            if ($segment === '.' || $segment === '') {
                continue;
            }
            if ($segment === '..') {
                array_pop($stack);
                continue;
            }
            $stack[] = $segment;
        }
        $prefix = (strlen($path) > 0 && $path[0] === '/') ? '/' : '';
        return $prefix . implode(DIRECTORY_SEPARATOR, $stack);
    }
}
