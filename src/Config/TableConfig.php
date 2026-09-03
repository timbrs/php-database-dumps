<?php

namespace Timbrs\DatabaseDumps\Config;

/**
 * DTO для конфигурации экспорта таблицы.
 *
 * Иммутабельный.
 *
 * Поля schema/table валидируются — допускаются только [a-zA-Z0-9_$].
 * Поля where/order_by сохраняются как trusted input из YAML (никакой санитизации
 * не происходит), но базовая проверка на наличие точки с запятой/комментариев
 * предотвращает очевидные ошибки.
 */
class TableConfig
{
    public const KEY_LIMIT = 'limit';
    public const KEY_WHERE = 'where';
    public const KEY_ORDER_BY = 'order_by';
    public const KEY_CASCADE_FROM = 'cascade_from';
    public const KEY_DEFERRED_COLUMNS = 'deferred_columns';
    public const KEY_SAMPLE = 'sample';

    /** Ключи внутри секции sample. */
    public const SAMPLE_KEY_CRITERIA = 'criteria';
    public const SAMPLE_KEY_STRATIFY_BY = 'stratify_by';
    public const SAMPLE_KEY_PER_VALUE = 'per_value';
    public const SAMPLE_KEY_STRATIFY = 'stratify';
    public const SAMPLE_KEY_STRATIFY_VIA = 'stratify_via';
    public const CRITERION_KEY_NAME = 'name';
    public const CRITERION_KEY_WHERE = 'where';
    public const CRITERION_KEY_LIMIT = 'limit';

    /** Ключи описания stratify[] (и вложенного then) и stratify_via[]. */
    public const STRATIFY_KEY_COLUMN = 'column';
    public const STRATIFY_KEY_PER_VALUE = 'per_value';
    public const STRATIFY_KEY_THEN = 'then';
    public const STRATIFY_KEY_MAX_VALUES = 'max_values';
    public const VIA_KEY_TABLE = 'table';
    public const VIA_KEY_JOIN = 'join';
    public const VIA_KEY_WHERE = 'where';

    /** Вложенный уровень: значений второго уровня на значение первого и квота на пару. */
    public const DEFAULT_THEN_MAX_VALUES = 20;
    public const DEFAULT_THEN_PER_VALUE = 10;

    /**
     * Дефолтная квота корзины stratify_by, если per_value не задан ни в таблице, ни в
     * settings.sample.per_value. «Сто красных, сто зелёных»: при упоре в limit квота
     * ужимается под него (SampleQueryBuilder), а не отрезаются последние корзины.
     */
    public const DEFAULT_PER_VALUE = 100;

    /**
     * Разрешённые символы в schema/table/column-идентификаторах.
     * Покрывает Unicode-буквы (кириллица и пр.), цифры, подчёркивание и доллар (Oracle).
     * Разделители, кавычки, точки и пробелы запрещены (защита от SQL-инъекции/path traversal).
     */
    private const IDENTIFIER_REGEX = '/^[\p{L}_][\p{L}\p{N}_$]*$/u';

    /** @var string */
    private $schema;
    /** @var string */
    private $table;
    /** @var int|null */
    private $limit;
    /** @var string|null */
    private $where;
    /** @var string|null */
    private $orderBy;
    /** @var string|null */
    private $connectionName;
    /** @var array<int, array{parent: string, fk_column: string, parent_column: string}>|null */
    private $cascadeFrom;
    /** @var array<int, array{column: string, reference_table: string, reference_column: string}>|null */
    private $deferredColumns;
    /** @var array<string, mixed>|null Секция sample (criteria/stratify_by/per_value) */
    private $sample;

