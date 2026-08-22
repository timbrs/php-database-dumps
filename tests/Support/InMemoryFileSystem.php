<?php

namespace Timbrs\DatabaseDumps\Tests\Support;

use Timbrs\DatabaseDumps\Contract\FileSystemInterface;

/**
 * Файловая система в памяти для тестов аудита: конфиг и слепок пишутся в массив,
 * поэтому тест не зависит ни от временных каталогов, ни от разделителя путей.
 */
class InMemoryFileSystem implements FileSystemInterface
{
    /** @var array<string, string> путь (через «/») => содержимое */
    private $files = [];

    /**
     * @param array<string, string> $files
     */
    public function __construct(array $files = [])
    {
        foreach ($files as $path => $content) {
            $this->files[$this->normalize((string) $path)] = $content;
        }
    }

    /**
     * @return array<string, string>
     */
    public function all(): array
    {
        return $this->files;
    }

    public function exists(string $path): bool
    {
        $normalized = $this->normalize($path);
        return isset($this->files[$normalized]) || $this->isDirectory($path);
    }

    public function read(string $path): string
    {
        $normalized = $this->normalize($path);
        if (!isset($this->files[$normalized])) {
            throw new \RuntimeException('Файл не найден: ' . $path);
        }
        return $this->files[$normalized];
    }

    public function write(string $path, string $content): void
    {
        $this->files[$this->normalize($path)] = $content;
    }

    public function writeAtomic(string $path, string $content): void
    {
        $this->write($path, $content);
    }

    /**
     * @return array<string> пути файлов, лежащих непосредственно в каталоге
     */
    public function findFiles(string $directory, string $pattern): array
    {
        $dir = rtrim($this->normalize($directory), '/');
        $regex = '/^' . str_replace(['\*', '\?'], ['.*', '.'], preg_quote($pattern, '/')) . '$/';

        $found = [];
        foreach (array_keys($this->files) as $path) {
            if (strpos($path, $dir . '/') !== 0) {
                continue;
            }
            $relative = substr($path, strlen($dir) + 1);
            if (strpos($relative, '/') !== false) {
                continue;
            }
            if (preg_match($regex, $relative) === 1) {
                $found[] = $path;
            }
        }
        sort($found);

        return $found;
    }

    public function createDirectory(string $path): void
    {
        // Каталоги в памяти не нужны: они выводятся из путей файлов.
    }

    public function isDirectory(string $path): bool
    {
        $dir = rtrim($this->normalize($path), '/') . '/';
        foreach (array_keys($this->files) as $existing) {
            if (strpos($existing, $dir) === 0) {
                return true;
            }
        }
        return false;
    }

    public function append(string $path, string $content): void
    {
        $normalized = $this->normalize($path);
        $this->files[$normalized] = (isset($this->files[$normalized]) ? $this->files[$normalized] : '') . $content;
    }

    public function getFileSize(string $path): int
    {
        return strlen($this->read($path));
    }

    public function delete(string $path): void
    {
        unset($this->files[$this->normalize($path)]);
    }

    private function normalize(string $path): string
    {
        return str_replace('\\', '/', $path);
    }
}
