<?php

namespace Timbrs\DatabaseDumps\Util;

use Symfony\Component\Finder\Finder;
use Timbrs\DatabaseDumps\Contract\FileSystemInterface;

/**
 * Реализация FileSystemInterface.
 *
 * - createDirectory защищён от TOCTOU (использует @mkdir + is_dir).
 * - writeAtomic пишет в *.tmp и делает rename (атомарная операция на одной FS).
 * - chmod ограничивает права дампов 0640 (доступ только владельцу+группе).
 */
class FileSystemHelper implements FileSystemInterface
{
    /** Права на новые dump-файлы (читать может только владелец+группа) */
    private const FILE_MODE = 0640;
    /** Права на новые директории */
    private const DIR_MODE = 0750;

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
        @chmod($path, self::FILE_MODE);
    }

    public function writeAtomic(string $path, string $content): void
    {
        $tmpPath = $path . '.tmp.' . bin2hex(random_bytes(4));

        $result = file_put_contents($tmpPath, $content);
        if ($result === false) {
            throw new \RuntimeException("Не удалось записать временный файл: {$tmpPath}");
        }

        @chmod($tmpPath, self::FILE_MODE);

        if (!@rename($tmpPath, $path)) {
            @unlink($tmpPath);
            throw new \RuntimeException("Не удалось переименовать {$tmpPath} → {$path}");
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
        // mkdir с -p семантикой + защита от TOCTOU.
        // @ подавляет ворнинг "директория уже есть"; проверяем is_dir для итога.
        if (!@mkdir($path, self::DIR_MODE, true) && !is_dir($path)) {
            $err = error_get_last();
            $detail = $err !== null && isset($err['message']) ? ': ' . $err['message'] : '';
            throw new \RuntimeException("Не удалось создать директорию: {$path}{$detail}");
        }
    }

    public function isDirectory(string $path): bool
    {
        return is_dir($path);
    }

    public function append(string $path, string $content): void
    {
        $isNew = !$this->exists($path);

        $result = file_put_contents($path, $content, FILE_APPEND);
        if ($result === false) {
            throw new \RuntimeException("Не удалось дописать в файл: {$path}");
        }

        if ($isNew) {
            @chmod($path, self::FILE_MODE);
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

    public function delete(string $path): void
    {
        if (!$this->exists($path)) {
            return;
        }
        if (!@unlink($path)) {
            throw new \RuntimeException("Не удалось удалить файл: {$path}");
        }
    }
}