    /**
     * @param array<int, array{parent: string, fk_column: string, parent_column: string}>|null $cascadeFrom
     * @param array<int, array{column: string, reference_table: string, reference_column: string}>|null $deferredColumns
     * @param array<string, mixed>|null $sample
     *
     * @throws \InvalidArgumentException если schema/table содержат недопустимые символы
     */
    public function __construct(
        string $schema,
        string $table,
        ?int $limit = null,
        ?string $where = null,
        ?string $orderBy = null,
        ?string $connectionName = null,
        ?array $cascadeFrom = null,
        ?array $deferredColumns = null,
        ?array $sample = null
    ) {
        $this->validateIdentifier('schema', $schema);
        $this->validateIdentifier('table', $table);

        if ($limit !== null && $limit < 0) {
            throw new \InvalidArgumentException('limit must be >= 0, got: ' . $limit);
        }
        if ($where !== null) {
            $this->validateClause('where', $where);
        }
        if ($orderBy !== null) {
            $this->validateClause('order_by', $orderBy);
        }
        if ($cascadeFrom !== null) {
            $this->validateCascadeFrom($cascadeFrom);
        }
        if ($deferredColumns !== null) {
            $this->validateDeferredColumns($deferredColumns);
        }
        if ($sample !== null) {
            $this->validateSample($sample);
        }

        $this->schema = $schema;
        $this->table = $table;
        $this->limit = $limit;
        $this->where = $where;
        $this->orderBy = $orderBy;
        $this->connectionName = $connectionName;
        $this->cascadeFrom = $cascadeFrom;
        $this->deferredColumns = $deferredColumns;
        $this->sample = $sample;
    }

    public function getSchema(): string
    {
        return $this->schema;
    }

    public function getTable(): string
    {
        return $this->table;
    }

    public function getFullTableName(): string
    {
        return "{$this->schema}.{$this->table}";
    }

    public function getLimit(): ?int
    {
        return $this->limit;
    }

    public function getWhere(): ?string
    {
        return $this->where;
    }

    public function getOrderBy(): ?string
    {
        return $this->orderBy;
    }

    public function isFullExport(): bool
    {
        return $this->limit === null;
    }

    public function isPartialExport(): bool
    {
        return $this->limit !== null;
    }

    public function getConnectionName(): ?string
    {
        return $this->connectionName;
    }

    /**
     * @return array<int, array{parent: string, fk_column: string, parent_column: string}>|null
     */
    public function getCascadeFrom(): ?array
    {
        return $this->cascadeFrom;
    }

    /**
     * @return array<int, array{column: string, reference_table: string, reference_column: string}>|null
     */
    public function getDeferredColumns(): ?array
    {
        return $this->deferredColumns;
    }

    /**
     * Получить секцию sample (criteria / stratify_by / per_value) или null.
     *
     * @return array<string, mixed>|null
     */
    public function getSample(): ?array
    {
        return $this->sample;
    }

    /**
     * Есть ли у таблицы директива выборки по именованным критериям.
     */
    public function hasSample(): bool
    {
        return $this->sample !== null;
    }

    /**
     * Колонки stratify_by секции sample: одна строка или список — всегда списком.
     *
     * @param array<string, mixed>|null $sample
     * @return array<int, string>
     */
    public static function stratifyColumns(?array $sample): array
    {
        if ($sample === null) {
            return [];
        }
        $columns = [];
        if (isset($sample[self::SAMPLE_KEY_STRATIFY_BY])) {
            $raw = $sample[self::SAMPLE_KEY_STRATIFY_BY];
            foreach (is_array($raw) ? $raw : [$raw] as $column) {
                if (is_string($column) && $column !== '') {
                    $columns[] = $column;
                }
            }
        }
        foreach (self::stratifySpecs($sample) as $spec) {
            $columns[] = $spec['column'];
        }

        return $columns;
    }

