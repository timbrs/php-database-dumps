<?php

namespace Timbrs\DatabaseDumps\Service\Analysis\CodeHints;

/**
 * Per-file stateless-детектор кандидатов бизнес-сегментов (для sample.criteria) и enum/const-значений.
 *
 * Возвращает сырые кандидаты; целевую таблицу проставляет CodeHintScanner по primaryClass файла
 * (модель/энтити/репозиторий → таблица через classMap). Точность вторична — агент финализирует SQL.
 *
 * Сигналы:
 *  - Eloquent scope: `public function scopeActive(` → name=active; тело (`->where('col','val')`) → черновой where;
 *  - методы репозиториев (kind=repository): `findActive/findByStatus` + `->andWhere('o.status = :status')`/`->where(...)`;
 *  - PHP enum: `enum XStatus: string { case Active = 'active'; }` → значения (origin=enum);
 *  - константы: `const STATUS_ACTIVE = 'active';` → значения (origin=const).
 *
 * PHP 7.2-совместимо.
 */
class CriteriaDetector
{
    use TextHelperTrait;

    /** Максимум строк тела метода/сегмента, которые осматриваем для черновика where. */
    const BODY_SCAN = 25;

    /**
     * @param array<int, string> $lines
     * @param bool               $isRepository файл — репозиторий (таблица известна из classMap)
     * @return array<int, array<string, mixed>> сырые кандидаты
     */
    public function detect(string $content, array $lines, string $rel, bool $isRepository = false): array
    {
        $out = [];
        foreach ($this->detectScopes($lines) as $c) {
            $out[] = $c;
        }
        if ($isRepository) {
            foreach ($this->detectRepositoryMethods($lines) as $c) {
                $out[] = $c;
            }
        }
        foreach ($this->detectEnums($content, $lines) as $c) {
            $out[] = $c;
        }
        foreach ($this->detectConstants($lines) as $c) {
            $out[] = $c;
        }
        foreach ($out as &$c) {
            $c['file'] = $rel;
        }
        unset($c);
        return $out;
    }

    /**
     * Eloquent scopes: scopeActive → name=active + черновой where из тела.
     *
     * @param array<int, string> $lines
     * @return array<int, array<string, mixed>>
     */
    private function detectScopes(array $lines): array
    {
        $out = [];
        foreach ($lines as $idx => $line) {
            if (!preg_match('/function\s+scope([A-Z][A-Za-z0-9_]*)\s*\(/', $line, $m)) {
                continue;
            }
            $name = $this->lcfirst($m[1]);
            $out[] = [
                'name'       => $name,
                'where'      => $this->extractWhere($lines, $idx),
                'origin'     => 'eloquent_scope',
                'confidence' => 'low',
                'line'       => $idx + 1,
                'snippet'    => $this->snippet($lines, $idx, 1, 2),
            ];
        }
        return $out;
    }

    /**
     * Методы репозиториев findActive/findByStatus + фрагменты where/andWhere.
     *
     * @param array<int, string> $lines
     * @return array<int, array<string, mixed>>
     */
    private function detectRepositoryMethods(array $lines): array
    {
        $out = [];
        foreach ($lines as $idx => $line) {
            if (!preg_match('/function\s+((?:find|get|fetch|select|count)[A-Z][A-Za-z0-9_]*)\s*\(/', $line, $m)) {
                continue;
            }
            $where = $this->extractWhere($lines, $idx);
            if ($where === '') {
                continue; // без фрагмента WHERE метод бесполезен как сегмент
            }
            $out[] = [
                'name'       => $this->normalizeName($m[1]),
                'where'      => $where,
                'origin'     => 'repository_method',
                'confidence' => 'low',
                'line'       => $idx + 1,
                'snippet'    => $this->snippet($lines, $idx, 1, 2),
            ];
        }
        return $out;
    }

    /**
     * PHP enum backed by string: значения кейсов.
     *
     * @param array<int, string> $lines
     * @return array<int, array<string, mixed>>
     */
    private function detectEnums(string $content, array $lines): array
    {
        if (!preg_match('/\benum\s+([A-Za-z_\x{0080}-\x{FFFF}][A-Za-z0-9_\x{0080}-\x{FFFF}]*)\s*:\s*(?:string|int)\b/u', $content, $em)) {
            return [];
        }
        $enum = $em[1];
        $values = [];
        $line = 0;
        foreach ($lines as $idx => $l) {
            if (preg_match('/\bcase\s+[A-Za-z_]\w*\s*=\s*[\'"]([^\'"]+)[\'"]/', $l, $cm)) {
                $values[] = $cm[1];
                if ($line === 0) {
                    $line = $idx + 1;
                }
            }
        }
        if (empty($values)) {
            return [];
        }
        return [[
            'origin'     => 'enum',
            'enum_type'  => $enum,
            'values'     => array_values(array_unique($values)),
            'confidence' => 'med',
            'line'       => $line,
        ]];
    }

    /**
     * Строковые константы STATUS_* / TYPE_* → значения (кандидаты сегментов/enum колонки).
     *
     * @param array<int, string> $lines
     * @return array<int, array<string, mixed>>
     */
    private function detectConstants(array $lines): array
    {
        $values = [];
        $line = 0;
        foreach ($lines as $idx => $l) {
            if (preg_match('/\bconst\s+[A-Z][A-Z0-9_]*\s*=\s*[\'"]([^\'"]+)[\'"]/', $l, $m)) {
                $values[] = $m[1];
                if ($line === 0) {
                    $line = $idx + 1;
                }
            }
        }
        if (empty($values)) {
            return [];
        }
        return [[
            'origin'     => 'const',
            'values'     => array_values(array_unique($values)),
            'confidence' => 'low',
            'line'       => $line,
        ]];
    }

    /**
     * Черновой WHERE из тела метода: первые ->where/andWhere/orWhere в пределах BODY_SCAN строк.
     *
     * @param array<int, string> $lines
     */
    private function extractWhere(array $lines, int $startIdx): string
    {
        $to = min(count($lines) - 1, $startIdx + self::BODY_SCAN);
        $parts = [];
        for ($i = $startIdx; $i <= $to; $i++) {
            if (preg_match_all('/->\s*(?:andWhere|orWhere|where)\s*\(\s*[\'"]([^\'"]+)[\'"]/', $lines[$i], $mm)) {
                foreach ($mm[1] as $frag) {
                    $frag = trim($frag);
                    if ($frag !== '' && (strpos($frag, '=') !== false || preg_match('/[<>]|LIKE|IN|IS/i', $frag))) {
                        $parts[] = $frag;
                    }
                }
            }
            // Eloquent ->where('col', 'val') → col = 'val'
            if (preg_match_all('/->\s*where\s*\(\s*[\'"]([A-Za-z_][\w.]*)[\'"]\s*,\s*[\'"]([^\'"]+)[\'"]\s*\)/', $lines[$i], $mm2, PREG_SET_ORDER)) {
                foreach ($mm2 as $w) {
                    $parts[] = $w[1] . " = '" . $w[2] . "'";
                }
            }
        }
        $parts = array_values(array_unique($parts));
        return implode(' AND ', $parts);
    }

    /**
     * findActive → active; findByStatus → by_status.
     */
    private function normalizeName(string $method): string
    {
        $name = preg_replace('/^(find|get|fetch|select|count)/', '', $method);
        if (!is_string($name) || $name === '') {
            $name = $method;
        }
        return $this->camelToSnake($name);
    }

    private function lcfirst(string $s): string
    {
        if ($s === '') {
            return $s;
        }
        return $this->lower(substr($s, 0, 1)) . substr($s, 1);
    }
}
