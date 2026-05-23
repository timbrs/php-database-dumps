<?php

namespace Timbrs\DatabaseDumps\Contract;

/**
 * Интерфейс для работы с файловой системой.
 *
 * Абстракция для тестируемости и возможности подмены реализации.
 */
interface FileSystemInterface
{
    public function exists(string $path): bool;

    public function read(string $path): string;

    public function write(string $path, string $content): void;

    /**
     * Атомарная запись файла: данные пишутся во временный файл, затем rename().
     * Гарантирует, что читатели не увидят частично записанный файл.
     */
    public function writeAtomic(string $path, string $content): void;

    /**
     * @return array<string> абсолютные пути к найденным файлам
     */
    public function findFiles(string $directory, string $pattern): array;

    public function createDirectory(string $path): void;

    public function isDirectory(string $path): bool;

    public function append(string $path, string $content): void;

    public function getFileSize(string $path): int;

    /**
     * Удалить файл (если существует).
     */
    public function delete(string $path): void;
}
