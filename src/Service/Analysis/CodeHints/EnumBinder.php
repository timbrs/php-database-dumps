<?php

namespace Timbrs\DatabaseDumps\Service\Analysis\CodeHints;

/**
 * Привязка enum'а к колонке таблицы — три моста, потому что в хосте связь чаще всего
 * нигде не объявлена: `enumType` стоит у 34 колонок из ~2000, а остальные enum'ы живут
 * только в коде.
 *
 *  (а) атрибут/каст — `#[ORM\Column(enumType: JobTypeEnum::class)]`, `$casts` — high;
 *      его собирает ColumnUsageDetector, сюда приходит готовым;
 *  (б) код в одном statement:
 *      - `->setJobType(JobTypeEnum::READY->value)` → свойство → колонка (EntityPropertyMap);
 *      - DQL `setParameter(':p', Enum::X)` рядом с `alias.property` в where/andWhere;
 *      - сырой SQL `t1.job_type = ' . Enum::X->value`;
 *  (в) конвенция — `job_type ↔ JobTypeEnum`, `<x>_attrs.attr_id ↔ <X>AttrDictEnum`,
 *      `*_id ↔ *IdEnum` (только с доменным фильтром) — low, поднимается до med, если
 *      case'ы enum'а укладываются в коды колонки из статистики.
 *
 * Несколько кандидатов на колонку → лучший плюс `alternatives[]` и `ambiguous: true`:
 * гадать за человека тут нельзя, зато можно показать, между чем выбирать.
 *
 * PHP 7.2-совместимо.
 */
class EnumBinder
{
    use TextHelperTrait;

    /** Окно поиска пары «setParameter — where» в строках. */
    const DQL_WINDOW = 25;

    /**
     * Порядок уверенности (меньше — лучше).
     *
     * @var array<string, int>
     */
    private static $RANK = ['high' => 0, 'med' => 1, 'low' => 2];

    /** @var EntityPropertyMap */
    private $properties;

    public function __construct(EntityPropertyMap $properties = null)
    {
        $this->properties = $properties !== null ? $properties : new EntityPropertyMap();
    }

    /**
     * @param array<int, string>                                                        $columns колонки таблицы
     * @param array<int, array{rel: string, content: string, lines: array<int, string>}> $files   файлы, связанные с таблицей
     * @param array{values: array<string, array<int, string>>, cases: array<string, array<string, string>>, backing: array<string, string>, by_short_name: array<string, array<int, string>>} $enums
     * @param array<string, array<int, string>>                                         $codes   колонка => коды из статистики (для подъёма уверенности)
     *
     * @return array<string, array<string, mixed>> колонка => {class, short, backing, cases, values, confidence, bridge, evidence, alternatives, ambiguous}
     */
    public function bind(string $schema, string $table, array $columns, array $files, array $enums, array $codes = []): array
    {
        $known = [];
        foreach ($columns as $column) {
            if (is_string($column) && $column !== '') {
                $known[$column] = true;
            }
        }
        if ($known === []) {
            return [];
        }

        /** @var array<string, array<string, array<string, mixed>>> $candidates колонка => fqcn => кандидат */
        $candidates = [];

        foreach ($files as $file) {
            $imports = UseStatementResolver::imports($file['content']);
            $propertyMap = $this->properties->build($file['lines']);

            foreach ($this->fromSetters($file, $imports, $propertyMap, $known) as $hit) {
                $this->addCandidate($candidates, $hit);
            }
            foreach ($this->fromDql($file, $imports, $propertyMap, $known) as $hit) {
                $this->addCandidate($candidates, $hit);
            }
            foreach ($this->fromRawSql($file, $imports, $known) as $hit) {
                $this->addCandidate($candidates, $hit);
            }
        }

        foreach ($this->fromConvention($schema, $table, $known, $enums) as $hit) {
            $this->addCandidate($candidates, $hit);
        }

        return $this->pickBest($candidates, $enums, $codes);
    }

