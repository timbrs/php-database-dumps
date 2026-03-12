<?php

namespace Timbrs\DatabaseDumps\Util;

use Timbrs\DatabaseDumps\Contract\FileSystemInterface;
use Symfony\Component\Finder\Finder;

/**
 * Реализация FileSystemInterface с использованием Symfony Finder
 */
class FileSystemHelper implements FileSystemInterface
{
    public function exists(string $path): bool
    {
        return file_exists($path);
    }

    public function read(string $path): string
    {
        if (!$this->exists($path)) {
            throw new \RuntimeException("Файл не найден: {$path}");
        }

        $content = file_get_contents($path);

        if ($content === false) {
            throw new \RuntimeException("Не удалось прочитать файл: {$path}");
        }

        return $content;
    }

    public function write(string $path, string $content): void
    {
        $result = file_put_contents($path, $content);

        if ($result === false) {
            throw new \RuntimeException("Не удалось записать файл: {$path}");
        }
    }

    public function findFiles(string $directory, string $pattern): array
    {
        if (!$this->isDirectory($directory)) {
            return [];
        }

        $finder = new Finder();
        $finder->files()->in($directory)->name($pattern)->sortByName();

        $files = [];
        foreach ($finder as $file) {
            $files[] = $file->getRealPath();
        }

        return $files;
    }

    public function createDirectory(string $path): void
    {
        if (!$this->exists($path)) {
            $result = mkdir($path, 0755, true);

            if (!$result) {
                throw new \RuntimeException("Не удалось создать директорию: {$path}");
            }
        }
    }

    public function isDirectory(string $path): bool
    {
        return is_dir($path);
    }

    public function append(string $path, string $content): void
    {
        $result = file_put_contents($path, $content, FILE_APPEND);

        if ($result === false) {
            throw new \RuntimeException("Не удалось дописать в файл: {$path}");
        }
    }

    public function getFileSize(string $path): int
    {
        if (!$this->exists($path)) {
            throw new \RuntimeException("Файл не найден: {$path}");
        }

        $size = filesize($path);

        if ($size === false) {
            throw new \RuntimeException("Не удалось получить размер файла: {$path}");
        }

        return $size;
    }
}
