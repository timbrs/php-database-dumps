<?php

namespace Timbrs\DatabaseDumps\Config\SettingsFile;

use Timbrs\DatabaseDumps\Contract\FileSystemInterface;
use Timbrs\DatabaseDumps\Contract\SettingsFileInterface;

/**
 * config/database-dumps.php — PHP-файл, возвращающий массив. Формат Laravel:
 * это его родной публикуемый конфиг, значения в нём обёрнуты в env().
 *
 * Для Symfony используется YamlBundleSettingsFile — там настройки идут через DI.
 */
class PhpArraySettingsFile implements SettingsFileInterface
{
    public const RELATIVE_PATH = 'config/database-dumps.php';

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
        $path = $this->path($projectDir);
        if (!$this->fileSystem->exists($path) || !is_file($path)) {
            return null;
        }
        /** @psalm-suppress UnresolvableInclude */
        $data = include $path;

        return is_array($data) ? $data : null;
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

        $this->fileSystem->writeAtomic($path, $this->render($settings));
    }

    /**
     * @param array<string, mixed> $data
     */
    private function render(array $data): string
    {
        $header = "<?php\n\n"
            . "// timbrs/database-dumps — настройки. Подтягивается только вне prod.\n"
            . "// Секреты держите в .env.local: DBDUMP_LLM_TOKEN=... (env перекрывает значения ниже).\n"
            . "// Файл создаётся/обновляется командами configure-llm и prepare-config.\n\n"
            . 'return ';

        return $header . $this->renderValue($data, 0) . ";\n";
    }

    /**
     * @param mixed $value
     */
    private function renderValue($value, int $indent): string
    {
        if (is_array($value)) {
            $pad = str_repeat('    ', $indent + 1);
            $close = str_repeat('    ', $indent);
            $isList = array_keys($value) === range(0, count($value) - 1);
            $lines = [];
            foreach ($value as $k => $v) {
                $prefix = $isList ? '' : $this->renderScalar($k) . ' => ';
                $lines[] = $pad . $prefix . $this->renderValue($v, $indent + 1);
            }
            if (empty($lines)) {
                return '[]';
            }

            return "[\n" . implode(",\n", $lines) . ",\n" . $close . ']';
        }

        return $this->renderScalar($value);
    }

    /**
     * @param mixed $value
     */
    private function renderScalar($value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if ($value === null) {
            return 'null';
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return var_export((string) $value, true);
    }
}