    /**
     * Описания sample.stratify — вложенная стратификация: корзина на значение column,
     * а внутри неё (then) — на значение второй колонки среди строк первой.
     *
     * @param array<string, mixed>|null $sample
     * @return array<int, array{column: string, per_value: int|null, then: array{column: string, per_value: int|null, max_values: int}|null}>
     */
    public static function stratifySpecs(?array $sample): array
    {
        if ($sample === null || !isset($sample[self::SAMPLE_KEY_STRATIFY]) || !is_array($sample[self::SAMPLE_KEY_STRATIFY])) {
            return [];
        }
        $specs = [];
        foreach ($sample[self::SAMPLE_KEY_STRATIFY] as $spec) {
            if (!is_array($spec) || !isset($spec[self::STRATIFY_KEY_COLUMN]) || !is_string($spec[self::STRATIFY_KEY_COLUMN])) {
                continue;
            }
            $then = null;
            if (isset($spec[self::STRATIFY_KEY_THEN]) && is_array($spec[self::STRATIFY_KEY_THEN])
                && isset($spec[self::STRATIFY_KEY_THEN][self::STRATIFY_KEY_COLUMN])
                && is_string($spec[self::STRATIFY_KEY_THEN][self::STRATIFY_KEY_COLUMN])
            ) {
                $rawThen = $spec[self::STRATIFY_KEY_THEN];
                $then = [
                    'column' => $rawThen[self::STRATIFY_KEY_COLUMN],
                    'per_value' => isset($rawThen[self::STRATIFY_KEY_PER_VALUE]) ? (int) $rawThen[self::STRATIFY_KEY_PER_VALUE] : null,
                    'max_values' => isset($rawThen[self::STRATIFY_KEY_MAX_VALUES]) ? (int) $rawThen[self::STRATIFY_KEY_MAX_VALUES] : self::DEFAULT_THEN_MAX_VALUES,
                ];
            }
            $specs[] = [
                'column' => $spec[self::STRATIFY_KEY_COLUMN],
                'per_value' => isset($spec[self::STRATIFY_KEY_PER_VALUE]) ? (int) $spec[self::STRATIFY_KEY_PER_VALUE] : null,
                'then' => $then,
            ];
        }

        return $specs;
    }

    /**
     * Описания sample.stratify_via — обратная стратификация: значения открываются на
     * дочерней таблице (EAV-атрибуты), а корзины строятся у родителя через EXISTS.
     *
     * @param array<string, mixed>|null $sample
     * @return array<int, array{table: string, join: array<string, string>, where: string|null, column: string, per_value: int|null}>
     */
    public static function stratifyVia(?array $sample): array
    {
        if ($sample === null || !isset($sample[self::SAMPLE_KEY_STRATIFY_VIA]) || !is_array($sample[self::SAMPLE_KEY_STRATIFY_VIA])) {
            return [];
        }
        $result = [];
        foreach ($sample[self::SAMPLE_KEY_STRATIFY_VIA] as $via) {
            if (!is_array($via) || !isset($via[self::VIA_KEY_TABLE], $via[self::VIA_KEY_JOIN], $via[self::STRATIFY_KEY_COLUMN])
                || !is_string($via[self::VIA_KEY_TABLE]) || !is_array($via[self::VIA_KEY_JOIN]) || !is_string($via[self::STRATIFY_KEY_COLUMN])
            ) {
                continue;
            }
            $join = [];
            foreach ($via[self::VIA_KEY_JOIN] as $viaColumn => $localColumn) {
                if (is_string($viaColumn) && is_string($localColumn)) {
                    $join[$viaColumn] = $localColumn;
                }
            }
            $result[] = [
                'table' => $via[self::VIA_KEY_TABLE],
                'join' => $join,
                'where' => isset($via[self::VIA_KEY_WHERE]) && is_string($via[self::VIA_KEY_WHERE]) && $via[self::VIA_KEY_WHERE] !== ''
                    ? $via[self::VIA_KEY_WHERE] : null,
                'column' => $via[self::STRATIFY_KEY_COLUMN],
                'per_value' => isset($via[self::STRATIFY_KEY_PER_VALUE]) ? (int) $via[self::STRATIFY_KEY_PER_VALUE] : null,
            ];
        }

        return $result;
    }

    /**
     * Создать новый TableConfig с обновлёнными deferred-столбцами (immutable wither).
     *
     * @param array<int, array{column: string, reference_table: string, reference_column: string}>|null $deferredColumns
     */
    public function withDeferredColumns(?array $deferredColumns): self
    {
        return new self(
            $this->schema,
            $this->table,
            $this->limit,
            $this->where,
            $this->orderBy,
            $this->connectionName,
            $this->cascadeFrom,
            $deferredColumns,
            $this->sample
        );
    }

