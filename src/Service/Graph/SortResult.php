<?php

namespace Timbrs\DatabaseDumps\Service\Graph;

/**
 * Результат топологической сортировки с информацией о разорванных циклах
 */
class SortResult
{
    /** @var array<string> Отсортированные узлы */
    private $sorted;

    /** @var array<int, array{source: string, target: string, source_column: string, target_column: string}> Отложенные рёбра (разорванные для устранения цикла) */
    private $deferredEdges;

    /**
     * @param array<string> $sorted
     * @param array<int, array{source: string, target: string, source_column: string, target_column: string}> $deferredEdges
     */
    public function __construct(array $sorted, array $deferredEdges = [])
    {
        $this->sorted = $sorted;
        $this->deferredEdges = $deferredEdges;
    }

    /**
     * @return array<string>
     */
    public function getSorted(): array
    {
        return $this->sorted;
    }

    /**
     * @return array<int, array{source: string, target: string, source_column: string, target_column: string}>
     */
    public function getDeferredEdges(): array
    {
        return $this->deferredEdges;
    }

    public function hasDeferredEdges(): bool
    {
        return !empty($this->deferredEdges);
    }

    /**
     * Получить deferred-рёбра для конкретного узла (таблицы)
     *
     * @return array<int, array{source: string, target: string, source_column: string, target_column: string}>
     */
    public function getDeferredEdgesForNode(string $node): array
    {
        $result = [];
        foreach ($this->deferredEdges as $edge) {
            if ($edge['source'] === $node) {
                $result[] = $edge;
            }
        }
        return $result;
    }
}
