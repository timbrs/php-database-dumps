<?php

namespace Timbrs\DatabaseDumps\Service\Analysis\CodeHints;

/**
 * Детектор использования колонок таблицы и их метаданных (enum/cast) по связанному подмножеству файлов.
 *
 * В отличие от per-file детекторов, работает по union файлов, связанных с ОДНОЙ таблицей (чтобы резко
 * снизить шум коротких имён id/name/status). На вход — имена колонок и файлы {rel, content, lines}.
 *
 * Для каждой колонки категоризирует использования:
 *   filter (where/andWhere), join (JOIN…ON / ->join / JoinColumn), order (ORDER BY / ->orderBy),
 *   write (UPDATE…SET / INSERT / $x->col = / ->set(...)), иначе read.
 * Итог по колонке: usages[] + count + 1 sample-сниппет (гасится для шумных/коротких имён).
 *
 * Метаданные: Eloquent $casts['col'=>Enum::class], Doctrine #[ORM\Column(... enumType: X::class)],
 * $casts['col'=>'boolean'] → enum/cast. Значения enum подставляет CodeHintScanner из глобальной карты.
 *
 * PHP 7.2-совместимо.
 */
class ColumnUsageDetector
{
    use TextHelperTrait;

    /** Имя короче — только счётчик, без сниппета (высокий шум). */
    const MIN_NAME_LEN = 3;

    /** Хитов по колонке больше — сниппет не кладём (слишком частая). */
    const NOISY_COUNT = 60;

    /** Максимальная длина строки сниппета (символов). */
    const MAX_SNIPPET_LINE = 200;

    /**
     * @param array<int, string>                                       $columns имена колонок таблицы
     * @param array<int, array{rel: string, content: string, lines: array<int, string>}> $files
     * @return array<string, array<string, mixed>> col => {usages, count, sample?, enum?, cast?}
     */
    public function detect(array $columns, array $files): array
    {
        $result = [];
        $meta = $this->detectMetadata($columns, $files);

        foreach ($columns as $col) {
            if (!is_string($col) || $col === '') {
                continue;
            }
            $usages = [];
            $count = 0;
            $sample = null;
            $re = '/\b' . preg_quote($col, '/') . '\b/';
            $short = $this->length($col) < self::MIN_NAME_LEN;

            foreach ($files as $f) {
                $lines = $f['lines'];
                foreach ($lines as $idx => $line) {
                    if (!preg_match($re, $line)) {
                        continue;
                    }
                    $count++;
                    $u = $this->classifyUsage($line, $col);
                    $usages[$u] = true;
                    if ($sample === null && !$short) {
                        $sample = [
                            'file'    => $f['rel'],
                            'line'    => $idx + 1,
                            'snippet' => $this->snippet($lines, $idx, 1, 1, self::MAX_SNIPPET_LINE),
                        ];
                    }
                }
            }

            if ($count === 0 && !isset($meta[$col])) {
                continue;
            }

            $entry = [
                'usages' => array_keys($usages),
                'count'  => $count,
            ];
            // Шумовой guard: слишком частая колонка — без сниппета.
            if ($sample !== null && $count <= self::NOISY_COUNT) {
                $entry['sample'] = $sample;
            }
            if (isset($meta[$col]['enum'])) {
                $entry['enum'] = $meta[$col]['enum'];
            }
            if (isset($meta[$col]['cast'])) {
                $entry['cast'] = $meta[$col]['cast'];
            }
            $result[$col] = $entry;
        }

        return $result;
    }

    /**
     * Категория использования колонки в строке.
     */
    private function classifyUsage(string $line, string $col): string
    {
        if (preg_match('/\bJOIN\b|->\s*join\s*\(|JoinColumn/i', $line)) {
            return 'join';
        }
        if (preg_match('/ORDER\s+BY|->\s*orderBy\s*\(/i', $line)) {
            return 'order';
        }
        if (preg_match('/\bwhere\b|andWhere|orWhere|WHERE/i', $line)) {
            return 'filter';
        }
        if (preg_match('/\bUPDATE\b|\bINSERT\b|->\s*set\s*\(|\bSET\b/i', $line)
            || preg_match('/->\s*' . preg_quote($col, '/') . '\s*=[^=]/', $line)
            || preg_match('/[\'"]' . preg_quote($col, '/') . '[\'"]\s*=>\s*/', $line)
        ) {
            return 'write';
        }
        return 'read';
    }

