<?php

namespace Timbrs\DatabaseDumps\Bridge\Symfony\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Timbrs\DatabaseDumps\Bridge\Symfony\ConsoleLogger;
use Timbrs\DatabaseDumps\Contract\ConfigLoaderInterface;
use Timbrs\DatabaseDumps\Contract\FileSystemInterface;
use Timbrs\DatabaseDumps\Contract\LoggerInterface;
use Timbrs\DatabaseDumps\Service\Ai\DbdumpConfigStore;
use Timbrs\DatabaseDumps\Service\Analysis\Dossier\MigrationScanner;
use Timbrs\DatabaseDumps\Service\Check\CheckReport;
use Timbrs\DatabaseDumps\Service\Check\CheckRunner;
use Timbrs\DatabaseDumps\Service\Incremental\Checkpoint;
use Timbrs\DatabaseDumps\Service\Incremental\DirtySetBuilder;
use Timbrs\DatabaseDumps\Service\Incremental\GitHistory;
use Timbrs\DatabaseDumps\Service\Incremental\MigrationDiffParser;
use Timbrs\DatabaseDumps\Service\Validation\Finding;
use Timbrs\DatabaseDumps\Service\Validation\FindingCatalog;
use Timbrs\DatabaseDumps\Service\Validation\InventoryReader;
use Timbrs\DatabaseDumps\Util\YamlConfigLoader;

/**
 * check: все проверки конфига и дампов одной командой.
 *
 * Стадии выбираются автоматически по тому, что доступно: слепок → static, живая БД → live,
 * файлы дампа → dump, scratch-БД (--import-connection) → import; plan — по запросу.
 * Один JSON, одно пространство кодов (FINDINGS.md), код возврата по --fail-on.
 * validate, check-criteria и verify-dump остаются как узкие подмножества этой команды.
 */
class CheckCommand extends Command
{
    /** @var CheckRunner */
    private $runner;

    /** @var FileSystemInterface */
    private $fileSystem;

    /** @var DbdumpConfigStore */
    private $configStore;

    /** @var LoggerInterface */
    private $logger;

    /** @var string */
    private $projectDir;

    /** @var string */
    private $configPath;

    /** @var ConfigLoaderInterface */
    private $configLoader;

