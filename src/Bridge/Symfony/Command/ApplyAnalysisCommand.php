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
use Timbrs\DatabaseDumps\Service\Analysis\AnalysisIngestor;
use Timbrs\DatabaseDumps\Service\Analysis\AnalysisPackageBuilder;
use Timbrs\DatabaseDumps\Service\Analysis\ConfigEnricher;
use Timbrs\DatabaseDumps\Service\Analysis\DecisionApplier;

/**
 * Записывает принятые решения в конфиг выгрузки.
 *
 * Основной вход — `decisions.<schema>.json`: механическое применяется само, остальное —
 * с отметкой `accepted`. Старый вывод агента (`out/*.json`) применяется тем же вызовом,
 * пока живы прогоны `dbdump-mapper`.
 */
class ApplyAnalysisCommand extends Command
{
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

    /** @var FileSystemInterface|null */
    private $fileSystem;

    public function __construct(
        AnalysisIngestor $ingestor,
        ConfigEnricher $enricher,
        string $projectDir,
        string $configPath,
        DbdumpConfigStore $configStore,
        LoggerInterface $logger,
        FileSystemInterface $fileSystem = null
    ) {
        $this->ingestor = $ingestor;
        $this->enricher = $enricher;
        $this->projectDir = rtrim($projectDir, '/\\');
        $this->configPath = $configPath;
        $this->configStore = $configStore;
        $this->logger = $logger;
        $this->fileSystem = $fileSystem;
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('app:dbdump:apply-analysis')
            ->setAliases(['app:dbdump:apply'])
            ->setDescription('Применить решения ({data_dir}/analysis/decisions.*.json) и вывод агента к dump_config.yaml')
            ->addOption(
                'schema',
                's',
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Только эти схемы (по умолчанию — все, для которых есть решения)'
            )
            ->addOption(
                'decisions',
                null,
                InputOption::VALUE_REQUIRED,
                'Путь к конкретному файлу решений вместо поиска в {data_dir}/analysis/'
            )
            ->addOption(
                'legacy',
                null,
                InputOption::VALUE_NONE,
                'Только старый вывод агента ({data_dir}/analysis/out/*.json), решения не читать'
            )
            ->setHelp(
                "Решения с auto=true применяются без подтверждения (маскирование ПД, связь по внешнему\n"
                . "ключу БД, удаление таблицы, которой нет в базе). Остальным нужна отметка accepted:\n"
                . "её ставит агент по коду или человек. Существующее значение в YAML побеждает без\n"
                . "override; решение, исходившее из другого состояния конфига, помечается stale\n"
                . "и не применяется.\n\n"
                . "Итог с провенансом — в {data_dir}/analysis/" . ConfigEnricher::APPLY_REPORT_FILE . "."
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        if ($this->logger instanceof ConsoleLogger) {
            $this->logger->setIo($io);
        }
        $io->title('Применение решений по конфигу выгрузки');

        $dataDir = $this->projectDir . '/' . $this->configStore->getDataDir($this->projectDir);
        $analysisDir = $dataDir . '/' . AnalysisPackageBuilder::ANALYSIS_DIR;

        try {
            $applied = false;

            if (!$input->getOption('legacy')) {
                $decisions = $this->loadDecisions(
                    $analysisDir,
                    $input->getOption('decisions'),
                    $input->getOption('schema'),
                    $io
                );
                if ($decisions !== []) {
                    $stats = $this->enricher->applyDecisions($this->configPath, $decisions);
                    $this->renderDecisionStats($io, $stats);
                    $applied = true;
                }
            }

            $ingested = $this->ingestor->ingest($dataDir . '/' . AnalysisPackageBuilder::OUT_DIR);
            if ($ingested['cascade_from'] !== [] || $ingested['sample_criteria'] !== []) {
                $legacy = $this->enricher->enrich($this->configPath, $ingested);
                $io->text(sprintf(
                    'Старый вывод агента: cascade_from +%d, sample.criteria +%d',
                    $legacy['cascade_added'],
                    $legacy['criteria_added']
                ));
                $applied = true;
            }

            if (!$applied) {
                $io->warning(
                    'Применять нечего: нет ни решений с отметкой, ни вывода агента. '
                    . 'Сначала выполните prepare-analysis.'
                );

                return Command::SUCCESS;
            }

            $io->success('Конфиг обновлён. Отчёт: ' . $analysisDir . '/' . ConfigEnricher::APPLY_REPORT_FILE);

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $io->error('Ошибка применения: ' . $e->getMessage());

            return Command::FAILURE;
        }
    }

    /**
     * Решения из decisions.<schema>.json (или из явного файла).
     *
     * @param mixed             $explicitPath
     * @param array<int, mixed> $schemas
     *
     * @return array<int, array<string, mixed>>
     */
    private function loadDecisions(string $analysisDir, $explicitPath, array $schemas, SymfonyStyle $io): array
    {
        $files = is_string($explicitPath) && $explicitPath !== ''
            ? [$explicitPath]
            : $this->findDecisionFiles($analysisDir, $schemas);

        $decisions = [];
        foreach ($files as $file) {
            $content = $this->readFile($file);
            if ($content === null) {
                $io->warning('Файл решений не прочитан: ' . $file);
                continue;
            }
            $decoded = json_decode($content, true);
            if (!is_array($decoded) || !isset($decoded['decisions']) || !is_array($decoded['decisions'])) {
                $io->warning('Файл решений без ключа decisions: ' . $file);
                continue;
            }
            foreach ($decoded['decisions'] as $decision) {
                if (is_array($decision)) {
                    $decisions[] = $decision;
                }
            }
        }

        return $decisions;
    }

    /**
     * @param array<int, mixed> $schemas
     * @return array<int, string>
     */
    private function findDecisionFiles(string $analysisDir, array $schemas): array
    {
        if ($schemas !== []) {
            $files = [];
            foreach ($schemas as $schema) {
                if (!is_string($schema) || $schema === '') {
                    continue;
                }
                $path = $analysisDir . '/decisions.' . $schema . '.json';
                if ($this->exists($path)) {
                    $files[] = $path;
                }
            }

            return $files;
        }

        if ($this->fileSystem !== null && $this->fileSystem->isDirectory($analysisDir)) {
            // decisions_schema.json — контракт, а не решения: под маску decisions.*.json
            // он не попадает, но перестраховаться дешевле, чем ловить пустой ключ.
            $found = [];
            foreach ($this->fileSystem->findFiles($analysisDir, 'decisions.*.json') as $file) {
                if (basename($file) !== 'decisions_schema.json') {
                    $found[] = $file;
                }
            }
            sort($found);

            return $found;
        }

        $glob = glob($analysisDir . '/decisions.*.json');

        return $glob === false ? [] : $glob;
    }

    private function exists(string $path): bool
    {
        return $this->fileSystem !== null ? $this->fileSystem->exists($path) : is_file($path);
    }

    private function readFile(string $path): ?string
    {
        if ($this->fileSystem !== null) {
            if (!$this->fileSystem->exists($path)) {
                return null;
            }

            return $this->fileSystem->read($path);
        }
        $content = is_file($path) ? @file_get_contents($path) : false;

        return $content === false ? null : $content;
    }

    /**
     * @param array<string, mixed> $stats
     */
    private function renderDecisionStats(SymfonyStyle $io, array $stats): void
    {
        $io->text(sprintf(
            'Решения: %d записано, %d пропущено, %d устарело, %d отброшено',
            $stats['applied'],
            $stats['skipped'],
            $stats['stale'],
            $stats['invalid']
        ));

        if ($stats['stale'] > 0) {
            $io->warning(
                'Часть решений устарела: конфиг правили после анализа. '
                . 'Перезапустите prepare-analysis, чтобы получить актуальные предложения.'
            );
        }

        $rows = [];
        foreach ($stats['results'] as $entry) {
            if ($entry['status'] !== DecisionApplier::STATUS_APPLIED) {
                continue;
            }
            $rows[] = [
                (string) $entry['table'],
                $entry['column'] === null ? '—' : (string) $entry['column'],
                (string) $entry['kind'],
                (string) $entry['rule'],
                (string) $entry['why'],
            ];
        }
        if ($rows !== []) {
            $io->table(['таблица', 'колонка', 'что', 'правило', 'почему'], $rows);
        }
    }
}