    /**
     * Метаданные колонок из entity/model файлов: casts, enumType.
     *
     * @param array<int, string> $columns
     * @param array<int, array{rel: string, content: string, lines: array<int, string>}> $files
     * @return array<string, array<string, mixed>>
     */
    private function detectMetadata(array $columns, array $files): array
    {
        $known = [];
        foreach ($columns as $c) {
            if (is_string($c)) {
                $known[$c] = true;
            }
        }
        $meta = [];

        foreach ($files as $f) {
            $content = $f['content'];

            // FQCN enum'а: короткое имя в хосте неоднозначно (три разных TypeEnum), поэтому
            // разворачиваем его по use-импортам файла, где написан маппинг.
            $imports = UseStatementResolver::imports($content);

            // Eloquent $casts: 'col' => Enum::class | 'boolean' | 'datetime'
            if (preg_match_all('/[\'"]([A-Za-z_][\w]*)[\'"]\s*=>\s*([A-Za-z_\\\\\x{0080}-\x{FFFF}][A-Za-z0-9_\\\\\x{0080}-\x{FFFF}]*)::class/u', $content, $mm, PREG_SET_ORDER)) {
                foreach ($mm as $m) {
                    if (isset($known[$m[1]])) {
                        $meta[$m[1]]['enum'] = [
                            'type' => $this->shortClass($m[2]),
                            'fqcn' => UseStatementResolver::resolve($m[2], $imports),
                            'bridge' => 'casts',
                            'confidence' => 'high',
                            'evidence' => ['file' => $f['rel']],
                        ];
                    }
                }
            }
            if (preg_match_all('/[\'"]([A-Za-z_][\w]*)[\'"]\s*=>\s*[\'"](boolean|bool|integer|int|float|datetime|date|array|json)[\'"]/', $content, $mm, PREG_SET_ORDER)) {
                foreach ($mm as $m) {
                    if (isset($known[$m[1]]) && !isset($meta[$m[1]]['cast'])) {
                        $meta[$m[1]]['cast'] = $m[2];
                    }
                }
            }

            // Doctrine #[ORM\Column(... name: 'col' ... enumType: X::class)] / @ORM\Column(... enumType=...)
            foreach ($f['lines'] as $idx => $line) {
                if (strpos($line, 'enumType') === false) {
                    continue;
                }
                if (!preg_match('/enumType\s*[:=]\s*[\'"]?([A-Za-z_\x{0080}-\x{FFFF}][A-Za-z0-9_\\\\\x{0080}-\x{FFFF}]*)/u', $line, $em)) {
                    continue;
                }
                $col = $this->doctrineColumnName($line, $f['lines'], $idx);
                if ($col !== null && isset($known[$col])) {
                    $meta[$col]['enum'] = [
                        'type' => $this->shortClass($em[1]),
                        'fqcn' => UseStatementResolver::resolve($em[1], $imports),
                        'bridge' => 'enum_type',
                        'confidence' => 'high',
                        'evidence' => ['file' => $f['rel'], 'line' => $idx + 1],
                    ];
                }
            }
        }

        return $meta;
    }

    /**
     * Имя колонки для Doctrine #[ORM\Column]: явный name или имя свойства (со snake_case-вариантом).
     *
     * @param array<int, string> $lines
     * @return string|null
     */
    private function doctrineColumnName(string $line, array $lines, int $idx)
    {
        if (preg_match('/name\s*[:=]\s*[\'"]([^\'"]+)[\'"]/', $line, $nm)) {
            return $nm[1];
        }
        // Имя свойства на этой или следующих строках: private ?Type $prop;
        $to = min(count($lines) - 1, $idx + 3);
        for ($i = $idx; $i <= $to; $i++) {
            if (preg_match('/\$([a-zA-Z_]\w*)/', $lines[$i], $pm)) {
                return $this->camelToSnake($pm[1]);
            }
        }
        return null;
    }

}