    /**
     * Создать из массива конфигурации.
     *
     * @param array<string, mixed> $config
     */
    public static function fromArray(string $schema, string $table, array $config = [], ?string $connectionName = null): self
    {
        $limit = $config[self::KEY_LIMIT] ?? null;
        if ($limit !== null && !is_int($limit)) {
            throw new \InvalidArgumentException(
                sprintf('Invalid limit type for %s.%s: expected int, got %s', $schema, $table, gettype($limit))
            );
        }

        $where = $config[self::KEY_WHERE] ?? null;
        if ($where !== null && !is_string($where)) {
            throw new \InvalidArgumentException(
                sprintf('Invalid where type for %s.%s: expected string, got %s', $schema, $table, gettype($where))
            );
        }

        $orderBy = $config[self::KEY_ORDER_BY] ?? null;
        if ($orderBy !== null && !is_string($orderBy)) {
            throw new \InvalidArgumentException(
                sprintf('Invalid order_by type for %s.%s: expected string, got %s', $schema, $table, gettype($orderBy))
            );
        }

        $cascadeFrom = $config[self::KEY_CASCADE_FROM] ?? null;
        $deferredColumns = $config[self::KEY_DEFERRED_COLUMNS] ?? null;

        $sample = $config[self::KEY_SAMPLE] ?? null;
        if ($sample !== null && !is_array($sample)) {
            throw new \InvalidArgumentException(
                sprintf('Invalid sample type for %s.%s: expected array, got %s', $schema, $table, gettype($sample))
            );
        }

        return new self(
            $schema,
            $table,
            $limit,
            $where,
            $orderBy,
            $connectionName,
            $cascadeFrom,
            $deferredColumns,
            $sample
        );
    }

    /**
     * Проверить, что значение — корректный SQL-идентификатор (защита от инъекций).
     *
     * Идентификатор может быть либо простым (table), либо qualified (schema.table),
     * либо допускать пробелы для составных схем — НО НЕТ. Schema и table —
     * простые идентификаторы.
     *
     * @throws \InvalidArgumentException
     */
    private function validateIdentifier(string $field, string $value): void
    {
        if ($value === '') {
            throw new \InvalidArgumentException("Field {$field} cannot be empty");
        }
        if (!preg_match(self::IDENTIFIER_REGEX, $value)) {
            throw new \InvalidArgumentException(
                sprintf(
                    'Invalid %s identifier "%s": only [A-Za-z0-9_$] allowed, must start with letter or underscore',
                    $field,
                    $value
                )
            );
        }
    }

    /**
     * Базовая проверка WHERE / ORDER BY (защита от очевидных ошибок).
     *
     * YAML — trusted input (разработчик/DBA), но мы блокируем символы,
     * которые ломают statement и часто свидетельствуют об опечатке.
     *
     * @throws \InvalidArgumentException
     */
    private function validateClause(string $field, string $clause): void
    {
        if (strpos($clause, ';') !== false) {
            throw new \InvalidArgumentException(
                "Field {$field} must not contain ';' (statement terminator)"
            );
        }
        // '--' и '/*' — стандартные SQL-комментарии; '#' — построчный комментарий MySQL.
        if (strpos($clause, '--') !== false
            || strpos($clause, '/*') !== false
            || strpos($clause, '#') !== false
        ) {
            throw new \InvalidArgumentException(
                "Field {$field} must not contain SQL comments ('--', '/*' or '#')"
            );
        }
        // Балансировка кавычек и скобок
        if (substr_count($clause, "'") % 2 !== 0) {
            throw new \InvalidArgumentException(
                "Field {$field} has unbalanced single quotes"
            );
        }
        $openParen = substr_count($clause, '(');
        $closeParen = substr_count($clause, ')');
        if ($openParen !== $closeParen) {
            throw new \InvalidArgumentException(
                "Field {$field} has unbalanced parentheses"
            );
        }
    }

