<?php

namespace Timbrs\DatabaseDumps\Bridge\Symfony\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Timbrs\DatabaseDumps\Contract\FileSystemInterface;
use Timbrs\DatabaseDumps\Service\Ai\DbdumpConfigStore;
use Timbrs\DatabaseDumps\Service\Validation\FindingCatalog;

/**
 * docs: документация инструмента, сгенерированная из него самого, — в каталог проекта, где её
 * читает агент. COMMANDS.md собирается из определений команд (Command::getDefinition()),
 * FINDINGS.md — из реестра кодов, WORKFLOW.md — из ресурса пакета. Устаревает вместе с кодом,
 * а не отдельно от него.
 */
class DocsCommand extends Command
{
    public const DOCS_DIR = 'analysis/docs';

    /** @var FileSystemInterface */
    private $fileSystem;

    /** @var DbdumpConfigStore */
    private $configStore;

    /** @var string */
    private $projectDir;

    public function __construct(FileSystemInterface $fileSystem, DbdumpConfigStore $configStore, string $projectDir)
    {
        $this->fileSystem = $fileSystem;
        $this->configStore = $configStore;
        $this->projectDir = rtrim($projectDir, '/\\');
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('app:dbdump:docs')
            ->setDescription('Сгенерировать документацию инструмента (WORKFLOW.md, COMMANDS.md, FINDINGS.md) в {data_dir}/analysis/docs')
            ->addOption('out', null, InputOption::VALUE_REQUIRED, 'Каталог для файлов (по умолчанию {data_dir}/' . self::DOCS_DIR . ')')
            ->addOption('stdout', null, InputOption::VALUE_NONE, 'Напечатать вместо записи');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $files = [
            'WORKFLOW.md' => self::workflow(),
            'DOSSIER.md' => self::dossier(),
            'FINDINGS.md' => FindingCatalog::renderMarkdown(),
            'COMMANDS.md' => $this->renderCommands(),
        ];

        if ($input->getOption('stdout')) {
            foreach ($files as $name => $content) {
                $output->writeln('<!-- ' . $name . ' -->', OutputInterface::OUTPUT_RAW);
                $output->writeln($content, OutputInterface::OUTPUT_RAW);
                $output->writeln('', OutputInterface::OUTPUT_RAW);
            }

            return Command::SUCCESS;
        }

        $outOption = $input->getOption('out');
        $dir = $outOption !== null && $outOption !== ''
            ? $this->absolutize((string) $outOption)
            : $this->projectDir . '/' . $this->configStore->getDataDir($this->projectDir) . '/' . self::DOCS_DIR;
        if (!$this->fileSystem->isDirectory($dir)) {
            $this->fileSystem->createDirectory($dir);
        }
        foreach ($files as $name => $content) {
            $this->fileSystem->writeAtomic($dir . '/' . $name, rtrim($content) . "\n");
            $io->writeln('  ' . $dir . '/' . $name, OutputInterface::OUTPUT_RAW);
        }
        $io->success('Документация записана: ' . count($files) . ' файла(ов).');

        return Command::SUCCESS;
    }

    /**
     * WORKFLOW.md — ресурс пакета; пустая строка, если ресурс не найден.
     */
    public static function workflow(): string
    {
        return self::resource('workflow.md');
    }

    /**
     * DOSSIER.md — что лежит в dossier.<schema>.json и что означают пометки ambiguous.
     * Агент читает досье, и структуру ему объясняет этот файл, а не исходники правил.
     */
    public static function dossier(): string
    {
        return self::resource('dossier.md');
    }

    private static function resource(string $name): string
    {
        $path = __DIR__ . '/../../../Resources/docs/' . $name;
        $content = is_file($path) ? file_get_contents($path) : false;

        return $content === false ? '' : $content;
    }

    /**
     * COMMANDS.md из определений всех команд app:dbdump:*.
     */
    private function renderCommands(): string
    {
        $lines = ['# Команды', ''];
        $lines[] = 'Сгенерировано из определений команд — аргументы, опции и значения по умолчанию те же, что в `--help`.';
        $lines[] = '';

        $application = $this->getApplication();
        if ($application === null) {
            return implode("\n", $lines);
        }

        $commands = [];
        foreach ($application->all() as $name => $command) {
            if (strpos($name, 'app:dbdump') !== 0 || $command->getName() !== $name) {
                continue;
            }
            $commands[$name] = $command;
        }
        ksort($commands);

        foreach ($commands as $name => $command) {
            $lines[] = '## `' . $name . '`';
            $lines[] = '';
            $lines[] = $command->getDescription();
            $lines[] = '';
            $definition = $command->getDefinition();
            $rows = $this->definitionRows($definition);
            if ($rows !== []) {
                $lines[] = '| параметр | описание | по умолчанию |';
                $lines[] = '|---|---|---|';
                foreach ($rows as $row) {
                    $lines[] = '| ' . implode(' | ', $row) . ' |';
                }
                $lines[] = '';
            }
            $help = trim($command->getProcessedHelp());
            if ($help !== '' && $help !== $command->getDescription()) {
                $lines[] = '```';
                $lines[] = $help;
                $lines[] = '```';
                $lines[] = '';
            }
        }

        return implode("\n", $lines);
    }

    /**
     * @return array<int, array{0: string, 1: string, 2: string}>
     */
    private function definitionRows(InputDefinition $definition): array
    {
        $rows = [];
        foreach ($definition->getArguments() as $argument) {
            /** @var InputArgument $argument */
            $rows[] = [
                '`<' . $argument->getName() . '>`' . ($argument->isRequired() ? '' : ' (необяз.)'),
                $this->cell($argument->getDescription()),
                $this->defaultCell($argument->getDefault()),
            ];
        }
        foreach ($definition->getOptions() as $option) {
            /** @var InputOption $option */
            $label = '`--' . $option->getName() . '`';
            if ($option->getShortcut() !== null) {
                $label .= ' / `-' . $option->getShortcut() . '`';
            }
            if ($option->isArray()) {
                $label .= ' (повторяемая)';
            }
            $rows[] = [$label, $this->cell($option->getDescription()), $this->defaultCell($option->getDefault())];
        }

        return $rows;
    }

    private function cell(string $text): string
    {
        return str_replace(['|', "\n"], ['\\|', ' '], $text);
    }

    /**
     * @param mixed $default
     */
    private function defaultCell($default): string
    {
        if ($default === null || $default === false || $default === [] || $default === '') {
            return '—';
        }
        if (is_array($default)) {
            return '`' . implode(', ', array_map('strval', $default)) . '`';
        }
        if (is_bool($default)) {
            return $default ? '`true`' : '—';
        }

        return '`' . (string) $default . '`';
    }

    private function absolutize(string $path): string
    {
        $isAbsolute = substr($path, 0, 1) === '/'
            || preg_match('#^[a-zA-Z]:[\\\\/]#', $path) === 1
            || substr($path, 0, 2) === '\\\\';

        return $isAbsolute ? $path : $this->projectDir . '/' . $path;
    }
}
