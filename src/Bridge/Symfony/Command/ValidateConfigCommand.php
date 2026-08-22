<?php

namespace Timbrs\DatabaseDumps\Bridge\Symfony\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Timbrs\DatabaseDumps\Bridge\Symfony\ConsoleLogger;
use Timbrs\DatabaseDumps\Contract\FileSystemInterface;
use Timbrs\DatabaseDumps\Contract\LoggerInterface;
use Timbrs\DatabaseDumps\Service\Ai\DbdumpConfigStore;
use Timbrs\DatabaseDumps\Service\Validation\AuditFixer;
use Timbrs\DatabaseDumps\Service\Validation\ConfigAuditor;
use Timbrs\DatabaseDumps\Service\Validation\ConfigDocument;
use Timbrs\DatabaseDumps\Service\Validation\Finding;
use Timbrs\DatabaseDumps\Service\Validation\InventoryReader;
use Timbrs\DatabaseDumps\Service\Validation\JsonReportWriter;
use Timbrs\DatabaseDumps\Service\Validation\TextReportRenderer;

/**
 * validate: детерминированный аудит конфигурации выгрузки БЕЗ подключения к БД.
 *
 * Схему берём из замороженного слепка (`{data_dir}/analysis/schema_inventory.json`),
 * поэтому команда работает и там, где базы нет: в CI, в открытом контуре, до подъёма стенда.
 * Код возврата 1 ровно тогда, когда есть находки уровня error — этого достаточно, чтобы
 * решить «готов конфиг к снятию дампа или нет», не разбирая текст вывода.
 */
class ValidateConfigCommand extends Command
{
    /** @var ConfigAuditor */
    private $auditor;

    /** @var AuditFixer */
    private $fixer;

    /** @var JsonReportWriter */
    private $reportWriter;

    /** @var FileSystemInterface */
    private $fileSystem;

    /** @var DbdumpConfigStore */
    private $configStore;

    /** @var string */
    private $projectDir;

    /** @var string */
    private $configPath;

    /** @var LoggerInterface */
    private $logger;

