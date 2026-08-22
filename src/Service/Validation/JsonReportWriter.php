<?php

namespace Timbrs\DatabaseDumps\Service\Validation;

use Timbrs\DatabaseDumps\Contract\FileSystemInterface;

/**
 * Машиночитаемый отчёт аудита.
 *
 * Формат специально плоский: агент, который запускает валидатор, должен уметь взять числа
 * из JSON, а не пересчитывать их по тексту консоли (и не ошибаться при этом).
 */
class JsonReportWriter
{
    /** @var FileSystemInterface */
    private $fileSystem;

    public function __construct(FileSystemInterface $fileSystem)
    {
        $this->fileSystem = $fileSystem;
    }

    /**
     * @param string|null $generatedAt отметка времени отчёта (по умолчанию — сейчас, UTC)
     * @return array<string, mixed>
     */
    public function toArray(AuditResult $result, ?string $generatedAt = null): array
    {
        $meta = $result->getMeta();

        $findings = [];
        foreach ($result->getFindings() as $finding) {
            $findings[] = $finding->toArray();
        }

        return [
            'generated_at' => $generatedAt ?? gmdate('Y-m-d\TH:i:s\Z'),
            'config_path' => isset($meta['config_path']) ? $meta['config_path'] : null,
            'inventory_path' => isset($meta['inventory_path']) ? $meta['inventory_path'] : null,
            'inventory_generated_at' => isset($meta['inventory_generated_at'])
                ? $meta['inventory_generated_at']
                : null,
            'inventory_present' => isset($meta['inventory_present']) ? (bool) $meta['inventory_present'] : false,
            'schema_filter' => isset($meta['schema_filter']) ? $meta['schema_filter'] : [],
            'schemas_checked' => isset($meta['schemas_checked']) ? $meta['schemas_checked'] : [],
            'summary' => [
                'total' => count($result->getFindings()),
                'error' => $result->countBySeverity(Finding::SEVERITY_ERROR),
                'warning' => $result->countBySeverity(Finding::SEVERITY_WARNING),
                'note' => $result->countBySeverity(Finding::SEVERITY_NOTE),
                'fixable' => count($result->fixableFindings()),
                'by_code' => $result->countsByCode(),
            ],
            'coverage' => $result->getCoverage(),
            'findings' => $findings,
        ];
    }

    public function toJson(AuditResult $result, ?string $generatedAt = null): string
    {
        $json = json_encode(
            $this->toArray($result, $generatedAt),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );

        return $json === false ? '{}' : $json;
    }

    /**
     * Записать отчёт, создав каталог при необходимости.
     */
    public function write(string $path, AuditResult $result, ?string $generatedAt = null): void
    {
        $dir = dirname($path);
        if ($dir !== '' && !$this->fileSystem->isDirectory($dir)) {
            $this->fileSystem->createDirectory($dir);
        }
        $this->fileSystem->writeAtomic($path, $this->toJson($result, $generatedAt));
    }
}
