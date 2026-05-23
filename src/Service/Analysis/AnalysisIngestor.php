<?php

namespace Timbrs\DatabaseDumps\Service\Analysis;

use Timbrs\DatabaseDumps\Contract\FileSystemInterface;
use Timbrs\DatabaseDumps\Contract\LoggerInterface;

/**
 * Поглощает вывод агента OPENCODE: читает database/analysis/out/*.json,
 * валидирует против контракта (output_schema.json), объединяет чанки и
 * нормализует в структуру для ConfigEnricher.
 *
 * Стратегия устойчивая: не парсим event-stream opencode, а читаем
 * JSON-деливераблы. Каждый файл может содержать любой набор ключей
 * (relationships / columns / criteria); один прогон по схеме пишет
 * частичный out/<schema>.json — ингест объединяет.
 */
class AnalysisIngestor
{
    private const IDENTIFIER_REGEX = '/^[A-Za-z_][A-Za-z0-9_$]*$/';

    /** @var FileSystemInterface */
    private $fileSystem;

    /** @var LoggerInterface */
    private $logger;

    public function __construct(FileSystemInterface $fileSystem, LoggerInterface $logger)
    {
        $this->fileSystem = $fileSystem;
        $this->logger = $logger;
    }

    /**
     * Прочитать и нормализовать все out/*.json.
     *
     * @return array{cascade_from: array<int, array<string, mixed>>, sample_criteria: array<int, array<string, mixed>>, relationships: array<int, array<string, mixed>>, columns: array<int, array<string, mixed>>, files: array<int, string>}
     */
    public function ingest(string $outDir): array
    {
        $result = [
            'cascade_from' => [],
            'sample_criteria' => [],
            'relationships' => [],
            'columns' => [],
            'files' => [],
        ];

        if (!$this->fileSystem->isDirectory($outDir)) {
            $this->logger->warning("Каталог вывода OPENCODE не найден: {$outDir}");
            return $result;
        }

        $files = $this->fileSystem->findFiles($outDir, '*.json');
        foreach ($files as $file) {
            // Устойчивость: сбой чтения/декода одного файла не должен валить весь ингест.
            try {
                $content = $this->fileSystem->read($file);
            } catch (\Throwable $e) {
                $this->logger->warning("Не удалось прочитать файл вывода OPENCODE: {$file}");
                continue;
            }

            $data = json_decode($content, true);
            if (!is_array($data)) {
                $this->logger->warning("Пропущен невалидный JSON: {$file}");
                continue;
            }
            $result['files'][] = $file;

            $this->ingestRelationships($data, $result);
            $this->ingestColumns($data, $result);
            $this->ingestCriteria($data, $result);
        }

        $this->logger->info(sprintf(
            'Поглощено из OPENCODE: %d связей (cascade_from), %d критериев, %d записей column_usage из %d файлов',
            count($result['cascade_from']),
            count($result['sample_criteria']),
            count($result['columns']),
            count($result['files'])
        ));

        return $result;
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $result
     */
    private function ingestRelationships(array $data, array &$result): void
    {
        if (!isset($data['relationships']) || !is_array($data['relationships'])) {
            return;
        }
        foreach ($data['relationships'] as $rel) {
            if (!is_array($rel)) {
                continue;
            }
            $source = $this->splitTable($rel['source_table'] ?? null);
            $target = $this->toScalarString($rel['target_table'] ?? null);
            $fkColumn = $this->toScalarString($rel['source_column'] ?? null);
            $parentColumn = $this->toScalarString($rel['target_column'] ?? null);

            if ($source === null || !$this->isQualifiedTable($target)
                || !$this->isIdentifier($fkColumn) || !$this->isIdentifier($parentColumn)) {
                $this->logger->warning('Пропущена некорректная relationship из OPENCODE.');
                continue;
            }

            $result['relationships'][] = $rel;
            $result['cascade_from'][] = [
                'schema' => $source['schema'],
                'table' => $source['table'],
                'parent' => $target,
                'fk_column' => $fkColumn,
                'parent_column' => $parentColumn,
                'source' => 'code',
                'confidence' => $this->normalizeConfidence($rel['confidence'] ?? null),
                'kind' => isset($rel['kind']) && is_scalar($rel['kind']) ? (string) $rel['kind'] : null,
            ];
        }
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $result
     */
    private function ingestColumns(array $data, array &$result): void
    {
        if (!isset($data['columns']) || !is_array($data['columns'])) {
            return;
        }
        foreach ($data['columns'] as $col) {
            if (is_array($col) && isset($col['table'], $col['column'])) {
                $result['columns'][] = $col;
            }
        }
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $result
     */
    private function ingestCriteria(array $data, array &$result): void
    {
        if (!isset($data['criteria']) || !is_array($data['criteria'])) {
            return;
        }
        foreach ($data['criteria'] as $crit) {
            if (!is_array($crit)) {
                continue;
            }
            $table = $this->splitTable($crit['table'] ?? null);
            $name = $this->toScalarString($crit['name'] ?? null);
            $where = $this->toScalarString($crit['sql_where'] ?? null);

            if ($table === null || !$this->isIdentifier($name) || $where === '') {
                $this->logger->warning('Пропущен некорректный criterion из OPENCODE.');
                continue;
            }

            $result['sample_criteria'][] = [
                'schema' => $table['schema'],
                'table' => $table['table'],
                'name' => $name,
                'where' => $where,
                'limit' => $this->normalizeLimit($crit['limit'] ?? null),
                'source' => 'code',
                'confidence' => $this->normalizeConfidence($crit['confidence'] ?? null),
            ];
        }
    }

    /**
     * Привести скалярное значение к строке; нескалярные (массивы/объекты) → ''.
     *
     * @param mixed $value
     */
    private function toScalarString($value): string
    {
        if (is_string($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        return '';
    }

    /**
     * Нормализовать confidence в диапазон 0..100 (контракт output_schema.json); иначе null.
     *
     * @param mixed $value
     */
    private function normalizeConfidence($value): ?int
    {
        if (!is_int($value) && !(is_string($value) && ctype_digit($value))) {
            return null;
        }
        $int = (int) $value;
        if ($int < 0) {
            return 0;
        }
        if ($int > 100) {
            return 100;
        }
        return $int;
    }

    /**
     * Нормализовать limit: целое >= 1, иначе null (применится дефолт в ConfigEnricher).
     *
     * @param mixed $value
     */
    private function normalizeLimit($value): ?int
    {
        if (!is_int($value) && !(is_string($value) && ctype_digit($value))) {
            return null;
        }
        $int = (int) $value;
        return $int >= 1 ? $int : null;
    }

    /**
     * @param mixed $value
     * @return array{schema: string, table: string}|null
     */
    private function splitTable($value): ?array
    {
        if (!is_string($value) || substr_count($value, '.') !== 1) {
            return null;
        }
        [$schema, $table] = explode('.', $value, 2);
        if (!$this->isIdentifier($schema) || !$this->isIdentifier($table)) {
            return null;
        }
        return ['schema' => $schema, 'table' => $table];
    }

    /**
     * @param mixed $value
     */
    private function isQualifiedTable($value): bool
    {
        return $this->splitTable($value) !== null;
    }

    /**
     * @param mixed $value
     */
    private function isIdentifier($value): bool
    {
        return is_string($value) && $value !== '' && (bool) preg_match(self::IDENTIFIER_REGEX, $value);
    }
}
