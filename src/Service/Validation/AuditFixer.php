<?php

namespace Timbrs\DatabaseDumps\Service\Validation;

use Symfony\Component\Yaml\Yaml;
use Timbrs\DatabaseDumps\Config\DumpConfig;
use Timbrs\DatabaseDumps\Config\TableConfig;
use Timbrs\DatabaseDumps\Contract\FileSystemInterface;
use Timbrs\DatabaseDumps\Contract\LoggerInterface;

/**
 * Применение находок, у которых правка механически однозначна.
 *
 * Сюда попадает только то, что не меняет СОСТАВ выборки: снятие faker-маппинга, который
 * заведомо ломает INSERT или указывает в никуда, удаление мёртвой записи cascade_from
 * (её всё равно молча отбрасывает CascadeWhereResolver), переименование дубля критерия,
 * удаление пустой секции. Всё, что меняет, какие строки попадут в дамп, остаётся человеку.
 *
 * Правки пишутся в тот файл, откуда пришла схема (пер-схемный `dump-settings/<schema>.yaml`
 * или общий конфиг). Тронутый файл переписывается целиком через Yaml::dump, то есть его
 * форматирование становится каноническим: записи cascade_from разворачиваются из однострочной
 * формы в блочную. Файлы, где чинить нечего, не трогаются вовсе.
 *
 * После правок содержимое проверяется тем же TableConfig, что и на экспорте: если правка
 * почему-то сделала конфиг хуже, файл не записывается.
 */
class AuditFixer
{
    /** Уровень вложенности, с которого Yaml::dump переходит на однострочную запись. */
    private const INLINE_LEVEL_SCHEMA_FILE = 6;

    /** В общем конфиге схема добавляет ещё один уровень вложенности. */
    private const INLINE_LEVEL_MAIN_FILE = 7;

    private const INDENT = 2;

    /** Маркер «секции в файле нет» — отличаем от `section:` с пустым значением. */
    private const MISSING = "\x00missing";

    /** @var FileSystemInterface */
    private $fileSystem;

    /** @var LoggerInterface|null */
    private $logger;

    public function __construct(FileSystemInterface $fileSystem, LoggerInterface $logger = null)
    {
        $this->fileSystem = $fileSystem;
        $this->logger = $logger;
    }

    /**
     * @param array<int, Finding> $findings находки аудита (нефиксабельные игнорируются)
     * @return array{applied: int, skipped: int, files: array<int, string>, by_code: array<string, int>, errors: array<int, string>}
     */
    public function fix(ConfigDocument $config, array $findings): array
    {
        $result = ['applied' => 0, 'skipped' => 0, 'files' => [], 'by_code' => [], 'errors' => []];

        $byFile = [];
        foreach ($findings as $finding) {
            $schema = $finding->getSchema();
            if (!$finding->isFixable() || $schema === null) {
                continue;
            }
            $suggestion = $finding->getSuggestion();
            if (!isset($suggestion['fix'])) {
                continue;
            }
            $file = $config->getSourceFile($schema);
            if (!isset($byFile[$file])) {
                $byFile[$file] = [];
            }
            $byFile[$file][] = ['schema' => $schema, 'finding' => $finding];
        }

        foreach ($byFile as $file => $items) {
            $this->fixFile($config, (string) $file, $items, $result);
        }

        ksort($result['by_code']);
        return $result;
    }

