<?php

namespace Timbrs\DatabaseDumps\Config\SettingsFile;

use Symfony\Component\Yaml\Yaml;
use Timbrs\DatabaseDumps\Contract\FileSystemInterface;
use Timbrs\DatabaseDumps\Contract\SettingsFileInterface;

/**
 * config/packages/database_dumps.yaml — обычный конфиг бандла под корневым ключом
 * `database_dumps:`. Читает его DI-расширение, поэтому здесь живут ВСЕ настройки пакета,
 * а не только те, что писала configure-llm: platform, batch_size, data_dir, llm, opencode.
 *
 * Запись сохраняет ключи, которых пакет не касается (их держит DI-дерево Configuration),
 * и не трогает секреты: токен остаётся в .env.local.
 */
class YamlBundleSettingsFile implements SettingsFileInterface
{
    public const RELATIVE_PATH = 'config/packages/database_dumps.yaml';

    /** Корневой ключ конфига бандла (совпадает с алиасом DatabaseDumpsExtension). */
    public const ROOT_KEY = 'database_dumps';

    /** Отступ вложенности и глубина инлайна для Yaml::dump. */
    private const DUMP_INLINE_LEVEL = 6;
    private const DUMP_INDENT = 4;

    /** @var FileSystemInterface */
    private $fileSystem;

    public function __construct(FileSystemInterface $fileSystem)
    {
        $this->fileSystem = $fileSystem;
    }

    public function path(string $projectDir): string
    {
        return rtrim($projectDir, '/\\') . '/' . self::RELATIVE_PATH;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function read(string $projectDir): ?array
    {
        $raw = $this->readRoot($projectDir);

        return $raw === null ? null : $raw;
    }

    /**
     * @param array<string, mixed> $settings
     */
    public function write(string $projectDir, array $settings): void
    {
        $path = $this->path($projectDir);
        $dir = dirname($path);
        if (!$this->fileSystem->exists($dir)) {
            $this->fileSystem->createDirectory($dir);
        }

        $existing = $this->readRoot($projectDir);
        $existing = $existing !== null ? $existing : [];

        // Ключи, которыми управляет только DI (platform, batch_size, config_path, …),
        // переживают запись: пишем поверх, а не вместо.
        $merged = array_merge($existing, $settings);

        $document = [self::ROOT_KEY => $merged];
        $body = Yaml::dump($document, self::DUMP_INLINE_LEVEL, self::DUMP_INDENT);

        $header = "# timbrs/database-dumps — настройки бандла. Подтягивается только вне prod.\n"
            . "# Секреты держите в .env.local: DBDUMP_LLM_TOKEN=... (env перекрывает значения ниже).\n"
            . "# Секцию llm создают/обновляют команды configure-llm и prepare-config.\n\n";

        $this->fileSystem->writeAtomic($path, $header . $body);
    }

    /**
     * Содержимое корневого ключа `database_dumps:` или null.
     *
     * @return array<string, mixed>|null
     */
    private function readRoot(string $projectDir): ?array
    {
        $path = $this->path($projectDir);
        if (!$this->fileSystem->exists($path)) {
            return null;
        }

        $parsed = Yaml::parse($this->fileSystem->read($path));
        if (!is_array($parsed) || !isset($parsed[self::ROOT_KEY]) || !is_array($parsed[self::ROOT_KEY])) {
            return null;
        }

        return $parsed[self::ROOT_KEY];
    }
}
