<?php

namespace Timbrs\DatabaseDumps\Service\Analysis\CodeHints;

/**
 * Per-file stateless-детектор связей между таблицами, заданных в ORM-коде (не во FK БД).
 *
 * Возвращает «сырые» кандидаты, где target указан классом (target_class) или литеральным
 * именем таблицы (target_table). Резолв target в schema.table и проставление in_db_fk
 * делает вызывающий CodeHintScanner (у него есть classMap и FK-граф инвентаря).
 *
 * Источник связи (source-таблица) — сущность/модель текущего файла: её знает сканер
 * по primaryClass файла, поэтому в кандидатах он не указывается.
 *
 * Сигналы:
 *  - Doctrine: #[ORM\ManyToOne/OneToOne/OneToMany/ManyToMany] (+ атрибут/аннотация targetEntity,
 *    mappedBy) и #[ORM\JoinColumn(name:'…', referencedColumnName:'…')]; типизированное свойство
 *    ?Client $client уточняет targetEntity;
 *  - Eloquent: $this->belongsTo/hasMany/hasOne/belongsToMany/morphTo/morphMany(Client::class,'fk','ok').
 *
 * PHP 7.2-совместимо.
 */
class RelationshipDetector
{
    /**
     * Doctrine-типы связей → kind в терминах контракта.
     *
     * @var array<string, string>
     */
    private static $DOCTRINE_KINDS = [
        'ManyToOne'  => 'belongs_to',
        'OneToOne'   => 'has_one',
        'OneToMany'  => 'has_many',
        'ManyToMany' => 'other',
    ];

    /**
     * Eloquent-методы связей → kind.
     *
     * @var array<string, string>
     */
    private static $ELOQUENT_KINDS = [
        'belongsTo'      => 'belongs_to',
        'hasMany'        => 'has_many',
        'hasOne'         => 'has_one',
        'belongsToMany'  => 'other',
        'morphTo'        => 'morph',
        'morphMany'      => 'morph',
        'morphOne'       => 'morph',
    ];

    /** Сколько строк вперёд смотреть от строки связи в поисках JoinColumn/свойства. */
    const LOOKAHEAD = 8;

    /**
     * @param array<int, string> $lines
     * @return array<int, array<string, mixed>> сырые кандидаты
     */
    public function detect(string $content, array $lines, string $rel): array
    {
        $out = [];
        foreach ($this->detectDoctrine($lines) as $c) {
            $c['file'] = $rel;
            $out[] = $c;
        }
        foreach ($this->detectEloquent($lines) as $c) {
            $c['file'] = $rel;
            $out[] = $c;
        }
        return $out;
    }

    /**
     * @param array<int, string> $lines
     * @return array<int, array<string, mixed>>
     */
    private function detectDoctrine(array $lines): array
    {
        $out = [];
        $count = count($lines);
        foreach ($lines as $idx => $line) {
            if (!preg_match('/ORM\\\\(ManyToOne|OneToOne|OneToMany|ManyToMany)\b/', $line, $km)) {
                continue;
            }
            $kind = self::$DOCTRINE_KINDS[$km[1]];

            // Окно строк связи: от строки атрибута до объявления свойства (со `;` или `{`).
            $window = $line;
            $to = min($count - 1, $idx + self::LOOKAHEAD);
            $propType = null;
            for ($i = $idx; $i <= $to; $i++) {
                if ($i > $idx) {
                    $window .= "\n" . $lines[$i];
                }
                if (preg_match('/(?:private|protected|public|var)?\s*\??([A-Za-z_\x{0080}-\x{FFFF}][A-Za-z0-9_\x{0080}-\x{FFFF}]*)\s+\$[A-Za-z_]/u', $lines[$i], $pm)
                    && $i > $idx
                ) {
                    $propType = $pm[1];
                    break;
                }
                if (strpos($lines[$i], ';') !== false && $i > $idx) {
                    break;
                }
            }

            $targetClass = $this->matchTargetEntity($window);
            if ($targetClass === null && $propType !== null && !$this->isScalarType($propType)) {
                $targetClass = $propType;
            }

            $sourceColumn = '';
            $targetColumn = 'id';
            if (preg_match('/JoinColumn\s*\([^)]*name\s*[:=]\s*[\'"]([^\'"]+)[\'"]/', $window, $jn)) {
                $sourceColumn = $jn[1];
            }
            if (preg_match('/referencedColumnName\s*[:=]\s*[\'"]([^\'"]+)[\'"]/', $window, $rc)) {
                $targetColumn = $rc[1];
            }

            if ($targetClass === null) {
                continue; // цель неизвестна — кандидат бесполезен
            }

            $out[] = [
                'source_column' => $sourceColumn,
                'target_class'  => $targetClass,
                'target_table'  => null,
                'target_column' => $targetColumn,
                'kind'          => $kind,
                'origin'        => 'doctrine',
                'line'          => $idx + 1,
            ];
        }
        return $out;
    }

    /**
     * targetEntity в стиле атрибута (Client::class) или аннотации (targetEntity="Client").
     *
     * @return string|null короткое имя класса
     */
    private function matchTargetEntity(string $window)
    {
        if (preg_match('/targetEntity\s*[:=]\s*([A-Za-z_\x{0080}-\x{FFFF}][A-Za-z0-9_\x{0080}-\x{FFFF}]*)\s*::\s*class/u', $window, $m)) {
            return $m[1];
        }
        if (preg_match('/targetEntity\s*[:=]\s*[\'"]([^\'"\\\\]*\\\\)*([A-Za-z_\x{0080}-\x{FFFF}][A-Za-z0-9_\x{0080}-\x{FFFF}]*)[\'"]/u', $window, $m)) {
            return $m[count($m) - 1];
        }
        return null;
    }

    private function isScalarType(string $type): bool
    {
        $t = strtolower($type);
        return in_array($t, ['int', 'string', 'bool', 'float', 'array', 'iterable', 'object', 'mixed', 'self', 'static', 'void', 'collection'], true);
    }

    /**
     * @param array<int, string> $lines
     * @return array<int, array<string, mixed>>
     */
    private function detectEloquent(array $lines): array
    {
        $out = [];
        $methods = implode('|', array_keys(self::$ELOQUENT_KINDS));
        $re = '/->\s*(' . $methods . ')\s*\(\s*([A-Za-z_\x{0080}-\x{FFFF}][A-Za-z0-9_\x{0080}-\x{FFFF}]*)\s*::\s*class'
            . '(?:\s*,\s*[\'"]([^\'"]+)[\'"])?'
            . '(?:\s*,\s*[\'"]([^\'"]+)[\'"])?/u';
        foreach ($lines as $idx => $line) {
            if (!preg_match_all($re, $line, $mm, PREG_SET_ORDER)) {
                continue;
            }
            foreach ($mm as $m) {
                $kind = self::$ELOQUENT_KINDS[$m[1]];
                $out[] = [
                    'source_column' => isset($m[3]) ? $m[3] : '',
                    'target_class'  => $m[2],
                    'target_table'  => null,
                    'target_column' => isset($m[4]) && $m[4] !== '' ? $m[4] : 'id',
                    'kind'          => $kind,
                    'origin'        => 'eloquent',
                    'line'          => $idx + 1,
                ];
            }
        }
        return $out;
    }
}