    /**
     * @param array<int, array{schema: string, finding: Finding}> $items
     * @param array{applied: int, skipped: int, files: array<int, string>, by_code: array<string, int>, errors: array<int, string>} $result
     */
    private function fixFile(ConfigDocument $config, string $file, array $items, array &$result): void
    {
        if (!$this->fileSystem->exists($file)) {
            $result['errors'][] = sprintf('%s: файл не найден, правки пропущены', $file);
            $result['skipped'] += count($items);
            return;
        }

        try {
            $data = Yaml::parse($this->fileSystem->read($file));
        } catch (\Throwable $e) {
            $result['errors'][] = sprintf('%s: YAML не разобран (%s), правки пропущены', $file, $e->getMessage());
            $result['skipped'] += count($items);
            return;
        }
        if (!is_array($data)) {
            $result['errors'][] = sprintf('%s: ожидался YAML-объект, правки пропущены', $file);
            $result['skipped'] += count($items);
            return;
        }

        $isSchemaFile = $file !== $config->getConfigPath();
        $applied = 0;
        $appliedCodes = [];

        // Порядок важен: сначала точечные удаления внутри секций, потом удаление пустых
        // секций — иначе секция, опустевшая после правки, останется в файле.
        foreach ($this->orderedItems($items) as $item) {
            $finding = $item['finding'];
            if ($this->applyOne($data, $isSchemaFile, $item['schema'], $finding)) {
                $applied++;
                $appliedCodes[] = $finding->getCode();
            } else {
                $result['skipped']++;
            }
        }

        if ($applied === 0) {
            return;
        }

        $problem = $this->validate($data, $isSchemaFile);
        if ($problem !== null) {
            $result['errors'][] = sprintf('%s: правки отменены — %s', $file, $problem);
            $result['skipped'] += $applied;
            return;
        }

        $inline = $isSchemaFile ? self::INLINE_LEVEL_SCHEMA_FILE : self::INLINE_LEVEL_MAIN_FILE;
        $this->fileSystem->write($file, Yaml::dump($data, $inline, self::INDENT));

        $result['applied'] += $applied;
        $result['files'][] = $file;
        foreach ($appliedCodes as $code) {
            $result['by_code'][$code] = isset($result['by_code'][$code]) ? $result['by_code'][$code] + 1 : 1;
        }
        $this->info(sprintf('%s: применено правок %d', $file, $applied));
    }

