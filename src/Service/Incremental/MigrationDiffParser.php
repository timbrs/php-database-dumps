<?php

namespace Timbrs\DatabaseDumps\Service\Incremental;

use Timbrs\DatabaseDumps\Service\Analysis\Dossier\MigrationScanner;

/**
 * Какие таблицы затронуты миграциями новее данной версии.
 *
 * Главный сенсор инкремента: колонка в этом проекте появляется миграцией, и если после
 * прошлого прогона миграций не было, перепроверять таблицу нечего. Разбор берётся у
 * MigrationScanner — второй набор регулярок на те же `addSql` разошёлся бы с первым.
 *
 * Версии Doctrine (`VersionYYYYMMDDHHMMSS`) сравниваются лексикографически: это тот же
 * порядок, в котором их применяет сам Doctrine.
 *
 * PHP 7.2-совместимо.
 */
class MigrationDiffParser
{
    /** @var MigrationScanner */
    private $scanner;

    public function __construct(MigrationScanner $scanner)
    {
        $this->scanner = $scanner;
    }

    /**
     * Самая новая миграция в проекте — её и записывает checkpoint.
     */
    public function newestVersion(): ?string
    {
        return $this->scanner->newestVersion();
    }

    /**
     * Версии строго новее `$since`. `null` — все (первый прогон, отметки нет).
     *
     * @return array<int, string>
     */
    public function versionsSince(?string $since): array
    {
        $versions = [];
        foreach ($this->scanner->parsed() as $migration) {
            if ($since === null || strcmp($migration['version'], $since) > 0) {
                $versions[] = $migration['version'];
            }
        }

        return $versions;
    }

    /**
     * Таблицы, затронутые миграциями новее `$since`, с причиной.
     *
     * `ddl` и `dml_rows` считаются только по новым миграциям — иначе в причине оказалось
     * бы «наполняется миграцией» из-за миграции трёхлетней давности.
     *
     * @return array<string, array{versions: array<int, string>, ddl: array<int, string>, dml_rows: int, jira: string|null, description: string|null}>
     */
    public function tablesChangedSince(?string $since): array
    {
        $result = [];
        foreach ($this->scanner->parsed() as $migration) {
            if ($since !== null && strcmp($migration['version'], $since) <= 0) {
                continue;
            }
            foreach ($migration['tables'] as $key => $facts) {
                if (!isset($result[$key])) {
                    $result[$key] = [
                        'versions' => [],
                        'ddl' => [],
                        'dml_rows' => 0,
                        'jira' => null,
                        'description' => null,
                    ];
                }
                $result[$key]['versions'][] = $migration['version'];
                foreach ($facts['ddl'] as $operation) {
                    if (!in_array($operation, $result[$key]['ddl'], true)) {
                        $result[$key]['ddl'][] = $operation;
                    }
                }
                $result[$key]['dml_rows'] += $facts['inserts'];
                if ($result[$key]['jira'] === null && $migration['jira'] !== null) {
                    $result[$key]['jira'] = $migration['jira'];
                }
                // Описание последней из новых миграций — самое свежее объяснение.
                if ($migration['description'] !== null) {
                    $result[$key]['description'] = $migration['description'];
                }
            }
        }
        ksort($result);

        return $result;
    }

    /**
     * Версии, к которым относятся эти файлы миграций (для git-сенсора: `git diff` даёт
     * пути, а сопоставлять их с таблицами умеет только разбор).
     *
     * @param array<int, string> $paths пути файлов (любые, лишние игнорируются)
     *
     * @return array<int, string>
     */
    public function versionsOfFiles(array $paths): array
    {
        $names = [];
        foreach ($paths as $path) {
            $names[basename(str_replace('\\', '/', $path), '.php')] = true;
        }

        $versions = [];
        foreach ($this->scanner->parsed() as $migration) {
            if (isset($names[$migration['version']])) {
                $versions[] = $migration['version'];
            }
        }

        return $versions;
    }

    /**
     * Таблицы этих версий миграций.
     *
     * @param array<int, string> $versions
     *
     * @return array<int, string>
     */
    public function tablesOfVersions(array $versions): array
    {
        if ($versions === []) {
            return [];
        }
        $wanted = array_flip($versions);

        $tables = [];
        foreach ($this->scanner->parsed() as $migration) {
            if (!isset($wanted[$migration['version']])) {
                continue;
            }
            foreach (array_keys($migration['tables']) as $key) {
                $tables[(string) $key] = true;
            }
        }
        $list = array_keys($tables);
        sort($list);

        return $list;
    }
}