    /**
     * `->setJobType(JobTypeEnum::READY->value)` — свойство берётся из имени setter'а,
     * колонка — из карты свойств файла (или конвенции snake_case).
     *
     * @param array{rel: string, content: string, lines: array<int, string>} $file
     * @param array<string, string> $imports
     * @param array<string, string> $propertyMap
     * @param array<string, true>   $known
     * @return array<int, array<string, mixed>>
     */
    private function fromSetters(array $file, array $imports, array $propertyMap, array $known): array
    {
        $hits = [];
        foreach ($file['lines'] as $idx => $line) {
            if (preg_match_all(
                '/->(?:set|with)([A-Z][A-Za-z0-9_]*)\s*\(\s*\\\\?([A-Za-z_\\\\][A-Za-z0-9_\\\\]*)::([A-Za-z_]\w*)/',
                $line,
                $matches,
                PREG_SET_ORDER
            ) === 0) {
                continue;
            }
            foreach ($matches as $match) {
                $column = $this->properties->columnForAccessor($match[1], $propertyMap);
                if ($column === null || !isset($known[$column])) {
                    continue;
                }
                $hits[] = [
                    'column' => $column,
                    'fqcn' => UseStatementResolver::resolve($match[2], $imports),
                    'confidence' => isset($propertyMap[lcfirst($match[1])]) ? 'high' : 'med',
                    'bridge' => 'setter',
                    'evidence' => ['file' => $file['rel'], 'line' => $idx + 1],
                ];
            }
        }

        return $hits;
    }

    /**
     * DQL: `setParameter('status', StatusEnum::NEW)` и `andWhere('c.status = :status')` стоят
     * в одном билдере, но на разных строках — ищем ближайшее упоминание алиаса и свойства.
     *
     * @param array{rel: string, content: string, lines: array<int, string>} $file
     * @param array<string, string> $imports
     * @param array<string, string> $propertyMap
     * @param array<string, true>   $known
     * @return array<int, array<string, mixed>>
     */
    private function fromDql(array $file, array $imports, array $propertyMap, array $known): array
    {
        $hits = [];
        $lines = $file['lines'];
        foreach ($lines as $idx => $line) {
            if (preg_match(
                '/setParameter\s*\(\s*[\'"]:?([A-Za-z_]\w*)[\'"]\s*,\s*\\\\?([A-Za-z_\\\\][A-Za-z0-9_\\\\]*)::([A-Za-z_]\w*)/',
                $line,
                $match
            ) !== 1) {
                continue;
            }
            $parameter = $match[1];
            $fqcn = UseStatementResolver::resolve($match[2], $imports);

            $from = max(0, $idx - self::DQL_WINDOW);
            $to = min(count($lines) - 1, $idx + self::DQL_WINDOW);
            for ($i = $from; $i <= $to; $i++) {
                if (preg_match_all(
                    '/([A-Za-z_]\w*)\.([A-Za-z_]\w*)\s*(?:=|IN|in)\s*\(?\s*:' . preg_quote($parameter, '/') . '\b/',
                    $lines[$i],
                    $where,
                    PREG_SET_ORDER
                ) === 0) {
                    continue;
                }
                foreach ($where as $pair) {
                    $property = $pair[2];
                    $column = isset($propertyMap[$property]) ? $propertyMap[$property] : $this->camelToSnake($property);
                    if (!isset($known[$column])) {
                        continue;
                    }
                    $hits[] = [
                        'column' => $column,
                        'fqcn' => $fqcn,
                        'confidence' => 'high',
                        'bridge' => 'dql',
                        'evidence' => ['file' => $file['rel'], 'line' => $idx + 1],
                    ];
                }
            }
        }

        return $hits;
    }