    /**
     * @param array<int, mixed> $cascadeFrom
     * @throws \InvalidArgumentException
     */
    private function validateCascadeFrom(array $cascadeFrom): void
    {
        foreach ($cascadeFrom as $i => $entry) {
            if (!is_array($entry)) {
                throw new \InvalidArgumentException("cascade_from[{$i}] must be array");
            }
            foreach (['parent', 'fk_column', 'parent_column'] as $key) {
                if (!isset($entry[$key]) || !is_string($entry[$key]) || $entry[$key] === '') {
                    throw new \InvalidArgumentException(
                        "cascade_from[{$i}].{$key} must be non-empty string"
                    );
                }
            }
            // parent должен быть в формате schema.table
            if (substr_count($entry['parent'], '.') !== 1) {
                throw new \InvalidArgumentException(
                    "cascade_from[{$i}].parent must be 'schema.table', got: {$entry['parent']}"
                );
            }
            // Валидируем КАЖДУЮ часть schema.table (защита от 'pub;DROP--.x')
            [$parentSchema, $parentTable] = explode('.', $entry['parent'], 2);
            $this->validateIdentifier("cascade_from[{$i}].parent.schema", $parentSchema);
            $this->validateIdentifier("cascade_from[{$i}].parent.table", $parentTable);
            // fk_column / parent_column — простые идентификаторы (защита от инъекций)
            $this->validateIdentifier("cascade_from[{$i}].fk_column", $entry['fk_column']);
            $this->validateIdentifier("cascade_from[{$i}].parent_column", $entry['parent_column']);
        }
    }

    /**
     * @param array<int, mixed> $deferredColumns
     * @throws \InvalidArgumentException
     */
    private function validateDeferredColumns(array $deferredColumns): void
    {
        foreach ($deferredColumns as $i => $entry) {
            if (!is_array($entry)) {
                throw new \InvalidArgumentException("deferred_columns[{$i}] must be array");
            }
            foreach (['column', 'reference_table', 'reference_column'] as $key) {
                if (!isset($entry[$key]) || !is_string($entry[$key]) || $entry[$key] === '') {
                    throw new \InvalidArgumentException(
                        "deferred_columns[{$i}].{$key} must be non-empty string"
                    );
                }
            }
            $this->validateIdentifier("deferred_columns[{$i}].column", $entry['column']);
        }
    }

