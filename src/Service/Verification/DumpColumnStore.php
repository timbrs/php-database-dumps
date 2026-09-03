<?php

namespace Timbrs\DatabaseDumps\Service\Verification;

/**
 * Один проход по каждому файлу дампа для всех проверок сразу.
 *
 * Проверки сначала заявляют, какие колонки каких файлов им нужны и в какой сток класть
 * значения (plan), затем store читает каждый файл ровно один раз и раздаёт значения по
 * стокам (load), после чего проверки смотрят в свои стоки (check). Так 300-мегабайтный
 * каталог дампов читается один раз, а не по разу на проверку и колонку.
 *
 * Запрос «все колонки файла» (requestAll) получает фабрику: имена колонок известны только
 * из шапки INSERT, и стоки для них создаются по ходу чтения.
 */
class DumpColumnStore
{
    /** @var DumpValueReader */
    private $reader;

    /** @var array<string, array<string, array<int, ColumnSinkInterface>>> путь => колонка => стоки */
    private $sinks = [];

    /** @var array<string, array<int, callable>> путь => фабрики стоков на все колонки */
    private $factories = [];

    /** @var array<string, array<string, bool>> путь => колонка => фабрики уже отработали */
    private $factoriesApplied = [];

    /** @var array<string, bool> файлы, для которых нужен хотя бы подсчёт строк */
    private $requested = [];

    /** @var array<string, array{rows: int, found: array<string, bool>, columns: array<int, string>}> */
    private $scanned = [];

    /** @var array<string, bool> */
    private $missing = [];

    public function __construct(DumpValueReader $reader)
    {
        $this->reader = $reader;
    }

    /** Файл нужен хотя бы ради числа строк. */
    public function requestRows(string $path): void
    {
        $this->requested[$path] = true;
    }

    public function request(string $path, string $column, ColumnSinkInterface $sink): void
    {
        $this->requested[$path] = true;
        $this->sinks[$path][$column][] = $sink;
    }

    /**
     * Сток на каждую колонку файла: фабрика получает имя колонки и возвращает сток
     * или null, если колонка проверке не интересна.
     *
     * @param callable(string): (ColumnSinkInterface|null) $factory
     */
    public function requestAll(string $path, callable $factory): void
    {
        $this->requested[$path] = true;
        $this->factories[$path][] = $factory;
    }

    public function load(): void
    {
        foreach (array_keys($this->requested) as $path) {
            if (isset($this->scanned[$path]) || isset($this->missing[$path])) {
                continue;
            }
            if (!is_file($path)) {
                $this->missing[$path] = true;
                continue;
            }
            $this->scanned[$path] = $this->scanFile($path);
        }
    }

    public function isMissing(string $path): bool
    {
        return isset($this->missing[$path]);
    }

    public function rows(string $path): ?int
    {
        return isset($this->scanned[$path]) ? $this->scanned[$path]['rows'] : null;
    }

    public function found(string $path, string $column): bool
    {
        if (!isset($this->scanned[$path])) {
            return false;
        }
        if (isset($this->scanned[$path]['found'][$column])) {
            return $this->scanned[$path]['found'][$column];
        }
        $lower = strtolower($column);
        foreach ($this->scanned[$path]['columns'] as $name) {
            if (strtolower($name) === $lower) {
                return true;
            }
        }

        return false;
    }

    /**
     * Колонки файла по шапке INSERT.
     *
     * @return array<int, string>
     */
    public function columns(string $path): array
    {
        return isset($this->scanned[$path]) ? $this->scanned[$path]['columns'] : [];
    }

    /**
     * @return array{rows: int, found: array<string, bool>, columns: array<int, string>}
     */
    private function scanFile(string $path): array
    {
        $useAll = isset($this->factories[$path]) && $this->factories[$path] !== [];
        $columns = $useAll
            ? [DumpValueReader::ALL_COLUMNS]
            : array_keys($this->sinks[$path] ?? []);

        $onHeader = function (array $headerColumns) use ($path): void {
            foreach ($this->factories[$path] ?? [] as $factory) {
                foreach ($headerColumns as $column) {
                    if (isset($this->factoriesApplied[$path][$column])) {
                        continue;
                    }
                    $this->factoriesApplied[$path][$column] = true;
                    $sink = $factory($column);
                    if ($sink instanceof ColumnSinkInterface) {
                        $this->sinks[$path][$column][] = $sink;
                    }
                }
            }
        };

        $visitor = function (array $row) use ($path): void {
            foreach ($row as $column => $value) {
                foreach ($this->sinks[$path][$column] ?? [] as $sink) {
                    $sink->accept($value);
                }
            }
        };

        $result = $this->reader->scan($path, $columns, $visitor, $onHeader);

        // При чтении всех колонок стоки, заявленные по имени, тоже получили значения —
        // но found по ним заполняется по шапке, а не по списку запроса.
        if ($useAll) {
            foreach (array_keys($this->sinks[$path] ?? []) as $column) {
                $result['found'][$column] = in_array($column, $result['columns'], true);
            }
        }

        return $result;
    }
}