    /**
     * Сырой SQL: `'... WHERE t1.job_type = ' . JobTypeEnum::READY->value`. Колонка берётся
     * прямо из текста — алиас таблицы тут не важен, важна пара «колонка ↔ enum».
     *
     * @param array{rel: string, content: string, lines: array<int, string>} $file
     * @param array<string, string> $imports
     * @param array<string, true>   $known
     * @return array<int, array<string, mixed>>
     */
    private function fromRawSql(array $file, array $imports, array $known): array
    {
        $hits = [];
        foreach ($file['lines'] as $idx => $line) {
            if (preg_match_all(
                '/(?:[A-Za-z_]\w*\.)?([A-Za-z_]\w*)\s*(?:=|IN|in)\s*\(?\s*[\'"]?\s*\.?\s*\\\\?([A-Za-z_\\\\][A-Za-z0-9_\\\\]*)::([A-Za-z_]\w*)/',
                $line,
                $matches,
                PREG_SET_ORDER
            ) === 0) {
                continue;
            }
            foreach ($matches as $match) {
                $column = $match[1];
                if (!isset($known[$column])) {
                    continue;
                }
                $hits[] = [
                    'column' => $column,
                    'fqcn' => UseStatementResolver::resolve($match[2], $imports),
                    'confidence' => 'med',
                    'bridge' => 'raw_sql',
                    'evidence' => ['file' => $file['rel'], 'line' => $idx + 1],
                ];
            }
        }

        return $hits;
    }

    /**
     * Конвенция имён. `*_id ↔ *IdEnum` берётся только при совпадении домена (namespace enum'а
     * содержит имя схемы или таблицы) — иначе `result_id` притянет чужой `ResultIdEnum`.
     *
     * @param array<string, true> $known
     * @param array{values: array<string, array<int, string>>, cases: array<string, array<string, string>>, backing: array<string, string>, by_short_name: array<string, array<int, string>>} $enums
     * @return array<int, array<string, mixed>>
     */
    private function fromConvention(string $schema, string $table, array $known, array $enums): array
    {
        $hits = [];
        $byShort = $enums['by_short_name'];

        foreach (array_keys($known) as $column) {
            foreach ($this->conventionNames($schema, $table, $column) as $shortName => $needsDomain) {
                foreach ($byShort as $candidateShort => $fqcns) {
                    if (strcasecmp($candidateShort, $shortName) !== 0) {
                        continue;
                    }
                    foreach ($fqcns as $fqcn) {
                        if ($needsDomain && !$this->sameDomain($fqcn, $schema, $table)) {
                            continue;
                        }
                        $hits[] = [
                            'column' => $column,
                            'fqcn' => $fqcn,
                            'confidence' => 'low',
                            'bridge' => 'convention',
                            'evidence' => ['convention' => $candidateShort],
                        ];
                    }
                }
            }
        }

        return $hits;
    }

    /**
     * Имена enum'ов, которые конвенция допускает для колонки: имя => нужен ли доменный фильтр.
     *
     * @return array<string, bool>
     */
    private function conventionNames(string $schema, string $table, string $column): array
    {
        $names = [];
        $studly = $this->studly($column);
        // job_type → JobTypeEnum
        $names[$studly . 'Enum'] = false;

        // <x>_attrs.attr_id → <X>Attr(Dict|Id)?Enum: домен EAV-атрибутов берётся из имени таблицы.
        if ($column === 'attr_id' && substr($table, -6) === '_attrs') {
            $base = $this->studly(substr($table, 0, -6));
            $base = substr($base, -1) === 's' ? substr($base, 0, -1) : $base;
            foreach (['AttrDictEnum', 'AttrEnum', 'AttrIdEnum'] as $suffix) {
                $names[$base . $suffix] = false;
            }
        }

        // *_id → *IdEnum — только при совпадении домена: слишком много одноимённых.
        if (substr($column, -3) === '_id') {
            $names[$studly . 'Enum'] = true;
        }

        return $names;
    }

