<?php

namespace Timbrs\DatabaseDumps\Service\Importer;

/**
 * Результат валидации схемы дампа vs БД
 */
class ValidationResult
{
    /** @var array<string> Столбцы, присутствующие в дампе, но отсутствующие в БД */
    private $missingInDb;

    /** @var array<string> Столбцы, присутствующие в БД, но отсутствующие в дампе */
    private $missingInDump;

    /**
     * @param array<string> $missingInDb
     * @param array<string> $missingInDump
     */
    public function __construct(array $missingInDb = [], array $missingInDump = [])
    {
        $this->missingInDb = $missingInDb;
        $this->missingInDump = $missingInDump;
    }

    /**
     * @return array<string>
     */
    public function getMissingInDb(): array
    {
        return $this->missingInDb;
    }

    /**
     * @return array<string>
     */
    public function getMissingInDump(): array
    {
        return $this->missingInDump;
    }

    public function isValid(): bool
    {
        return empty($this->missingInDb);
    }

    /**
     * Сформировать текстовое описание расхождений
     */
    public function getDescription(): string
    {
        $parts = [];

        if (!empty($this->missingInDb)) {
            $parts[] = 'Столбцы из дампа отсутствуют в БД: ' . implode(', ', $this->missingInDb);
        }

        if (!empty($this->missingInDump)) {
            $parts[] = 'Новые столбцы в БД (отсутствуют в дампе): ' . implode(', ', $this->missingInDump);
        }

        return implode('. ', $parts);
    }
}
