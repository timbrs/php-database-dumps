<?php

namespace Timbrs\DatabaseDumps\Service\Analysis\CodeHints;

/**
 * Упорядоченный replay миграций для восстановления «выживших» FK-связей.
 *
 * Миграции НЕЛЬЗЯ обрабатывать как независимые файлы: FK, добавленный ранней миграцией,
 * может быть снят более поздней. Поэтому:
 *  1) собираем миграции и сортируем хронологически по имени файла (таймстемп в имени);
 *  2) для каждой берём тело up() (без down()/rollback — это отмена, не прямое состояние);
 *  3) проигрываем ADD/DROP как события над множеством FK (ключ source_table.source_column→target_table);
 *  4) отдаём только выжившие связи как кандидаты origin: migration.
 *
 * Поддержаны и fluent-билдер (Laravel), и чистый SQL внутри миграций (addSql/DB::statement/heredoc):
 *  ADD  — ->foreign('c')->references('r')->on('t'); foreignId('c')->constrained('t');
 *         raw FOREIGN KEY (c) REFERENCES t (r) (в т.ч. в CREATE TABLE); ALTER TABLE … ADD … FOREIGN KEY …;
 *  DROP — dropForeign(['c']); dropConstrainedForeignId('c'); DROP FOREIGN KEY/CONSTRAINT …;
 *         DROP COLUMN c / dropColumn('c') убирает связи по колонке.
 *
 * Это эвристика по тексту, не полноценный SQL-парсер: сложные случаи (переименования, условный SQL)
 * можем пропустить — финальную проверку делает агент + сверка in_db_fk.
 *
 * PHP 7.2-совместимо.
 */
class MigrationFkResolver
{
    use TextHelperTrait;

    /** Уверенность для явно указанной target-таблицы. */
    const CONF_HIGH = 'high';

    /** Уверенность для выведенной target-таблицы (constrained() без аргумента). */
    const CONF_MED = 'med';

    /**
     * @param array<string, string> $migrationFiles rel-путь => содержимое (уже отфильтрованы как миграции)
     * @return array<int, array<string, mixed>> кандидаты: {source_table, source_column, target_table,
     *         target_column, kind, origin:'migration', confidence, file, line}. Таблицы — «голые» имена.
     */
    public function resolve(array $migrationFiles): array
    {
        $files = array_keys($migrationFiles);
        // Сортировка по basename (таймстемп в имени задаёт порядок применения).
        usort($files, function ($a, $b) {
            return strcmp($this->baseName($a), $this->baseName($b));
        });

        /** @var array<string, array<string, mixed>> $fks ключ → edge */
        $fks = [];
        foreach ($files as $rel) {
            $body = $this->upBody($migrationFiles[$rel]);
            $events = $this->collectEvents($body, $rel);
            foreach ($events as $ev) {
                $this->applyEvent($fks, $ev);
            }
        }

        return array_values($fks);
    }

    /**
     * Тело up(): от `function up(` до `function down(` (или конца). Для .sql — весь текст.
     */
    private function upBody(string $content): string
    {
        if (substr($content, 0, 5) !== '<?php' && stripos($content, 'function up') === false) {
            return $content; // сырой .sql
        }
        if (!preg_match('/function\s+up\s*\(/i', $content, $m, PREG_OFFSET_CAPTURE)) {
            return $content;
        }
        $start = $m[0][1];
        $rest = substr($content, $start);
        if (preg_match('/function\s+down\s*\(/i', $rest, $dm, PREG_OFFSET_CAPTURE)) {
            $rest = substr($rest, 0, $dm[0][1]);
        }
        return $rest;
    }

