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
use Timbrs\DatabaseDumps\Service\Analysis\AnalysisPackageBuilder;
use Timbrs\DatabaseDumps\Service\Incremental\Checkpoint;
use Timbrs\DatabaseDumps\Service\Incremental\DirtySetBuilder;
use Timbrs\DatabaseDumps\Service\Incremental\GitHistory;
use Timbrs\DatabaseDumps\Service\Incremental\MigrationDiffParser;
use Timbrs\DatabaseDumps\Service\Validation\InventoryReader;

/**
 * checkpoint: зафиксировать «здесь дамп признан годным» и показать, что изменилось с прошлой отметки.
 *
 * Без аргументов — только показывает грязный набор (`--dry-run` по духу, но безопасно по
 * умолчанию: перезаписать отметку случайно нельзя, для этого есть `--save`).
 */
class CheckpointCommand extends Command
{
    /** @var FileSystemInterface */
    private $fileSystem;

    /** @var ConfigLoaderInterface */
    private $configLoader;

    /** @var DbdumpConfigStore */
    private $configStore;

    /** @var LoggerInterface */
    private $logger;

    /** @var string */
    private $projectDir;

    /** @var string */
    private $configPath;

    public function __construct(
        FileSystemInterface $fileSystem,
        ConfigLoaderInterface $configLoader,
        DbdumpConfigStore $configStore,
        LoggerInterface $logger,
        string $projectDir,
        string $configPath
    ) {
        $this->fileSystem = $fileSystem;
        $this->configLoader = $configLoader;
        $this->configStore = $configStore;
        $this->logger = $logger;
        $this->projectDir = rtrim($projectDir, '/\\');
        $this->configPath = $configPath;
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('app:dbdump:checkpoint')
            ->setDescription('Отметить текущее состояние как проверенное и/или показать, что изменилось с прошлой отметки')
            ->addOption('save', null, InputOption::VALUE_NONE, 'Записать новую отметку (без опции — только показать грязный набор)')
            ->addOption('schema', 's', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Только эти схемы (можно повторять)')
            ->addOption('config', null, InputOption::VALUE_REQUIRED, 'Путь к dump_config.yaml')
            ->addOption('inventory', null, InputOption::VALUE_REQUIRED, 'Путь к schema_inventory.json')
            ->addOption('since-migration', null, InputOption::VALUE_REQUIRED, 'Считать отметкой эту версию миграции вместо checkpoint.json')
            ->addOption('out', null, InputOption::VALUE_REQUIRED, 'Куда писать dirty.json (по умолчанию — в {data_dir}/analysis/)')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'text|json', 'text')
            ->setHelp(<<<'HELP'
Отметка (checkpoint.json) хранит самую новую миграцию, время слепка, коммит и хеши по каждой
таблице: настройки, состав колонок, набор кодов, внешние ключи. От неё считается грязный набор.

Четыре сенсора:
  миграции  — версии новее отметки: колонка появляется миграцией
  конфиг    — хеш настроек таблицы: правку руками не заметит никто другой
  слепок    — колонки, коды, внешние ключи: новое значение status_id приходит без миграции
  git       — файлы миграций, изменившиеся после коммита отметки; на схлопнутой истории отключается

Грязный набор пишется в {data_dir}/analysis/dirty.json и подаётся дальше как есть:
  php bin/console app:dbdump:check --tables-from=docker/database/analysis/dirty.json

Примеры:
  php bin/console app:dbdump:checkpoint                     что изменилось с прошлой отметки
  php bin/console app:dbdump:checkpoint --save              зафиксировать текущее состояние
  php bin/console app:dbdump:checkpoint --since-migration=Version20250101120000
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

        $analysisDir = $this->projectDir . '/' . $this->configStore->getDataDir($this->projectDir)
            . '/' . AnalysisPackageBuilder::ANALYSIS_DIR;
        $checkpointPath = $analysisDir . '/' . Checkpoint::FILE;

        $configPath = $this->resolve($input->getOption('config'), $this->configPath);
        if (!$this->fileSystem->exists($configPath)) {
            $io->error('Конфиг выгрузки не найден: ' . $configPath . '. Сначала выполните prepare-config.');

            return Command::FAILURE;
        }
        $dumpConfig = $this->configLoader->load($configPath);
        $inventory = new InventoryReader(
            $this->fileSystem,
            $this->resolve($input->getOption('inventory'), $analysisDir . '/schema_inventory.json')
        );

        /** @var array<int, string> $schemas */
        $schemas = (array) $input->getOption('schema');

        $git = new GitHistory($this->projectDir);
        $parser = new MigrationDiffParser($this->migrationScanner());
        $builder = new DirtySetBuilder($parser, $git->hasHistory() ? $git->diffSensor() : null);

        $checkpoint = Checkpoint::load($this->fileSystem, $checkpointPath);
        $sinceMigration = $input->getOption('since-migration');
        if ($sinceMigration !== null && $sinceMigration !== '') {
            // Явная версия перекрывает отметку: так проверяют «что принесла вот эта миграция»
            // без записи отметки и на чужой машине.
            $checkpoint = new Checkpoint([
                'created_at' => $checkpoint !== null ? $checkpoint->createdAt() : null,
                'newest_migration' => (string) $sinceMigration,
                'inventory_generated_at' => $checkpoint !== null ? $checkpoint->inventoryGeneratedAt() : null,
                'head_commit' => null,
                'tables' => $checkpoint !== null ? $checkpoint->tables() : [],
            ]);
        }

        $dirty = $builder->build($checkpoint, $dumpConfig, $inventory, $schemas);

        $dirtyPath = $this->resolve($input->getOption('out'), $analysisDir . '/' . DirtySetBuilder::FILE);
        $this->writeJson($dirtyPath, $dirty);

        if ($input->getOption('save')) {
            $saved = Checkpoint::create(
                $parser->newestVersion(),
                $inventory->exists() ? $inventory->generatedAt() : null,
                $git->head(),
                $builder->snapshotHashes($dumpConfig, $inventory, $schemas)
            );
            $saved->save($this->fileSystem, $checkpointPath);
            $io->writeln('Отметка записана: ' . $checkpointPath, OutputInterface::OUTPUT_RAW);
        }

        if ($format === 'json') {
            $output->writeln(
                (string) json_encode($dirty, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                OutputInterface::OUTPUT_RAW
            );

            return Command::SUCCESS;
        }

        $this->render($io, $dirty, $dirtyPath);

        return Command::SUCCESS;
    }

    /**
     * @param array<string, mixed> $dirty
     */
    private function render(SymfonyStyle $io, array $dirty, string $dirtyPath): void
    {
        $io->title('Что перепроверять');

        if ($dirty['full']) {
            $io->warning(sprintf(
                'Отметки нет — перепроверять всё (%d таблиц). Это первый прогон: нужен полный цикл.',
                $dirty['summary']['dirty']
            ));
        } elseif ($dirty['summary']['dirty'] === 0) {
            $io->success(sprintf(
                'Делать нечего: с отметки %s ни одна из %d таблиц не изменилась.',
                (string) $dirty['checkpoint']['created_at'],
                $dirty['summary']['configured']
            ));
        } else {
            $io->writeln(sprintf(
                'Грязных таблиц: %d из %d (отметка %s).',
                $dirty['summary']['dirty'],
                $dirty['summary']['configured'],
                (string) $dirty['checkpoint']['created_at']
            ));
            $rows = [];
            foreach ($dirty['details'] as $table => $entry) {
                $why = [];
                foreach ($entry['reasons'] as $reason) {
                    $why[] = $reason['sensor'] . ': ' . $reason['why'];
                }
                $rows[] = [(string) $table, implode("\n", $why)];
            }
            $io->table(['таблица', 'почему'], $rows);
        }

        $rows = [];
        foreach ($dirty['sensors'] as $name => $sensor) {
            $rows[] = [
                (string) $name,
                empty($sensor['enabled']) ? 'выключен' : 'работает',
                isset($sensor['why_skipped']) ? (string) $sensor['why_skipped'] : $this->sensorDetail($sensor),
            ];
        }
        if ($rows !== []) {
            $io->table(['сенсор', 'состояние', 'подробности'], $rows);
        }

        $io->writeln('Грязный набор: ' . $dirtyPath, OutputInterface::OUTPUT_RAW);
        $io->writeln(
            'Дальше: app:dbdump:check --tables-from=' . $dirtyPath,
            OutputInterface::OUTPUT_RAW
        );
    }

    /**
     * @param array<string, mixed> $sensor
     */
    private function sensorDetail(array $sensor): string
    {
        $parts = [];
        foreach ($sensor as $key => $value) {
            if ($key === 'enabled' || $value === null || is_array($value) && $value === []) {
                continue;
            }
            $parts[] = $key . ': ' . (is_array($value) ? implode(', ', array_map('strval', $value)) : (string) $value);
        }

        return implode('; ', $parts);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function writeJson(string $path, array $data): void
    {
        $dir = dirname($path);
        if ($dir !== '' && !$this->fileSystem->isDirectory($dir)) {
            $this->fileSystem->createDirectory($dir);
        }
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->fileSystem->write($path, $json === false ? '{}' : $json);
    }

    /**
     * Сканер миграций собирается здесь, а не приходит из контейнера: команда — единственный
     * его потребитель в инкременте, а каталоги миграций зависят от projectDir.
     */
    protected function migrationScanner(): \Timbrs\DatabaseDumps\Service\Analysis\Dossier\MigrationScanner
    {
        return new \Timbrs\DatabaseDumps\Service\Analysis\Dossier\MigrationScanner($this->projectDir);
    }

    /**
     * @param mixed $option
     */
    private function resolve($option, string $default): string
    {
        if (!is_string($option) || $option === '') {
            return $default;
        }
        if ($this->isAbsolute($option)) {
            return $option;
        }

        return $this->projectDir . '/' . ltrim($option, '/\\');
    }

    private function isAbsolute(string $path): bool
    {
        return $path !== '' && ($path[0] === '/' || $path[0] === '\\' || preg_match('#^[A-Za-z]:[\\\\/]#', $path) === 1);
    }
}
