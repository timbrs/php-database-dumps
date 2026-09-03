<?php

namespace Timbrs\DatabaseDumps\Service\Analysis\Decision;

/**
 * Одно предложение изменить конфиг выгрузки: что меняем, на что, почему и на основании чего.
 *
 * `id` считается от адреса и предложения, а не от порядка в файле: между прогонами `analyze`
 * решения переупорядочиваются, и отметка «принято» должна переживать это. Изменилось само
 * предложение — изменится и `id`, значит принимать его нужно заново.
 *
 * PHP 7.2-совместимо.
 */
class Decision
{
    public const KIND_MODE = 'mode';
    public const KIND_LIMIT = 'limit';
    public const KIND_ORDER_BY = 'order_by';
    public const KIND_WHERE = 'where';
    public const KIND_STRATIFY = 'stratify';
    public const KIND_STRATIFY_VIA = 'stratify_via';
    public const KIND_PER_VALUE = 'per_value';
    public const KIND_CRITERIA = 'criteria';
    public const KIND_CASCADE_FROM = 'cascade_from';
    public const KIND_FAKER = 'faker';
    public const KIND_REMOVE_TABLE = 'remove_table';

    public const KINDS = [
        self::KIND_MODE,
        self::KIND_LIMIT,
        self::KIND_ORDER_BY,
        self::KIND_WHERE,
        self::KIND_STRATIFY,
        self::KIND_STRATIFY_VIA,
        self::KIND_PER_VALUE,
        self::KIND_CRITERIA,
        self::KIND_CASCADE_FROM,
        self::KIND_FAKER,
        self::KIND_REMOVE_TABLE,
    ];

    /** Источники доказательств. */
    public const SOURCE_ORM = 'orm';
    public const SOURCE_RAW_SQL = 'raw_sql';
    public const SOURCE_DB = 'db';
    public const SOURCE_VIEW = 'view';
    public const SOURCE_MIGRATION = 'migration';
    public const SOURCE_JIRA = 'jira';
    public const SOURCE_ARTICLE = 'article';
    public const SOURCE_AGENT = 'agent';

    /** @var array<string, mixed> */
    private $data;

    /**
     * @param mixed                            $current
     * @param mixed                            $proposed
     * @param array<int, array<string, mixed>> $evidence
     */
    public function __construct(
        string $table,
        ?string $column,
        string $kind,
        $current,
        $proposed,
        string $rule,
        string $why,
        array $evidence = [],
        string $confidence = 'med',
        bool $auto = false
    ) {
        if (!in_array($kind, self::KINDS, true)) {
            throw new \InvalidArgumentException('Неизвестный kind решения: ' . $kind);
        }
        $this->data = [
            'id' => self::makeId($table, $column, $kind, $rule, $proposed),
            'table' => $table,
            'column' => $column,
            'kind' => $kind,
            'current' => $current,
            'proposed' => $proposed,
            'rule' => $rule,
            'why' => $why,
            'evidence' => array_values($evidence),
            'confidence' => $confidence,
            'auto' => $auto,
        ];
    }

    /**
     * @param mixed $proposed
     */
    public static function makeId(string $table, ?string $column, string $kind, string $rule, $proposed): string
    {
        $normalized = json_encode($proposed, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return sha1(implode('|', [$table, (string) $column, $kind, $rule, $normalized === false ? '' : $normalized]));
    }

    public function getId(): string
    {
        return $this->data['id'];
    }

    public function getTable(): string
    {
        return $this->data['table'];
    }

    public function getKind(): string
    {
        return $this->data['kind'];
    }

    public function isAuto(): bool
    {
        return (bool) $this->data['auto'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->data;
    }
}