    public function __construct(
        ConfigAuditor $auditor,
        AuditFixer $fixer,
        JsonReportWriter $reportWriter,
        FileSystemInterface $fileSystem,
        DbdumpConfigStore $configStore,
        string $projectDir,
        string $configPath,
        LoggerInterface $logger
    ) {
        $this->auditor = $auditor;
        $this->fixer = $fixer;
        $this->reportWriter = $reportWriter;
        $this->fileSystem = $fileSystem;
        $this->configStore = $configStore;
        $this->projectDir = rtrim($projectDir, '/\\');
        $this->configPath = $configPath;
        $this->logger = $logger;
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('app:dbdump:validate')
            ->setDescription('Проверить конфиг выгрузки по слепку схемы (без подключения к БД)')
            ->addOption('config', null, InputOption::VALUE_REQUIRED, 'Путь к dump_config.yaml')
            ->addOption('inventory', null, InputOption::VALUE_REQUIRED, 'Путь к schema_inventory.json')
            ->addOption('schema', 's', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Проверить только эту схему (можно повторять)')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'Формат вывода: text|json', 'text')
            ->addOption('out', null, InputOption::VALUE_REQUIRED, 'Записать отчёт в файл')
            ->addOption('severity', null, InputOption::VALUE_REQUIRED, 'Порог вывода находок: error|warning|note', Finding::SEVERITY_NOTE)
            ->addOption('fix', null, InputOption::VALUE_NONE, 'Применить механически однозначные правки');
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

        $severity = (string) $input->getOption('severity');
        if (!in_array($severity, Finding::SEVERITIES, true)) {
            $io->error('Неизвестный порог: ' . $severity . ' (допустимы ' . implode(', ', Finding::SEVERITIES) . ')');
            return Command::FAILURE;
        }

        $configPath = $this->resolveConfigPath($input);
        $inventoryPath = $this->resolveInventoryPath($input);
        /** @var array<int, string> $schemas */
        $schemas = (array) $input->getOption('schema');

        $inventory = new InventoryReader($this->fileSystem, $inventoryPath);
        $result = $this->auditor->audit($configPath, $inventory, $schemas);

        if ($input->getOption('fix')) {
            $fixReport = $this->fixer->fix(
                ConfigDocument::load($this->fileSystem, $configPath),
                $result->getFindings()
            );
            $this->reportFixes($io, $fixReport, $format);

            if ($fixReport['applied'] > 0) {
                // Слепок не менялся, а конфиг — да: перечитываем и пересчитываем находки,
                // чтобы и отчёт, и код возврата отражали состояние ПОСЛЕ правок.
                $inventory = new InventoryReader($this->fileSystem, $inventoryPath);
                $result = $this->auditor->audit($configPath, $inventory, $schemas);
            }
        }

        $rendered = $format === 'json'
            ? $this->reportWriter->toJson($result)
            : implode(PHP_EOL, (new TextReportRenderer())->render($result, $severity));

        $out = $input->getOption('out');
        if ($out !== null && $out !== '') {
            $path = $this->absolutize((string) $out);
            $dir = dirname($path);
            if ($dir !== '' && !$this->fileSystem->isDirectory($dir)) {
                $this->fileSystem->createDirectory($dir);
            }
            $this->fileSystem->writeAtomic($path, $rendered . PHP_EOL);
            if ($format !== 'json') {
                $io->writeln('Отчёт записан: ' . $path, OutputInterface::OUTPUT_RAW);
            }
        }

        $output->writeln($rendered, OutputInterface::OUTPUT_RAW);

        return $result->hasErrors() ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * @param array{applied: int, skipped: int, files: array<int, string>, by_code: array<string, int>, errors: array<int, string>} $report
     */
    private function reportFixes(SymfonyStyle $io, array $report, string $format): void
    {
        if ($format === 'json') {
            // В JSON-режиме stdout должен остаться валидным JSON — подробности уходят в лог.
            $this->logger->info(sprintf(
                'validate --fix: применено %d, пропущено %d, файлов %d',
                $report['applied'],
                $report['skipped'],
                count($report['files'])
            ));
            return;
        }

        $parts = [];
        foreach ($report['by_code'] as $code => $count) {
            $parts[] = $code . ': ' . $count;
        }
        $io->writeln(sprintf(
            'Автоправки: применено %d (%s), пропущено %d, файлов изменено %d',
            $report['applied'],
            empty($parts) ? '—' : implode(', ', $parts),
            $report['skipped'],
            count($report['files'])
        ), OutputInterface::OUTPUT_RAW);

        foreach ($report['files'] as $file) {
            $io->writeln('  изменён ' . $file, OutputInterface::OUTPUT_RAW);
        }
        foreach ($report['errors'] as $error) {
            $io->writeln('  ! ' . $error, OutputInterface::OUTPUT_RAW);
        }
    }

    private function resolveConfigPath(InputInterface $input): string
    {
        $option = $input->getOption('config');
        if ($option !== null && $option !== '') {
            return $this->absolutize((string) $option);
        }
        return $this->configPath;
    }

    /**
     * По умолчанию слепок лежит рядом с конфигом — в `{data_dir}/analysis`, куда его кладёт
     * prepare-analysis.
     */
    private function resolveInventoryPath(InputInterface $input): string
    {
        $option = $input->getOption('inventory');
        if ($option !== null && $option !== '') {
            return $this->absolutize((string) $option);
        }

        $dataDir = $this->configStore->getDataDir($this->projectDir);

        return $this->projectDir . '/' . $dataDir . '/analysis/' . InventoryReader::DEFAULT_FILE;
    }

    /**
     * Относительный путь считаем от корня проекта — так команда одинаково работает
     * из любого рабочего каталога внутри контейнера.
     */
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