    public function __construct(
        CheckRunner $runner,
        FileSystemInterface $fileSystem,
        DbdumpConfigStore $configStore,
        LoggerInterface $logger,
        string $projectDir,
        string $configPath,
        ConfigLoaderInterface $configLoader = null
    ) {
        $this->runner = $runner;
        $this->fileSystem = $fileSystem;
        $this->configStore = $configStore;
        $this->logger = $logger;
        $this->projectDir = rtrim($projectDir, '/\\');
        $this->configPath = $configPath;
        $this->configLoader = $configLoader !== null ? $configLoader : new YamlConfigLoader();
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('app:dbdump:check')
            ->setDescription('Все проверки одной командой: конфиг против слепка, критерии в БД, план, выгруженные дампы, контрольный импорт')
            ->addOption('stage', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Стадии: ' . implode(', ', CheckRunner::STAGES) . ' (по умолчанию — все доступные, кроме plan)')
            ->addOption('schema', 's', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Только эти схемы (можно повторять)')
            ->addOption('tables-from', null, InputOption::VALUE_REQUIRED, 'Файл со списком schema.table (по строке) или dirty.json со списком tables — проверять только их')
            ->addOption('since-checkpoint', null, InputOption::VALUE_NONE, 'Только изменившееся с прошлой отметки (checkpoint.json); отметки нет — проверяется всё')
            ->addOption('since-migration', null, InputOption::VALUE_REQUIRED, 'Только таблицы, затронутые миграциями новее этой версии')
            ->addOption('config', null, InputOption::VALUE_REQUIRED, 'Путь к dump_config.yaml')
            ->addOption('inventory', null, InputOption::VALUE_REQUIRED, 'Путь к schema_inventory.json')
            ->addOption('connection', 'c', InputOption::VALUE_REQUIRED, 'Подключение для стадий live/plan/dump')
            ->addOption('import-connection', null, InputOption::VALUE_REQUIRED, 'Scratch-БД для контрольного импорта (стадия import выполняется только с этой опцией)')
            ->addOption('fail-on', null, InputOption::VALUE_REQUIRED, 'Порог для кода возврата 1: error|warning|note', Finding::SEVERITY_ERROR)
            ->addOption('fix', null, InputOption::VALUE_NONE, 'Применить механически однозначные правки конфига (стадия static)')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'text|json', 'text')
            ->addOption('out', null, InputOption::VALUE_REQUIRED, 'Записать отчёт (JSON) в файл')
            ->setHelp(<<<'HELP'
Стадии:
  static   конфиг против слепка схемы, без БД (коды S/C/L/Q-1..5/F/G/D/H); --fix чинит однозначное
  live     каждый sample.criterion в БД под statement_timeout: Q-7 падает, Q-8 таймаут, Q-6 корзина пуста; P-1/P-2 из слепка
  plan     что и как будет выгружено: режим, where, каскад, корзины (без БД)
  dump     что реально легло в dumps/: V-1..V-8 (сироты, покрытие значений, ПД без faker, число строк)
  import   контрольная заливка в scratch-БД (--import-connection): I-1..I-4

Примеры:
  php bin/console app:dbdump:check                                  все доступные стадии
  php bin/console app:dbdump:check --stage=static --fix             только конфиг, с автоправками
  php bin/console app:dbdump:check --stage=dump -s persons          выгруженные дампы схемы persons
  php bin/console app:dbdump:check --tables-from=docker/database/analysis/dirty.json
  php bin/console app:dbdump:check --since-checkpoint               только изменившееся с прошлой отметки
  php bin/console app:dbdump:check --since-migration=Version20250101120000
  php bin/console app:dbdump:check --format=json --out=docker/database/analysis/check.json
  php bin/console app:dbdump:check --stage=import --import-connection=scratch

Коды находок описаны в {data_dir}/analysis/docs/FINDINGS.md (app:dbdump:docs).
HELP
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        if ($this->logger instanceof ConsoleLogger) {
            $this->logger->setIo($io);
        }

        $format = (string) $input->getOption('format');
        if (!in_array($format, ['text', 'json'], true)) {
            $io->error('Неизвестный формат: ' . $format . ' (допустимы text и json)');

            return Command::FAILURE;
        }
        $failOn = (string) $input->getOption('fail-on');
        if (!in_array($failOn, Finding::SEVERITIES, true)) {
            $io->error('Неизвестный порог --fail-on: ' . $failOn . ' (допустимы ' . implode(', ', Finding::SEVERITIES) . ')');

            return Command::FAILURE;
        }

        /** @var array<int, string> $stages */
        $stages = (array) $input->getOption('stage');
        foreach ($stages as $stage) {
            if (!in_array($stage, CheckRunner::STAGES, true)) {
                $io->error('Неизвестная стадия: ' . $stage . ' (допустимы ' . implode(', ', CheckRunner::STAGES) . ')');

                return Command::FAILURE;
            }
        }
        if ($stages === []) {
            // plan — по запросу: в автоматическом наборе он только шумит.
            $stages = array_values(array_diff(CheckRunner::STAGES, [FindingCatalog::STAGE_PLAN]));
        }

        $tables = null;
        $tablesFrom = $input->getOption('tables-from');
        $sinceCheckpoint = (bool) $input->getOption('since-checkpoint');
        $sinceMigration = $input->getOption('since-migration');

        if ($tablesFrom !== null && $tablesFrom !== '') {
            $tables = $this->readTableList($this->absolutize((string) $tablesFrom));
            if ($tables === null) {
                $io->error('Не удалось прочитать список таблиц: ' . $tablesFrom);

                return Command::FAILURE;
            }
        } elseif ($sinceCheckpoint || ($sinceMigration !== null && $sinceMigration !== '')) {
            $tables = $this->incrementalTables(
                $input,
                $io,
                $sinceMigration !== null && $sinceMigration !== '' ? (string) $sinceMigration : null
            );
            if ($tables === null) {
                return Command::FAILURE;
            }
            if ($tables === []) {
                $io->success('Делать нечего: с прошлой отметки ни одна таблица не изменилась.');

                return Command::SUCCESS;
            }
            $io->writeln(sprintf('Инкремент: проверяем %d таблиц.', count($tables)), OutputInterface::OUTPUT_RAW);
        }

        $report = $this->runner->run([
            'config_path' => $this->resolveConfigPath($input),
            'inventory_path' => $this->resolveInventoryPath($input),
            'schemas' => (array) $input->getOption('schema'),
            'stages' => $stages,
            'fix' => (bool) $input->getOption('fix'),
            'tables' => $tables,
            'connection' => $input->getOption('connection') !== null ? (string) $input->getOption('connection') : null,
            'import_connection' => $input->getOption('import-connection') !== null ? (string) $input->getOption('import-connection') : null,
        ]);

        $json = (string) json_encode($report->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $out = $input->getOption('out');
        if ($out !== null && $out !== '') {
            $path = $this->absolutize((string) $out);
            $dir = dirname($path);
            if ($dir !== '' && !$this->fileSystem->isDirectory($dir)) {
                $this->fileSystem->createDirectory($dir);
            }
            $this->fileSystem->writeAtomic($path, $json . PHP_EOL);
            if ($format !== 'json') {
                $io->writeln('Отчёт записан: ' . $path, OutputInterface::OUTPUT_RAW);
            }
        }

        if ($format === 'json') {
            $output->writeln($json, OutputInterface::OUTPUT_RAW);
        } else {
            $this->renderText($io, $report);
        }

        return $report->hasAtLeast($failOn) ? Command::FAILURE : Command::SUCCESS;
    }

    private function renderText(SymfonyStyle $io, CheckReport $report): void
    {
        $io->title('Проверка выгрузки');

        $lines = [];
        foreach ($report->getStages() as $name => $stage) {
            if (!$stage['ran']) {
                $lines[] = sprintf('%-7s пропущена — %s', $name, $stage['why_skipped']);
                continue;
            }
            $details = sprintf('%d находок, %d мс', $stage['findings'], $stage['ms']);
            if (isset($stage['queries']) && $stage['queries'] !== null) {
                $details .= sprintf(', запросов %d', $stage['queries']);
            }
            if (isset($stage['fix'])) {
                $details .= sprintf(', автоправок %d', $stage['fix']['applied']);
            }
            if (isset($stage['tables']) && is_array($stage['tables'])) {
                $details .= sprintf(', таблиц в плане %d', count($stage['tables']));
            }
            $lines[] = sprintf('%-7s %s', $name, $details);
        }
        $io->text($lines);

        $plan = $report->getStages()[FindingCatalog::STAGE_PLAN] ?? null;
        if ($plan !== null && !empty($plan['ran']) && isset($plan['tables'])) {
            $rows = [];
            foreach ($plan['tables'] as $row) {
                $sample = [];
                if ($row['criteria'] !== []) {
                    $sample[] = count($row['criteria']) . ' критериев';
                }
                if ($row['stratify_by'] !== []) {
                    $sample[] = 'stratify_by ' . implode(',', $row['stratify_by']);
                }
                if ($row['stratify'] > 0) {
                    $sample[] = 'stratify ×' . $row['stratify'];
                }
                if ($row['stratify_via'] > 0) {
                    $sample[] = 'via ×' . $row['stratify_via'];
                }
                $rows[] = [
                    $row['table'],
                    $row['mode'] . ($row['limit'] !== null ? ' ' . $row['limit'] : ''),
                    $row['where'] ?? '—',
                    $row['cascade_from'] === [] ? '—' : implode('; ', $row['cascade_from']),
                    $sample === [] ? '—' : implode(', ', $sample),
                ];
            }
            $io->section('План выгрузки');
            $io->table(['таблица', 'режим', 'where', 'cascade_from', 'sample'], $rows);
        }

        $findings = $report->getFindings();
        if ($findings === []) {
            $io->success('Находок нет.');

            return;
        }

        $rows = [];
        foreach ($findings as $finding) {
            $rows[] = [
                $finding->getCode(),
                FindingCatalog::stageOf($finding->getCode()),
                $finding->getSeverity(),
                $finding->getTarget(),
                $finding->getMessage(),
            ];
        }
        $io->table(['код', 'стадия', 'уровень', 'адрес', 'что не так'], $rows);

        $summary = sprintf(
            'Итог: ошибок %d, предупреждений %d, заметок %d.',
            $report->countBySeverity(Finding::SEVERITY_ERROR),
            $report->countBySeverity(Finding::SEVERITY_WARNING),
            $report->countBySeverity(Finding::SEVERITY_NOTE)
        );
        if ($report->countBySeverity(Finding::SEVERITY_ERROR) > 0) {
            $io->error($summary);
        } else {
            $io->warning($summary);
        }
    }

    /**
     * Список таблиц: по строке «schema.table» (пустые и # — пропускаются) или JSON с ключом
     * tables (dirty.json инкрементального режима).
     *
     * @return array<int, string>|null
     */
    private function readTableList(string $path): ?array
    {
        if (!$this->fileSystem->exists($path)) {
            return null;
        }
        $content = $this->fileSystem->read($path);
        $decoded = json_decode($content, true);
        if (is_array($decoded)) {
            $list = isset($decoded['tables']) && is_array($decoded['tables']) ? $decoded['tables'] : $decoded;
            $tables = [];
            foreach ($list as $key => $entry) {
                if (is_string($entry)) {
                    $tables[] = $entry;
                } elseif (is_array($entry) && isset($entry['table']) && is_string($entry['table'])) {
                    $tables[] = $entry['table'];
                } elseif (is_string($key) && strpos($key, '.') !== false) {
                    $tables[] = $key;
                }
            }

            return $tables;
        }

        $tables = [];
        foreach (preg_split('/\R/', $content) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            $tables[] = $line;
        }

        return $tables;
    }

    /**
     * Грязный набор без записи отметки: та же механика, что у `app:dbdump:checkpoint`,
     * но результат никуда не сохраняется — `check` просто сужает себе список таблиц.
     *
     * `null` — ошибка (сообщение уже выведено), `[]` — проверять нечего.
     *
     * @return array<int, string>|null
     */
    private function incrementalTables(InputInterface $input, SymfonyStyle $io, ?string $sinceMigration): ?array
    {
        $configPath = $this->resolveConfigPath($input);
        if (!$this->fileSystem->exists($configPath)) {
            $io->error('Конфиг выгрузки не найден: ' . $configPath);

            return null;
        }

        $analysisDir = $this->projectDir . '/' . $this->configStore->getDataDir($this->projectDir) . '/analysis';
        $checkpoint = Checkpoint::load($this->fileSystem, $analysisDir . '/' . Checkpoint::FILE);

        if ($sinceMigration !== null) {
            $checkpoint = new Checkpoint([
                'newest_migration' => $sinceMigration,
                'tables' => $checkpoint !== null ? $checkpoint->tables() : [],
            ]);
        } elseif ($checkpoint === null) {
            $io->warning('Отметки нет — проверяем всё. Зафиксировать состояние: app:dbdump:checkpoint --save');

            return [];
        }

        $git = new GitHistory($this->projectDir);
        $parser = new MigrationDiffParser(new MigrationScanner($this->projectDir));
        $builder = new DirtySetBuilder($parser, $git->hasHistory() ? $git->diffSensor() : null);

        $dirty = $builder->build(
            $checkpoint,
            $this->configLoader->load($configPath),
            new InventoryReader($this->fileSystem, $this->resolveInventoryPath($input)),
            (array) $input->getOption('schema')
        );

        // Отметки не было и режим не «с миграции» — грязное всё, а это обычный полный прогон.
        if (!empty($dirty['full'])) {
            return [];
        }

        return DirtySetBuilder::tableList($dirty);
    }

    private function resolveConfigPath(InputInterface $input): string
    {
        $option = $input->getOption('config');
        if ($option !== null && $option !== '') {
            return $this->absolutize((string) $option);
        }

        return $this->configPath;
    }

    private function resolveInventoryPath(InputInterface $input): string
    {
        $option = $input->getOption('inventory');
        if ($option !== null && $option !== '') {
            return $this->absolutize((string) $option);
        }
        $dataDir = $this->configStore->getDataDir($this->projectDir);

        return $this->projectDir . '/' . $dataDir . '/analysis/' . InventoryReader::DEFAULT_FILE;
    }

    private function absolutize(string $path): string
    {
        if ($path === '') {
            return $path;
        }
        $isAbsolute = substr($path, 0, 1) === '/'
            || preg_match('#^[a-zA-Z]:[\\\\/]#', $path) === 1
            || substr($path, 0, 2) === '\\\\';

        return $isAbsolute ? $path : $this->projectDir . '/' . $path;
    }
}