    /**
     * Домен enum'а: namespace содержит имя схемы или таблицы (без суффиксов).
     */
    private function sameDomain(string $fqcn, string $schema, string $table): bool
    {
        $namespace = strtolower(str_replace('\\', '/', $fqcn));
        foreach ([$schema, $table, rtrim($table, 's')] as $needle) {
            $needle = strtolower((string) $needle);
            if ($needle !== '' && strpos($namespace, $needle) !== false) {
                return true;
            }
        }

        return false;
    }

    private function studly(string $value): string
    {
        $parts = preg_split('/[_\-\s]+/', $value) ?: [];
        $out = '';
        foreach ($parts as $part) {
            $out .= ucfirst($part);
        }

        return $out;
    }

    /**
     * @param array<string, array<string, array<string, mixed>>> $candidates
     * @param array<string, mixed>                               $hit
     */
    private function addCandidate(array &$candidates, array $hit): void
    {
        $column = (string) $hit['column'];
        $fqcn = (string) $hit['fqcn'];
        if ($column === '' || $fqcn === '') {
            return;
        }
        if (!isset($candidates[$column][$fqcn])) {
            $candidates[$column][$fqcn] = [
                'class' => $fqcn,
                'confidence' => $hit['confidence'],
                'bridge' => $hit['bridge'],
                'evidence' => [],
                'hits' => 0,
            ];
        }
        $entry = &$candidates[$column][$fqcn];
        $entry['hits']++;
        if (self::$RANK[$hit['confidence']] < self::$RANK[$entry['confidence']]) {
            $entry['confidence'] = $hit['confidence'];
            $entry['bridge'] = $hit['bridge'];
        }
        if (count($entry['evidence']) < 5 && isset($hit['evidence'])) {
            $entry['evidence'][] = $hit['evidence'];
        }
        unset($entry);
    }

    /**
     * @param array<string, array<string, array<string, mixed>>> $candidates
     * @param array<string, mixed>                               $enums
     * @param array<string, array<int, string>>                  $codes
     * @return array<string, array<string, mixed>>
     */
    private function pickBest(array $candidates, array $enums, array $codes): array
    {
        $result = [];
        foreach ($candidates as $column => $byClass) {
            $ranked = array_values($byClass);
            usort($ranked, function (array $a, array $b): int {
                $byConfidence = self::$RANK[$a['confidence']] <=> self::$RANK[$b['confidence']];

                return $byConfidence !== 0 ? $byConfidence : ($b['hits'] <=> $a['hits']);
            });

            $best = $ranked[0];
            $fqcn = $best['class'];
            $entry = [
                'class' => $fqcn,
                'short' => UseStatementResolver::shortName($fqcn),
                'backing' => isset($enums['backing'][$fqcn]) ? $enums['backing'][$fqcn] : null,
                'cases' => isset($enums['cases'][$fqcn]) ? $enums['cases'][$fqcn] : [],
                'values' => isset($enums['values'][$fqcn]) ? $enums['values'][$fqcn] : [],
                'confidence' => $best['confidence'],
                'bridge' => $best['bridge'],
                'evidence' => $best['evidence'],
                'alternatives' => [],
            ];

            foreach (array_slice($ranked, 1) as $alternative) {
                $entry['alternatives'][] = [
                    'class' => $alternative['class'],
                    'confidence' => $alternative['confidence'],
                    'bridge' => $alternative['bridge'],
                ];
            }
            if ($entry['alternatives'] !== []) {
                $entry['ambiguous'] = true;
            }

            // Конвенция — догадка; но если все case'ы enum'а есть среди кодов колонки,
            // это уже не догадка, а совпадение доменов значений.
            if ($entry['confidence'] === 'low' && isset($codes[$column]) && $entry['values'] !== []) {
                $missing = array_diff(array_map('strval', $entry['values']), array_map('strval', $codes[$column]));
                if ($missing === []) {
                    $entry['confidence'] = 'med';
                    $entry['bridge'] = $entry['bridge'] . '+codes';
                }
            }

            $result[$column] = $entry;
        }

        return $result;
    }
}