    /**
     * Валидация секции sample.
     *
     * Структура:
     *   sample:
     *     criteria:
     *       - { name: <ident>, where: <clause>, limit: <int> }
     *     stratify_by: <ident> | [<ident>, …]   # необязательно; список — корзины по каждой колонке
     *     per_value:  <int>         # необязательно (квота для stratify_by)
     *     stratify:                 # необязательно; вложенная стратификация (один уровень then)
     *       - { column: <ident>, per_value: <int>, then: { column: <ident>, per_value: <int>, max_values: <int> } }
     *     stratify_via:             # необязательно; значения открываются на дочерней таблице
     *       - { table: <schema.table>, join: { <via_col>: <local_col> }, where: <clause>, column: <ident>, per_value: <int> }
     *
     * Каждый criteria[].where проходит ту же проверку, что и общий where
     * (запрет ';', SQL-комментариев, баланс кавычек/скобок) — корректные
     * EXISTS (...) подзапросы проходят. name/stratify_by — идентификаторы.
     *
     * @param array<string, mixed> $sample
     * @throws \InvalidArgumentException
     */
    private function validateSample(array $sample): void
    {
        $hasCriteria = isset($sample[self::SAMPLE_KEY_CRITERIA]);
        $hasStratify = isset($sample[self::SAMPLE_KEY_STRATIFY_BY]);
        $hasSpecs = isset($sample[self::SAMPLE_KEY_STRATIFY]);
        $hasVia = isset($sample[self::SAMPLE_KEY_STRATIFY_VIA]);

        if (!$hasCriteria && !$hasStratify && !$hasSpecs && !$hasVia) {
            throw new \InvalidArgumentException(
                'sample must define at least "' . self::SAMPLE_KEY_CRITERIA
                . '", "' . self::SAMPLE_KEY_STRATIFY_BY . '", "' . self::SAMPLE_KEY_STRATIFY
                . '" or "' . self::SAMPLE_KEY_STRATIFY_VIA . '"'
            );
        }

        if ($hasSpecs) {
            $specs = $sample[self::SAMPLE_KEY_STRATIFY];
            if (!is_array($specs) || $specs === []) {
                throw new \InvalidArgumentException('sample.stratify must be a non-empty list');
            }
            foreach ($specs as $i => $spec) {
                $this->validateStratifySpec("sample.stratify[{$i}]", $spec, true);
            }
        }

        if ($hasVia) {
            $vias = $sample[self::SAMPLE_KEY_STRATIFY_VIA];
            if (!is_array($vias) || $vias === []) {
                throw new \InvalidArgumentException('sample.stratify_via must be a non-empty list');
            }
            foreach ($vias as $i => $via) {
                $this->validateStratifyVia("sample.stratify_via[{$i}]", $via);
            }
        }

        if ($hasCriteria) {
            $criteria = $sample[self::SAMPLE_KEY_CRITERIA];
            if (!is_array($criteria)) {
                throw new \InvalidArgumentException('sample.criteria must be an array');
            }
            foreach ($criteria as $i => $entry) {
                $this->validateCriterion((int) $i, $entry);
            }
        }

        if ($hasStratify) {
            $stratifyBy = $sample[self::SAMPLE_KEY_STRATIFY_BY];
            if (is_array($stratifyBy)) {
                if ($stratifyBy === []) {
                    throw new \InvalidArgumentException('sample.stratify_by must be a non-empty string or a non-empty list of column names');
                }
                foreach ($stratifyBy as $i => $column) {
                    if (!is_string($column) || $column === '') {
                        throw new \InvalidArgumentException("sample.stratify_by[{$i}] must be a non-empty string");
                    }
                    $this->validateIdentifier("sample.stratify_by[{$i}]", $column);
                }
            } else {
                if (!is_string($stratifyBy) || $stratifyBy === '') {
                    throw new \InvalidArgumentException('sample.stratify_by must be a non-empty string or a non-empty list of column names');
                }
                $this->validateIdentifier('sample.stratify_by', $stratifyBy);
            }
        }

        if (isset($sample[self::SAMPLE_KEY_PER_VALUE])) {
            $perValue = $sample[self::SAMPLE_KEY_PER_VALUE];
            if (!is_int($perValue) || $perValue < 1) {
                throw new \InvalidArgumentException('sample.per_value must be an int >= 1');
            }
        }
    }

    /**
     * Описание stratify[]: column — идентификатор, per_value/max_values — int >= 1,
     * then — ещё одно описание без собственного then (глубже одного уровня не поддерживается:
     * число корзин растёт как произведение, и квота ужмётся в ноль).
     *
     * @param mixed $spec
     * @throws \InvalidArgumentException
     */
    private function validateStratifySpec(string $path, $spec, bool $allowThen): void
    {
        if (!is_array($spec)) {
            throw new \InvalidArgumentException("{$path} must be an object with \"column\"");
        }
        $column = $spec[self::STRATIFY_KEY_COLUMN] ?? null;
        if (!is_string($column) || $column === '') {
            throw new \InvalidArgumentException("{$path}.column must be a non-empty string");
        }
        $this->validateIdentifier("{$path}.column", $column);

        foreach ([self::STRATIFY_KEY_PER_VALUE, self::STRATIFY_KEY_MAX_VALUES] as $key) {
            if (isset($spec[$key]) && (!is_int($spec[$key]) || $spec[$key] < 1)) {
                throw new \InvalidArgumentException("{$path}.{$key} must be an int >= 1");
            }
        }

        if (isset($spec[self::STRATIFY_KEY_THEN])) {
            if (!$allowThen) {
                throw new \InvalidArgumentException("{$path}.then: only one nested level is supported");
            }
            $this->validateStratifySpec("{$path}.then", $spec[self::STRATIFY_KEY_THEN], false);
        }
    }