    /**
     * Собрать события ADD/DROP в порядке появления, каждому — ближайшую предшествующую таблицу-контекст.
     *
     * @return array<int, array<string, mixed>>
     */
    private function collectEvents(string $body, string $rel): array
    {
        $contexts = $this->collectContexts($body);
        $events = [];

        // ADD: fluent ->foreign('c') [ -> ... ->references('r') ... ->on('t') ]
        if (preg_match_all('/->\s*foreign\s*\(\s*\[?\s*[\'"]([^\'"]+)[\'"]/', $body, $mm, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
            foreach ($mm as $m) {
                $col = $m[1][0];
                $off = $m[0][1];
                $seg = $this->segmentAfter($body, $off);
                $target = null;
                $refCol = 'id';
                if (preg_match('/->\s*on\s*\(\s*[\'"]([^\'"]+)[\'"]/', $seg, $on)) {
                    $target = $on[1];
                }
                if (preg_match('/->\s*references\s*\(\s*[\'"]([^\'"]+)[\'"]/', $seg, $r)) {
                    $refCol = $r[1];
                }
                if ($target !== null) {
                    $events[] = $this->addEvent($contexts, $off, $col, $target, $refCol, self::CONF_HIGH, $rel, $body);
                }
            }
        }

        // ADD: foreignId('c')->constrained('t')
        if (preg_match_all('/foreignId\s*\(\s*[\'"]([^\'"]+)[\'"]\s*\)/', $body, $mm, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
            foreach ($mm as $m) {
                $col = $m[1][0];
                $off = $m[0][1];
                $seg = $this->segmentAfter($body, $off);
                if (!preg_match('/->\s*constrained\s*\(([^)]*)\)/', $seg, $cm)) {
                    continue;
                }
                $target = null;
                if (preg_match('/[\'"]([^\'"]+)[\'"]/', $cm[1], $tm)) {
                    $target = $tm[1];
                    $conf = self::CONF_HIGH;
                } else {
                    $target = $this->inferTable($col);
                    $conf = self::CONF_MED;
                }
                if ($target !== null) {
                    $events[] = $this->addEvent($contexts, $off, $col, $target, 'id', $conf, $rel, $body);
                }
            }
        }

        // ADD: raw FOREIGN KEY (c) REFERENCES t (r)
        if (preg_match_all('/FOREIGN\s+KEY\s*\(\s*["\'`]?([\w]+)["\'`]?\s*\)\s*REFERENCES\s+["\'`]?([\w.]+)["\'`]?\s*\(\s*["\'`]?([\w]+)/i', $body, $mm, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
            foreach ($mm as $m) {
                $col = $m[1][0];
                $target = $this->bare($m[2][0]);
                $refCol = $m[3][0];
                $off = $m[0][1];
                $events[] = $this->addEvent($contexts, $off, $col, $target, $refCol, self::CONF_HIGH, $rel, $body);
            }
        }

        // DROP: dropForeign(['c'])
        if (preg_match_all('/dropForeign\s*\(\s*\[?\s*[\'"]([^\'"]+)[\'"]/', $body, $mm, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
            foreach ($mm as $m) {
                $events[] = $this->dropByColumn($contexts, $m[0][1], $m[1][0]);
            }
        }
        // DROP: dropConstrainedForeignId('c')
        if (preg_match_all('/dropConstrainedForeignId\s*\(\s*[\'"]([^\'"]+)[\'"]/', $body, $mm, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
            foreach ($mm as $m) {
                $events[] = $this->dropByColumn($contexts, $m[0][1], $m[1][0]);
            }
        }
        // DROP: dropColumn('c') / DROP COLUMN c
        if (preg_match_all('/dropColumn\s*\(\s*\[?\s*[\'"]([^\'"]+)[\'"]/', $body, $mm, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
            foreach ($mm as $m) {
                $events[] = $this->dropByColumn($contexts, $m[0][1], $m[1][0]);
            }
        }
        if (preg_match_all('/DROP\s+COLUMN\s+["\'`]?([\w]+)/i', $body, $mm, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
            foreach ($mm as $m) {
                $events[] = $this->dropByColumn($contexts, $m[0][1], $m[1][0]);
            }
        }
        // DROP: DROP FOREIGN KEY/CONSTRAINT <name> — снимаем связи на текущей таблице (без точной колонки).
        if (preg_match_all('/DROP\s+(?:FOREIGN\s+KEY|CONSTRAINT)\s+["\'`]?([\w]+)/i', $body, $mm, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
            foreach ($mm as $m) {
                $events[] = [
                    'type' => 'drop_table',
                    'off'  => $m[0][1],
                    'table' => $this->contextFor($contexts, $m[0][1]),
                ];
            }
        }

        usort($events, [$this, 'compareByOffset']);
        return $events;
    }

    /**
     * @param array<int, array{off:int, table:string}> $contexts
     * @return array<string, mixed>
     */
    private function addEvent(array $contexts, int $off, string $col, string $target, string $refCol, string $conf, string $rel, string $body): array
    {
        return [
            'type'         => 'add',
            'off'          => $off,
            'source_table' => $this->contextFor($contexts, $off),
            'source_column' => $col,
            'target_table' => $target,
            'target_column' => $refCol,
            'confidence'   => $conf,
            'file'         => $rel,
            'line'         => $this->lineAt($body, $off),
        ];
    }

    /**
     * @param array<int, array{off:int, table:string}> $contexts
     * @return array<string, mixed>
     */
    private function dropByColumn(array $contexts, int $off, string $col): array
    {
        return [
            'type'          => 'drop_col',
            'off'           => $off,
            'source_table'  => $this->contextFor($contexts, $off),
            'source_column' => $col,
        ];
    }

    /**
     * Применить событие к множеству FK.
     *
     * @param array<string, array<string, mixed>> $fks
     * @param array<string, mixed>                $ev
     */
    private function applyEvent(array &$fks, array $ev): void
    {
        if ($ev['type'] === 'add') {
            if ($ev['source_table'] === '' || $ev['target_table'] === '') {
                return;
            }
            $key = $ev['source_table'] . '.' . $ev['source_column'] . '->' . $ev['target_table'];
            $fks[$key] = [
                'source_table'  => $ev['source_table'],
                'source_column' => $ev['source_column'],
                'target_table'  => $ev['target_table'],
                'target_column' => $ev['target_column'],
                'kind'          => 'belongs_to',
                'origin'        => 'migration',
                'confidence'    => $ev['confidence'],
                'file'          => $ev['file'],
                'line'          => $ev['line'],
            ];
            return;
        }
        if ($ev['type'] === 'drop_col') {
            foreach (array_keys($fks) as $key) {
                if ($fks[$key]['source_table'] === $ev['source_table']
                    && $fks[$key]['source_column'] === $ev['source_column']
                ) {
                    unset($fks[$key]);
                }
            }
            return;
        }
        if ($ev['type'] === 'drop_table' && $ev['table'] !== '') {
            // Снять все FK на этой таблице (имя констрейнта к колонке не привязать).
            foreach (array_keys($fks) as $key) {
                if ($fks[$key]['source_table'] === $ev['table']) {
                    unset($fks[$key]);
                }
            }
        }
    }

    /**
     * Все контексты «текущей таблицы» с офсетами: Schema::create/table, ALTER/CREATE TABLE.
     *
     * @return array<int, array{off:int, table:string}>
     */
    private function collectContexts(string $body): array
    {
        $ctx = [];
        if (preg_match_all('/Schema::\s*(?:create|table|drop|dropIfExists)\s*\(\s*[\'"]([^\'"]+)[\'"]/', $body, $mm, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
            foreach ($mm as $m) {
                $ctx[] = ['off' => $m[0][1], 'table' => $this->bare($m[1][0])];
            }
        }
        if (preg_match_all('/(?:ALTER|CREATE)\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?["\'`]?([\w.]+)["\'`]?/i', $body, $mm, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
            foreach ($mm as $m) {
                $ctx[] = ['off' => $m[0][1], 'table' => $this->bare($m[1][0])];
            }
        }
        usort($ctx, [$this, 'compareByOffset']);
        return $ctx;
    }

    /**
     * @param array<int, array{off:int, table:string}> $contexts
     */
    private function contextFor(array $contexts, int $off): string
    {
        $table = '';
        foreach ($contexts as $c) {
            if ($c['off'] <= $off) {
                $table = $c['table'];
            } else {
                break;
            }
        }
        return $table;
    }

    /**
     * Сегмент от офсета до конца выражения (`;`) — область поиска references/on/constrained.
     */
    private function segmentAfter(string $body, int $off): string
    {
        $rest = substr($body, $off);
        $pos = strpos($rest, ';');
        return $pos === false ? $rest : substr($rest, 0, $pos);
    }

    /**
     * Вывести имя target-таблицы из колонки по Laravel-конвенции (client_id → clients).
     *
     * @return string|null
     */
    private function inferTable(string $column)
    {
        $base = preg_replace('/_id$/', '', $column);
        if (!is_string($base) || $base === '') {
            return null;
        }
        return $base . 's';
    }

    private function lineAt(string $body, int $off): int
    {
        return substr_count(substr($body, 0, $off), "\n") + 1;
    }

    private function baseName(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        $pos = strrpos($path, '/');
        return $pos === false ? $path : substr($path, $pos + 1);
    }

    /** Имя таблицы без кавычек/бэктиков и без префикса схемы. */
    private function bare(string $name): string
    {
        return $this->bareName(trim($name, '"\'`'));
    }

    /**
     * Компаратор по возрастанию офсета (для usort событий/контекстов).
     *
     * @param array{off:int} $a
     * @param array{off:int} $b
     */
    private function compareByOffset(array $a, array $b): int
    {
        return $a['off'] - $b['off'];
    }
}