    /**
     * Удаление пустых секций — последним: до него секция ещё может опустеть.
     *
     * @param array<int, array{schema: string, finding: Finding}> $items
     * @return array<int, array{schema: string, finding: Finding}>
     */
    private function orderedItems(array $items): array
    {
        $first = [];
        $last = [];
        foreach ($items as $item) {
            $suggestion = $item['finding']->getSuggestion();
            $fix = isset($suggestion['fix']) ? (string) $suggestion['fix'] : '';
            if ($fix === 'remove_section') {
                $last[] = $item;
            } else {
                $first[] = $item;
            }
        }
        return array_merge($first, $last);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function applyOne(array &$data, bool $isSchemaFile, string $schema, Finding $finding): bool
    {
        $suggestion = $finding->getSuggestion();
        $fix = (string) $suggestion['fix'];

        switch ($fix) {
            case 'remove_faker_column':
                return $this->removeFakerColumn($data, $isSchemaFile, $schema, $suggestion);
            case 'remove_faker_table':
                return $this->removeFakerTable($data, $isSchemaFile, $schema, $suggestion);
            case 'remove_cascade_entry':
                return $this->removeCascadeEntry($data, $isSchemaFile, $schema, $finding, $suggestion);
            case 'rename_criterion':
                return $this->renameCriterion($data, $isSchemaFile, $schema, $finding, $suggestion);
            case 'remove_section':
                return $this->removeSection($data, $isSchemaFile, $schema, $suggestion);
            default:
                return false;
        }
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $suggestion
     */
    private function removeFakerColumn(array &$data, bool $isSchemaFile, string $schema, array $suggestion): bool
    {
        $table = isset($suggestion['table']) ? (string) $suggestion['table'] : '';
        $column = isset($suggestion['column']) ? (string) $suggestion['column'] : '';
        $faker = $this->readSection($data, $isSchemaFile, $schema, DumpConfig::KEY_FAKER);

        if ($table === '' || $column === '' || !is_array($faker)
            || !isset($faker[$table]) || !is_array($faker[$table])
            || !array_key_exists($column, $faker[$table])) {
            return false;
        }

        unset($faker[$table][$column]);
        if (empty($faker[$table])) {
            unset($faker[$table]);
        }
        $this->writeSection($data, $isSchemaFile, $schema, DumpConfig::KEY_FAKER, empty($faker) ? null : $faker);

        return true;
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $suggestion
     */
    private function removeFakerTable(array &$data, bool $isSchemaFile, string $schema, array $suggestion): bool
    {
        $table = isset($suggestion['table']) ? (string) $suggestion['table'] : '';
        $faker = $this->readSection($data, $isSchemaFile, $schema, DumpConfig::KEY_FAKER);

        if ($table === '' || !is_array($faker) || !array_key_exists($table, $faker)) {
            return false;
        }

        unset($faker[$table]);
        $this->writeSection($data, $isSchemaFile, $schema, DumpConfig::KEY_FAKER, empty($faker) ? null : $faker);

        return true;
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $suggestion
     */
    private function removeCascadeEntry(
        array &$data,
        bool $isSchemaFile,
        string $schema,
        Finding $finding,
        array $suggestion
    ): bool {
        $table = $finding->getTable();
        $expected = isset($suggestion['entry']) && is_array($suggestion['entry']) ? $suggestion['entry'] : [];
        $partial = $this->readSection($data, $isSchemaFile, $schema, DumpConfig::KEY_PARTIAL_EXPORT);

        if ($table === null || empty($expected) || !is_array($partial)
            || !isset($partial[$table][TableConfig::KEY_CASCADE_FROM])
            || !is_array($partial[$table][TableConfig::KEY_CASCADE_FROM])) {
            return false;
        }

        $kept = [];
        $removed = false;
        foreach ($partial[$table][TableConfig::KEY_CASCADE_FROM] as $entry) {
            if (!$removed && is_array($entry) && $this->sameCascadeEntry($entry, $expected)) {
                $removed = true;
                continue;
            }
            $kept[] = $entry;
        }
        if (!$removed) {
            return false;
        }

        if (empty($kept)) {
            unset($partial[$table][TableConfig::KEY_CASCADE_FROM]);
        } else {
            $partial[$table][TableConfig::KEY_CASCADE_FROM] = $kept;
        }
        $this->writeSection($data, $isSchemaFile, $schema, DumpConfig::KEY_PARTIAL_EXPORT, $partial);

        return true;
    }

    /**
     * @param array<string, mixed> $entry
     * @param array<string, mixed> $expected
     */
    private function sameCascadeEntry(array $entry, array $expected): bool
    {
        foreach (['parent', 'fk_column', 'parent_column'] as $key) {
            $left = isset($entry[$key]) ? (string) $entry[$key] : null;
            $right = isset($expected[$key]) ? (string) $expected[$key] : null;
            if ($left !== $right) {
                return false;
            }
        }
        return true;
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $suggestion
     */
    private function renameCriterion(
        array &$data,
        bool $isSchemaFile,
        string $schema,
        Finding $finding,
        array $suggestion
    ): bool {
        $table = $finding->getTable();
        $index = isset($suggestion['index']) ? (int) $suggestion['index'] : -1;
        $name = isset($suggestion['name']) ? (string) $suggestion['name'] : '';
        $partial = $this->readSection($data, $isSchemaFile, $schema, DumpConfig::KEY_PARTIAL_EXPORT);

        if ($table === null || $index < 0 || $name === '' || !is_array($partial)) {
            return false;
        }

        $criteria = isset($partial[$table][TableConfig::KEY_SAMPLE][TableConfig::SAMPLE_KEY_CRITERIA])
            ? $partial[$table][TableConfig::KEY_SAMPLE][TableConfig::SAMPLE_KEY_CRITERIA]
            : null;
        if (!is_array($criteria) || !isset($criteria[$index]) || !is_array($criteria[$index])) {
            return false;
        }
        if (!isset($criteria[$index][TableConfig::CRITERION_KEY_NAME])
            || (string) $criteria[$index][TableConfig::CRITERION_KEY_NAME] !== $name) {
            return false;
        }

        $taken = [];
        foreach ($criteria as $criterion) {
            if (is_array($criterion) && isset($criterion[TableConfig::CRITERION_KEY_NAME])) {
                $taken[(string) $criterion[TableConfig::CRITERION_KEY_NAME]] = true;
            }
        }
        $suffix = 2;
        while (isset($taken[$name . '_' . $suffix])) {
            $suffix++;
        }

        $criteria[$index][TableConfig::CRITERION_KEY_NAME] = $name . '_' . $suffix;
        $partial[$table][TableConfig::KEY_SAMPLE][TableConfig::SAMPLE_KEY_CRITERIA] = $criteria;
        $this->writeSection($data, $isSchemaFile, $schema, DumpConfig::KEY_PARTIAL_EXPORT, $partial);

        return true;
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $suggestion
     */
    private function removeSection(array &$data, bool $isSchemaFile, string $schema, array $suggestion): bool
    {
        $name = isset($suggestion['section']) ? (string) $suggestion['section'] : '';
        if ($name === '') {
            return false;
        }

        $section = $this->readSection($data, $isSchemaFile, $schema, $name);
        if ($section === self::MISSING) {
            return false;
        }
        if ($section !== null && !(is_array($section) && empty($section))) {
            return false;
        }

        $this->writeSection($data, $isSchemaFile, $schema, $name, null);
        return true;
    }

    /**
     * Значение секции файла: в пер-схемном файле она лежит в корне, в общем конфиге —
     * под именем схемы. Ключи не создаются: отсутствие возвращается маркером MISSING.
     *
     * @param array<string, mixed> $data
     * @return mixed
     */
    private function readSection(array $data, bool $isSchemaFile, string $schema, string $name)
    {
        if ($isSchemaFile) {
            return array_key_exists($name, $data) ? $data[$name] : self::MISSING;
        }
        if (!isset($data[$name]) || !is_array($data[$name]) || !array_key_exists($schema, $data[$name])) {
            return self::MISSING;
        }
        return $data[$name][$schema];
    }

    /**
     * Записать секцию обратно; null удаляет её (и опустевшего родителя в общем конфиге).
     *
     * @param array<string, mixed> $data
     * @param mixed $value
     */
    private function writeSection(array &$data, bool $isSchemaFile, string $schema, string $name, $value): void
    {
        if ($isSchemaFile) {
            if ($value === null) {
                unset($data[$name]);
                return;
            }
            $data[$name] = $value;
            return;
        }

        if ($value === null) {
            if (isset($data[$name]) && is_array($data[$name])) {
                unset($data[$name][$schema]);
                if (empty($data[$name])) {
                    unset($data[$name]);
                }
            }
            return;
        }

        if (!isset($data[$name]) || !is_array($data[$name])) {
            $data[$name] = [];
        }
        $data[$name][$schema] = $value;
    }

    /**
     * Проверить, что после правок каждая таблица всё ещё принимается TableConfig —
     * тем же кодом, что и на экспорте.
     *
     * @param array<string, mixed> $data
     * @return string|null текст проблемы или null
     */
    private function validate(array $data, bool $isSchemaFile): ?string
    {
        if (!isset($data[DumpConfig::KEY_PARTIAL_EXPORT]) || !is_array($data[DumpConfig::KEY_PARTIAL_EXPORT])) {
            return null;
        }

        $bySchema = $isSchemaFile
            ? ['' => $data[DumpConfig::KEY_PARTIAL_EXPORT]]
            : $data[DumpConfig::KEY_PARTIAL_EXPORT];

        foreach ($bySchema as $schema => $tables) {
            if (!is_array($tables)) {
                continue;
            }
            foreach ($tables as $table => $conf) {
                try {
                    // Имена schema/table здесь заведомо валидны (они уже прошли аудит) —
                    // проверяем именно тело настроек таблицы.
                    TableConfig::fromArray('audit', 'table', is_array($conf) ? $conf : []);
                } catch (\Throwable $e) {
                    return sprintf(
                        '%s%s стала невалидной (%s)',
                        $schema === '' ? '' : $schema . '.',
                        (string) $table,
                        $e->getMessage()
                    );
                }
            }
        }

        return null;
    }

    private function info(string $message): void
    {
        if ($this->logger !== null) {
            $this->logger->info($message);
        }
    }
}
