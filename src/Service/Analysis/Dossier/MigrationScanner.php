<?php

namespace Timbrs\DatabaseDumps\Service\Analysis\Dossier;

use Symfony\Component\Finder\Finder;

/**
 * Миграции как источник знаний о таблице: когда колонка появилась, что о ней написано
 * по-русски в описании и наполняется ли таблица из самой миграции.
 *
 * Последнее важнее, чем кажется: словари и часть EAV в хосте заливаются DML-миграциями, и
 * такая таблица должна выгружаться целиком — иначе на стенде окажется словарь из тридцати
 * строк вместо трёхсот. Ключей Jira в миграциях нет (проверено), поэтому поле `jira`
 * заполняется, только если ключ действительно найдётся.
 *
 * PHP 7.2-совместимо.
 */
class MigrationScanner
{
    /** Не читаем файлы больше этого размера. */
    const MAX_FILE_SIZE = '< 2M';

    /** Ключ задачи в описании миграции — если он вдруг там есть. */
    const ISSUE_REGEX = '/\b([A-Z][A-Z0-9]{1,9}-\d{1,6})\b/';

    /** @var string */
    private $projectDir;

    /** @var array<int, string> */
    private $directories;

    /** @var array<string, array<string, mixed>>|null */
    private $cache;

    /**
     * @param array<int, string> $directories относительные каталоги миграций
     */
    public function __construct(string $projectDir, array $directories = ['migrations', 'database/migrations'])
    {
        $this->projectDir = rtrim($projectDir, '/\\');
        $this->directories = $directories;
    }

    /**
     * @return array<string, array<string, mixed>> «schema.table» => сведения из миграций
     */
    public function scan(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        $result = [];
        foreach ($this->files() as $file) {
            $content = $this->read($file['path']);
            if ($content === null) {
                continue;
            }
            $version = $file['name'];
            $description = $this->description($content);
            $issue = null;
            if ($description !== null && preg_match(self::ISSUE_REGEX, $description, $im) === 1) {
                $issue = $im[1];
            }

            foreach ($this->tablesOf($content) as $key => $facts) {
                if (!isset($result[$key])) {
                    $result[$key] = [
                        'introduced_in' => $version,
                        'last_changed_in' => $version,
                        'description' => $description,
                        'jira' => $issue,
                        'dml_rows' => 0,
                        'ddl' => [],
                    ];
                }
                $entry = &$result[$key];
                if (strcmp($version, $entry['introduced_in']) < 0) {
                    $entry['introduced_in'] = $version;
                }
                if (strcmp($version, $entry['last_changed_in']) > 0) {
                    $entry['last_changed_in'] = $version;
                    if ($description !== null) {
                        $entry['description'] = $description;
                    }
                }
                if ($issue !== null && $entry['jira'] === null) {
                    $entry['jira'] = $issue;
                }
                $entry['dml_rows'] += $facts['inserts'];
                foreach ($facts['ddl'] as $operation) {
                    if (!in_array($operation, $entry['ddl'], true)) {
                        $entry['ddl'][] = $operation;
                    }
                }
                unset($entry);
            }
        }

        $this->cache = $result;

        return $result;
    }

    /**
     * Что миграция делает с таблицами: DDL-операции и число INSERT'ов (строк наполнения).
     *
     * @return array<string, array{ddl: array<int, string>, inserts: int}>
     */
    private function tablesOf(string $content): array
    {
        $tables = [];
        $add = function (string $table, string $operation, int $inserts) use (&$tables): void {
            $key = strpos($table, '.') === false ? 'public.' . $table : $table;
            $key = str_replace(['"', '`'], '', $key);
            if (!isset($tables[$key])) {
                $tables[$key] = ['ddl' => [], 'inserts' => 0];
            }
            if ($operation !== '' && !in_array($operation, $tables[$key]['ddl'], true)) {
                $tables[$key]['ddl'][] = $operation;
            }
            $tables[$key]['inserts'] += $inserts;
        };

        $patterns = [
            'create_table' => '/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?([\w".`]+)/i',
            'alter_table' => '/ALTER\s+TABLE\s+(?:ONLY\s+)?([\w".`]+)/i',
            'drop_table' => '/DROP\s+TABLE\s+(?:IF\s+EXISTS\s+)?([\w".`]+)/i',
            'create_view' => '/CREATE\s+(?:OR\s+REPLACE\s+)?VIEW\s+([\w".`]+)/i',
        ];
        foreach ($patterns as $operation => $pattern) {
            if (preg_match_all($pattern, $content, $matches) === 0) {
                continue;
            }
            foreach ($matches[1] as $table) {
                $add($table, $operation, 0);
            }
        }

        // Наполнение данными: сколько кортежей заливает миграция — аргумент за full_export.
        if (preg_match_all('/INSERT\s+INTO\s+([\w".`]+)\s*(?:\([^)]*\))?\s*VALUES(.*?)(?:;|$)/is', $content, $matches, PREG_SET_ORDER) > 0) {
            foreach ($matches as $match) {
                $rows = substr_count($match[2], '(');
                $add($match[1], 'insert', max(1, $rows));
            }
        }

        return $tables;
    }

    /**
     * Описание миграции: getDescription() у Doctrine или док-блок класса. По-русски и своими
     * словами — единственное место, где сказано, зачем колонка появилась.
     */
    private function description(string $content): ?string
    {
        if (preg_match('/function\s+getDescription\s*\([^)]*\)\s*:\s*string\s*\{\s*return\s*[\'"](.+?)[\'"]\s*;/s', $content, $match) === 1) {
            return trim($match[1]);
        }
        if (preg_match('/\/\*\*(.*?)\*\//s', $content, $match) === 1) {
            $text = preg_replace('/^\s*\*\s?/m', '', trim($match[1]));
            $text = $text === null ? '' : trim($text);
            if ($text !== '' && stripos($text, 'Auto-generated Migration') === false) {
                return $text;
            }
        }

        return null;
    }

    /**
     * @return array<int, array{path: string, name: string}>
     */
    protected function files(): array
    {
        $dirs = [];
        foreach ($this->directories as $directory) {
            $path = $this->projectDir . '/' . $directory;
            if (is_dir($path)) {
                $dirs[] = $path;
            }
        }
        if ($dirs === []) {
            return [];
        }

        $finder = new Finder();
        $finder->files()->in($dirs)->name('*.php')->size(self::MAX_FILE_SIZE)->ignoreUnreadableDirs();

        $files = [];
        foreach ($finder as $file) {
            $files[] = ['path' => $file->getPathname(), 'name' => $file->getBasename('.php')];
        }
        sort($files);

        return $files;
    }

    protected function read(string $path): ?string
    {
        $content = @file_get_contents($path);

        return $content === false ? null : $content;
    }
}
