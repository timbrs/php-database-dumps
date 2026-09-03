<?php

namespace Timbrs\DatabaseDumps\Service\Incremental;

/**
 * Тонкая обёртка над `git` для git-сенсора инкремента.
 *
 * Отдельным классом — потому что на схлопнутой истории (`clone --depth=1`, а именно так
 * выглядит история хост-проекта) `git diff` по старому коммиту не работает вовсе, и это
 * штатная ситуация: сенсор обязан отключиться, а не свалиться. Поэтому все методы
 * возвращают `null` вместо исключения.
 *
 * PHP 7.2-совместимо.
 */
class GitHistory
{
    /** @var string */
    private $projectDir;

    public function __construct(string $projectDir)
    {
        $this->projectDir = rtrim($projectDir, '/\\');
    }

    /**
     * Текущий HEAD или `null` (не репозиторий, нет git, нет коммитов).
     */
    public function head(): ?string
    {
        $output = $this->run(['rev-parse', 'HEAD']);

        return $output === null || $output === [] ? null : trim($output[0]);
    }

    /**
     * Есть ли у HEAD предки: на `--depth=1` история схлопнута и сравнивать не с чем.
     */
    public function hasHistory(): bool
    {
        $output = $this->run(['rev-list', '--count', '--max-count=2', 'HEAD']);
        if ($output === null || $output === []) {
            return false;
        }

        return (int) trim($output[0]) > 1;
    }

    /**
     * Файлы, изменившиеся между коммитом и HEAD. `null` — сравнить нельзя
     * (коммита нет в истории, история схлопнута, git недоступен).
     *
     * @return array<int, string>|null
     */
    public function changedFilesSince(string $commit): ?array
    {
        if (!$this->isCommit($commit)) {
            return null;
        }
        $output = $this->run(['diff', '--name-only', $commit . '..HEAD']);
        if ($output === null) {
            return null;
        }

        $files = [];
        foreach ($output as $line) {
            $line = trim($line);
            if ($line !== '') {
                $files[] = $line;
            }
        }

        return $files;
    }

    /**
     * Готовый сенсор для DirtySetBuilder.
     *
     * @return callable
     */
    public function diffSensor()
    {
        $self = $this;

        return function (string $commit) use ($self) {
            return $self->changedFilesSince($commit);
        };
    }

    private function isCommit(string $commit): bool
    {
        if (preg_match('/^[0-9a-f]{7,40}$/i', $commit) !== 1) {
            return false;
        }

        return $this->run(['cat-file', '-e', $commit . '^{commit}']) !== null;
    }

    /**
     * @param array<int, string> $args
     * @return array<int, string>|null
     */
    private function run(array $args): ?array
    {
        if (!is_dir($this->projectDir . '/.git')) {
            return null;
        }
        if (!function_exists('exec')) {
            return null;
        }

        // Аргументы собираются здесь и только здесь: ни одно значение из конфига или
        // из отметки не попадает в командную строку без escapeshellarg.
        $command = 'git -C ' . escapeshellarg($this->projectDir);
        foreach ($args as $arg) {
            $command .= ' ' . escapeshellarg($arg);
        }
        $command .= ' 2>' . (DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null');

        $output = [];
        $code = 0;
        @exec($command, $output, $code);

        return $code === 0 ? $output : null;
    }
}
