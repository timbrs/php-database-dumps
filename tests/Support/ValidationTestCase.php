<?php

namespace Timbrs\DatabaseDumps\Tests\Support;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;
use Timbrs\DatabaseDumps\Service\Validation\AuditContext;
use Timbrs\DatabaseDumps\Service\Validation\ConfigDocument;
use Timbrs\DatabaseDumps\Service\Validation\Finding;
use Timbrs\DatabaseDumps\Service\Validation\InventoryReader;

/**
 * Общая обвязка тестов аудита: собрать слепок и конфиг из компактного описания,
 * получить готовый AuditContext и список кодов находок.
 */
abstract class ValidationTestCase extends TestCase
{
    protected const CONFIG_PATH = '/proj/database/dump_config.yaml';
    protected const INVENTORY_PATH = '/proj/database/analysis/schema_inventory.json';
    protected const GENERATED_AT = '2026-01-15T00:00:00Z';

    /** @var InMemoryFileSystem */
    protected $fileSystem;

    /**
     * Слепок из компактного описания:
     *   ['pub' => ['orders' => ['row_count' => 10, 'columns' => ['id' => 'bigint'], …]]]
     *
     * @param array<string, array<string, array<string, mixed>>> $schemas
     */
    protected function inventoryJson(array $schemas, string $generatedAt = self::GENERATED_AT): string
    {
        $out = [];
        foreach ($schemas as $schema => $tables) {
            $outTables = [];
            foreach ($tables as $table => $spec) {
                $columns = [];
                $rawColumns = isset($spec['columns']) && is_array($spec['columns']) ? $spec['columns'] : [];
                foreach ($rawColumns as $name => $type) {
                    $columns[] = ['name' => $name, 'type' => $type, 'nullable' => true];
                }

                $profiles = [];
                $rawProfiles = isset($spec['profiles']) && is_array($spec['profiles']) ? $spec['profiles'] : [];
                foreach ($rawProfiles as $name => $profile) {
                    $profiles[] = array_merge(['column' => $name], $profile);
                }

                $outTables[$table] = [
                    'row_count' => isset($spec['row_count']) ? $spec['row_count'] : 0,
                    'columns' => $columns,
                    'foreign_keys' => isset($spec['foreign_keys']) ? $spec['foreign_keys'] : [],
                    'profiles' => $profiles,
                ];
            }
            $out[$schema] = ['tables' => $outTables];
        }

        $json = json_encode([
            'generated_at' => $generatedAt,
            'database_platform' => 'postgresql',
            'connection' => 'default',
            'schemas' => $out,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $json === false ? '{}' : $json;
    }

    /**
     * Разложить конфиг по пер-схемным файлам, как это делает ConfigSplitter.
     *
     * @param array<string, array<string, mixed>> $bySchema schema => содержимое её файла
     * @return array<string, string> путь => содержимое
     */
    protected function splitConfig(array $bySchema): array
    {
        $includes = [];
        $files = [];
        foreach ($bySchema as $schema => $content) {
            $relative = './dump-settings/' . $schema . '.yaml';
            $includes[$schema] = $relative;
            $files['/proj/database/dump-settings/' . $schema . '.yaml'] = Yaml::dump($content, 6, 2);
        }
        $files[self::CONFIG_PATH] = Yaml::dump(['includes' => $includes], 4, 2);

        return $files;
    }

    /**
     * @param array<string, string> $files
     * @param array<int, string> $schemaFilter
     */
    protected function context(array $files, array $schemaFilter = []): AuditContext
    {
        $this->fileSystem = new InMemoryFileSystem($files);
        $config = ConfigDocument::load($this->fileSystem, self::CONFIG_PATH);
        $inventory = new InventoryReader($this->fileSystem, self::INVENTORY_PATH);

        return new AuditContext($config, $inventory, $schemaFilter);
    }

    /**
     * @param array<int, Finding> $findings
     * @return array<int, string>
     */
    protected function codes(array $findings): array
    {
        $codes = [];
        foreach ($findings as $finding) {
            $codes[] = $finding->getCode();
        }
        sort($codes);
        return $codes;
    }

    /**
     * Первая находка с указанным кодом.
     *
     * @param array<int, Finding> $findings
     */
    protected function firstWithCode(array $findings, string $code): ?Finding
    {
        foreach ($findings as $finding) {
            if ($finding->getCode() === $code) {
                return $finding;
            }
        }
        return null;
    }

    /**
     * @param array<int, Finding> $findings
     */
    protected function countCode(array $findings, string $code): int
    {
        $count = 0;
        foreach ($findings as $finding) {
            if ($finding->getCode() === $code) {
                $count++;
            }
        }
        return $count;
    }
}
