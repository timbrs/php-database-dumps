<?php

namespace Timbrs\DatabaseDumps\Service\Validation;

/**
 * Одна находка аудита конфигурации выгрузки.
 *
 * Иммутабельный VO. Код находки (`code`) стабилен и машиночитаем — по нему группируются
 * отчёты и решается, что можно починить автоматически; `message` человекочитаем и может
 * меняться. `suggestion` — структурная подсказка правки (что и где менять), из неё же
 * AuditFixer берёт адрес правки для механически однозначных находок.
 */
class Finding
{
    public const SEVERITY_ERROR = 'error';
    public const SEVERITY_WARNING = 'warning';
    public const SEVERITY_NOTE = 'note';

    /**
     * Порядок важности (по убыванию) — для сортировки и порога --severity.
     *
     * @var array<int, string>
     */
    public const SEVERITIES = [self::SEVERITY_ERROR, self::SEVERITY_WARNING, self::SEVERITY_NOTE];

    /** @var string */
    private $code;

    /** @var string */
    private $severity;

    /** @var string */
    private $message;

    /** @var string|null */
    private $schema;

    /** @var string|null */
    private $table;

    /** @var string|null */
    private $column;

    /** @var bool */
    private $fixable;

    /** @var array<string, mixed> */
    private $suggestion;

    /**
     * @param array<string, mixed> $suggestion
     *
     * @throws \InvalidArgumentException при неизвестном severity
     */
    public function __construct(
        string $code,
        string $severity,
        string $message,
        ?string $schema = null,
        ?string $table = null,
        ?string $column = null,
        bool $fixable = false,
        array $suggestion = []
    ) {
        if (!in_array($severity, self::SEVERITIES, true)) {
            throw new \InvalidArgumentException(
                sprintf('Unknown severity "%s", allowed: %s', $severity, implode(', ', self::SEVERITIES))
            );
        }

        $this->code = $code;
        $this->severity = $severity;
        $this->message = $message;
        $this->schema = $schema;
        $this->table = $table;
        $this->column = $column;
        $this->fixable = $fixable;
        $this->suggestion = $suggestion;
    }

    /**
     * @param array<string, mixed> $suggestion
     */
    public static function error(
        string $code,
        string $message,
        ?string $schema = null,
        ?string $table = null,
        ?string $column = null,
        bool $fixable = false,
        array $suggestion = []
    ): self {
        return new self($code, self::SEVERITY_ERROR, $message, $schema, $table, $column, $fixable, $suggestion);
    }

    /**
     * @param array<string, mixed> $suggestion
     */
    public static function warning(
        string $code,
        string $message,
        ?string $schema = null,
        ?string $table = null,
        ?string $column = null,
        bool $fixable = false,
        array $suggestion = []
    ): self {
        return new self($code, self::SEVERITY_WARNING, $message, $schema, $table, $column, $fixable, $suggestion);
    }

    /**
     * @param array<string, mixed> $suggestion
     */
    public static function note(
        string $code,
        string $message,
        ?string $schema = null,
        ?string $table = null,
        ?string $column = null,
        bool $fixable = false,
        array $suggestion = []
    ): self {
        return new self($code, self::SEVERITY_NOTE, $message, $schema, $table, $column, $fixable, $suggestion);
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function getSeverity(): string
    {
        return $this->severity;
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    public function getSchema(): ?string
    {
        return $this->schema;
    }

    public function getTable(): ?string
    {
        return $this->table;
    }

    public function getColumn(): ?string
    {
        return $this->column;
    }

    public function isFixable(): bool
    {
        return $this->fixable;
    }

    /**
     * @return array<string, mixed>
     */
    public function getSuggestion(): array
    {
        return $this->suggestion;
    }

    /**
     * «schema.table.column» — ровно те части, что заданы. Пустая строка для находок
     * уровня всего конфига.
     */
    public function getTarget(): string
    {
        $parts = [];
        foreach ([$this->schema, $this->table, $this->column] as $part) {
            if ($part !== null && $part !== '') {
                $parts[] = $part;
            }
        }
        return implode('.', $parts);
    }

    /**
     * Ранг важности: 0 — error (самая важная). Для сортировки и порога --severity.
     */
    public function severityRank(): int
    {
        $rank = array_search($this->severity, self::SEVERITIES, true);
        return $rank === false ? count(self::SEVERITIES) : (int) $rank;
    }

    /**
     * @return array{code: string, severity: string, schema: string|null, table: string|null, column: string|null, message: string, fixable: bool, suggestion: array<string, mixed>}
     */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'severity' => $this->severity,
            'schema' => $this->schema,
            'table' => $this->table,
            'column' => $this->column,
            'message' => $this->message,
            'fixable' => $this->fixable,
            'suggestion' => $this->suggestion,
        ];
    }
}
