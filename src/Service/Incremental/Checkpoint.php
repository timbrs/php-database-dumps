<?php

namespace Timbrs\DatabaseDumps\Service\Incremental;

use Timbrs\DatabaseDumps\Contract\FileSystemInterface;

/**
 * Отметка «здесь дамп был признан годным»: от неё считается, что перепроверять.
 *
 * Хранит не только время, но и хеши того, из чего складывалось решение — конфиг таблицы,
 * её колонки, её коды. Времени мало: конфиг могли поправить и вернуть, а в базе за неделю
 * мог появиться новый `status_id` без единой миграции. Хеш это ловит, время — нет.
 *
 * PHP 7.2-совместимо.
 */
class Checkpoint
{
    public const FILE = 'checkpoint.json';

    /** @var array<string, mixed> */
    private $data;

    /**
     * @param array<string, mixed> $data
     */
    public function __construct(array $data = [])
    {
        $this->data = $data + [
            'created_at' => null,
            'newest_migration' => null,
            'inventory_generated_at' => null,
            'head_commit' => null,
            'tables' => [],
        ];
    }

    /**
     * @param array<string, array<string, mixed>> $tables «schema.table» => хеши
     */
    public static function create(
        ?string $newestMigration,
        ?string $inventoryGeneratedAt,
        ?string $headCommit,
        array $tables
    ): self {
        ksort($tables);

        return new self([
            'created_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'newest_migration' => $newestMigration,
            'inventory_generated_at' => $inventoryGeneratedAt,
            'head_commit' => $headCommit,
            'tables' => $tables,
        ]);
    }

    /**
     * Прочитать отметку. Нет файла или он битый — `null`: это «первый прогон»,
     * а не ошибка, и звать в таком случае надо полный цикл.
     */
    public static function load(FileSystemInterface $fileSystem, string $path): ?self
    {
        if (!$fileSystem->exists($path)) {
            return null;
        }
        try {
            $decoded = json_decode($fileSystem->read($path), true);
        } catch (\Throwable $e) {
            return null;
        }
        if (!is_array($decoded)) {
            return null;
        }
        if (!isset($decoded['tables']) || !is_array($decoded['tables'])) {
            $decoded['tables'] = [];
        }

        return new self($decoded);
    }

    public function save(FileSystemInterface $fileSystem, string $path): void
    {
        $flags = JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
        $json = json_encode($this->data, $flags);
        $fileSystem->write($path, $json === false ? '{}' : $json);
    }

    public function createdAt(): ?string
    {
        return $this->data['created_at'] === null ? null : (string) $this->data['created_at'];
    }

    public function newestMigration(): ?string
    {
        return $this->data['newest_migration'] === null ? null : (string) $this->data['newest_migration'];
    }

    public function inventoryGeneratedAt(): ?string
    {
        return $this->data['inventory_generated_at'] === null
            ? null
            : (string) $this->data['inventory_generated_at'];
    }

    public function headCommit(): ?string
    {
        return $this->data['head_commit'] === null ? null : (string) $this->data['head_commit'];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function tables(): array
    {
        $tables = [];
        foreach ($this->data['tables'] as $key => $entry) {
            if (is_array($entry)) {
                $tables[(string) $key] = $entry;
            }
        }

        return $tables;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function table(string $key): ?array
    {
        $tables = $this->tables();

        return isset($tables[$key]) ? $tables[$key] : null;
    }

    /**
     * Хеш из отметки. Ключа нет — `null`: старая отметка без этого сенсора не должна
     * объявлять все таблицы грязными.
     */
    public function hash(string $key, string $field): ?string
    {
        $entry = $this->table($key);
        if ($entry === null || !isset($entry[$field]) || !is_string($entry[$field])) {
            return null;
        }

        return $entry[$field];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->data;
    }
}
