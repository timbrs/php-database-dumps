<?php

namespace Timbrs\DatabaseDumps\Bridge\Symfony\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Timbrs\DatabaseDumps\Bridge\Symfony\ConsoleLogger;
use Timbrs\DatabaseDumps\Contract\LoggerInterface;
use Timbrs\DatabaseDumps\Service\Ai\DbdumpConfigStore;
use Timbrs\DatabaseDumps\Service\Analysis\AnalysisIngestor;
use Timbrs\DatabaseDumps\Service\Analysis\AnalysisPackageBuilder;
use Timbrs\DatabaseDumps\Service\Analysis\ConfigEnricher;
use Timbrs\DatabaseDumps\Service\Analysis\OpencodeRunner;

class PrepareAnalysisCommand extends Command
{
    /** @var AnalysisPackageBuilder */
    private $builder;

    /** @var OpencodeRunner */
    private $runner;

    /** @var AnalysisIngestor */
    private $ingestor;

    /** @var ConfigEnricher */
    private $enricher;

    /** @var string */
    private $projectDir;

    /** @var string */
    private $configPath;

    /** @var DbdumpConfigStore */
    private $configStore;

    /** @var LoggerInterface */
    private $logger;

    public function __construct(
        AnalysisPackageBuilder $builder,
        OpencodeRunner $runner,
        AnalysisIngestor $ingestor,
        ConfigEnricher $enricher,
        string $projectDir,
        string $configPath,
        DbdumpConfigStore $configStore,
        LoggerInterface $logger
    ) {
        $this->builder = $builder;
        $this->runner = $runner;
        $this->ingestor = $ingestor;
        $this->enricher = $enricher;
        $this->projectDir = rtrim($projectDir, '/\\');
        $this->configPath = $configPath;
        $this->configStore = $configStore;
        $this->logger = $logger;
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('app:dbdump:prepare-analysis')
            ->setDescription('Подготовить пакет для анализа кода хоста агентом OPENCODE')
            ->addOption('connection', 'c', InputOption::VALUE_REQUIRED, 'Имя подключения (по умолчанию — дефолтное)')
            ->addOption('run', null, InputOption::VALUE_NONE, 'Сразу запустить OPENCODE (по чанку на схему) и применить результат — всё одной командой');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        // Роутим пошаговый прогресс в консоль — сбор инвентаря по всем таблицам
        // (подсчёт строк + профилирование колонок) иначе выглядит как «зависание».
        if ($this->logger instanceof ConsoleLogger) {
            $this->logger->setIo($io);
        }
        $io->title('Подготовка пакета анализа (OPENCODE)');

        $run = (bool) $input->getOption('run');
        $this->printRoadmap($io, $run);

        $connection = $input->getOption('connection');

        try {
            $result = $this->builder->build($connection !== null ? (string) $connection : null);
            $io->success(sprintf('Подготовлено файлов: %d (таблиц: %d)', count($result['paths']), $result['tables']));

            if ($input->getOption('run')) {
                return $this->runPipeline($io, $result['schema_files']);
            }

            $this->printManualInstructions($io, $result['schema_files']);
            return Command::SUCCESS;
        } catch (\Exception $e) {
            $io->error('Ошибка подготовки пакета: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }

    /**
     * Короткая карта предстоящих фаз — чтобы было ясно, что ещё впереди.
     */
    private function printRoadmap(SymfonyStyle $io, bool $run): void
    {
        if ($run) {
            $io->text('Этапы (--run, всё одной командой):');
            $io->listing([
                'Инвентаризация БД — сбор метаданных (типы/FK/профили, без данных)',
                'OPENCODE по каждой схеме — анализ кода проекта (нужен opencode в PATH)',
                'Применение результата — cascade_from / sample.criteria → dump_config.yaml',
            ]);
        } else {
            $io->text('Этапы:');
            $io->listing([
                'Инвентаризация БД — сбор метаданных (типы/FK/профили, без данных)',
                'Запуск OPENCODE — вручную по инструкции ниже (или повторите с --run)',
                'Применение результата — app:dbdump:apply-analysis → dump_config.yaml',
            ]);
        }
    }

    /**
     * @param array<string, string> $schemaFiles
     */
    private function runPipeline(SymfonyStyle $io, array $schemaFiles): int
    {
        if (!$this->runner->isAvailable()) {
            $io->warning('opencode не найден в PATH — автозапуск невозможен. Запустите вручную:');
            $this->printManualInstructions($io, $schemaFiles);
            return Command::SUCCESS;
        }

        $dataDir = $this->configStore->getDataDir($this->projectDir);
        $outRel = $dataDir . '/' . AnalysisPackageBuilder::OUT_DIR;
        $io->section('Этап 2/3 — OPENCODE по схемам');
        $io->note("Запуск OPENCODE автономно (--dangerously-skip-permissions). Агент пишет только в {$outRel}/.");
        foreach ($schemaFiles as $schema => $absPath) {
            $relFile = $dataDir . '/' . AnalysisPackageBuilder::ANALYSIS_DIR . '/schema_inventory.' . $schema . '.json';
            $prompt = "Обработай схему {$schema} по инструкции; результат запиши в {$outRel}/{$schema}.json";
            $io->section("Схема: {$schema}");
            $code = $this->runner->runAgent($this->projectDir, $relFile, $prompt);
            if ($code !== 0) {
                $io->warning("OPENCODE завершился с кодом {$code} для схемы {$schema}");
            }
        }

        $io->section('Этап 3/3 — применение результата к dump_config.yaml');
        $outDir = $this->projectDir . '/' . $outRel;
        $ingested = $this->ingestor->ingest($outDir);
        $stats = $this->enricher->enrich($this->configPath, $ingested);

        $io->success(sprintf(
            'Готово одной командой: cascade_from +%d, sample.criteria +%d',
            $stats['cascade_added'],
            $stats['criteria_added']
        ));
        return Command::SUCCESS;
    }

    /**
     * @param array<string, string> $schemaFiles
     */
    private function printManualInstructions(SymfonyStyle $io, array $schemaFiles): void
    {
        $dataDir = $this->configStore->getDataDir($this->projectDir);
        $io->section('Запуск OPENCODE вручную (скопируйте команды)');
        if (empty($schemaFiles)) {
            $io->text($this->runner->manualCommandHint($dataDir . '/' . AnalysisPackageBuilder::ANALYSIS_DIR . '/schema_inventory.json'));
        } else {
            $io->text('# по чанку на схему (рекомендуется для больших БД):');
            foreach ($schemaFiles as $schema => $absPath) {
                $relFile = $dataDir . '/' . AnalysisPackageBuilder::ANALYSIS_DIR . '/schema_inventory.' . $schema . '.json';
                $io->text($this->runner->manualCommandHint($relFile));
            }
        }
        $io->newLine();
        $io->text('Затем примените результат: php bin/console app:dbdump:apply-analysis');
        $io->note('Или повторите эту команду с флагом --run, чтобы сделать всё автоматически (нужен opencode в PATH).');
    }
}