    /**
     * Описание stratify_via[]: table — «schema.table», join — непустая карта
     * колонка_дочерней => колонка_этой_таблицы, where — необязательный фрагмент с теми же
     * ограничениями, что и обычный where, column — идентификатор, per_value — int >= 1.
     *
     * @param mixed $via
     * @throws \InvalidArgumentException
     */
    private function validateStratifyVia(string $path, $via): void
    {
        if (!is_array($via)) {
            throw new \InvalidArgumentException("{$path} must be an object with \"table\", \"join\" and \"column\"");
        }
        $table = $via[self::VIA_KEY_TABLE] ?? null;
        if (!is_string($table) || substr_count($table, '.') !== 1) {
            throw new \InvalidArgumentException("{$path}.table must be \"schema.table\"");
        }
        [$viaSchema, $viaTable] = explode('.', $table, 2);
        $this->validateIdentifier("{$path}.table (schema)", $viaSchema);
        $this->validateIdentifier("{$path}.table (table)", $viaTable);

        $join = $via[self::VIA_KEY_JOIN] ?? null;
        if (!is_array($join) || $join === []) {
            throw new \InvalidArgumentException("{$path}.join must be a non-empty map of <via column>: <local column>");
        }
        foreach ($join as $viaColumn => $localColumn) {
            if (!is_string($viaColumn) || !is_string($localColumn) || $localColumn === '') {
                throw new \InvalidArgumentException("{$path}.join entries must map column names to column names");
            }
            $this->validateIdentifier("{$path}.join key", $viaColumn);
            $this->validateIdentifier("{$path}.join value", $localColumn);
        }

        if (isset($via[self::VIA_KEY_WHERE])) {
            $where = $via[self::VIA_KEY_WHERE];
            if (!is_string($where) || $where === '') {
                throw new \InvalidArgumentException("{$path}.where must be a non-empty string");
            }
            $this->validateClause("{$path}.where", $where);
        }

        $column = $via[self::STRATIFY_KEY_COLUMN] ?? null;
        if (!is_string($column) || $column === '') {
            throw new \InvalidArgumentException("{$path}.column must be a non-empty string");
        }
        $this->validateIdentifier("{$path}.column", $column);

        if (isset($via[self::STRATIFY_KEY_PER_VALUE]) && (!is_int($via[self::STRATIFY_KEY_PER_VALUE]) || $via[self::STRATIFY_KEY_PER_VALUE] < 1)) {
            throw new \InvalidArgumentException("{$path}.per_value must be an int >= 1");
        }
    }

    /**
     * @param mixed $entry
     * @throws \InvalidArgumentException
     */
    private function validateCriterion(int $i, $entry): void
    {
        if (!is_array($entry)) {
            throw new \InvalidArgumentException("sample.criteria[{$i}] must be an array");
        }

        $name = $entry[self::CRITERION_KEY_NAME] ?? null;
        if (!is_string($name) || $name === '') {
            throw new \InvalidArgumentException("sample.criteria[{$i}].name must be a non-empty string");
        }
        $this->validateIdentifier("sample.criteria[{$i}].name", $name);

        $where = $entry[self::CRITERION_KEY_WHERE] ?? null;
        if (!is_string($where) || $where === '') {
            throw new \InvalidArgumentException("sample.criteria[{$i}].where must be a non-empty string");
        }
        $this->validateClause("sample.criteria[{$i}].where", $where);

        if (!isset($entry[self::CRITERION_KEY_LIMIT])) {
            throw new \InvalidArgumentException("sample.criteria[{$i}].limit is required");
        }
        $limit = $entry[self::CRITERION_KEY_LIMIT];
        if (!is_int($limit) || $limit < 1) {
            throw new \InvalidArgumentException("sample.criteria[{$i}].limit must be an int >= 1");
        }
    }
}
