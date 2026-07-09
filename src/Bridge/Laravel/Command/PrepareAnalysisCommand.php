<?php

namespace Timbrs\DatabaseDumps\Bridge\Laravel\Command;

use Illuminate\Console\Command;
use Timbrs\DatabaseDumps\Bridge\Laravel\LaravelLogger;
use Timbrs\DatabaseDumps\Contract\LoggerInterface;
use Timbrs\DatabaseDumps\Service\Ai\DbdumpConfigStore;
use Timbrs\DatabaseDumps\Service\Analysis\AnalysisIngestor;
use Timbrs\DatabaseDumps\Service\Analysis\AnalysisPackageBuilder;
use Timbrs\DatabaseDumps\Service\Analysis\ConfigEnricher;
use Timbrs\DatabaseDumps\Service\Analysis\OpencodeRunner;

class PrepareAnalysisCommand extends Command
{
    /** @var string */
    protected $signature = 'dbdump:prepare-analysis'
        . ' {--c|connection= : Имя подключения (по умолчанию — дефолтное)}'
        . ' {--run : Сразу запустить OPENCODE по чанку на схему и применить результат — одной командой}';

    /** @var string */
    protected $description = 'Подготовить пакет для анализа кода хоста агентом OPENCODE';

    /** @var AnalysisPackageBuilder */
    private $builder;

    /** @var OpencodeRunner */
    private $runner;

    /** @var AnalysisIngestor */
    private $ingestor;

    /** @var ConfigEnricher */
    private $enricher;

    /** @var LoggerInterface */
    private $logger;

    /** @var string */
    private $projectDir;

    /** @var string */
    private $configPath;

    /** @var DbdumpConfigStore */
    private $configStore;

    public function __construct(
        AnalysisPackageBuilder $builder,
        OpencodeRunner $runner,
        AnalysisIngestor $ingestor,
        ConfigEnricher $enricher,
        LoggerInterface $logger,
        string $projectDir,
        string $configPath,
        DbdumpConfigStore $configStore
    ) {
        parent::__construct();
        $this->builder = $builder;
        $this->runner = $runner;
        $this->ingestor = $ingestor;
        $this->enricher = $enricher;
        $this->logger = $logger;
        $this->projectDir = rtrim($projectDir, '/\\');
        $this->configPath = $configPath;
        $this->configStore = $configStore;
    }

    public function handle(): int
    {
        $this->setupLogger();
        $this->info('Подготовка пакета анализа (OPENCODE)');

        $connection = $this->option('connection');

        try {
            $result = $this->builder->build($connection !== null && $connection !== '' ? (string) $connection : null);
            $this->info(sprintf('Подготовлено файлов: %d (таблиц: %d)', count($result['paths']), $result['tables']));

            if ($this->option('run')) {
                return $this->runPipeline($result['schema_files']);
            }

            $this->printManualInstructions($result['schema_files']);
            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->error('Ошибка подготовки пакета: ' . $e->getMessage());
            return self::FAILURE;
        }
    }

    /**
     * @param array<string, string> $schemaFiles
     */
    private function runPipeline(array $schemaFiles): int
    {
        if (!$this->runner->isAvailable()) {
            $this->warn('opencode не найден в PATH — автозапуск невозможен. Запустите вручную:');
            $this->printManualInstructions($schemaFiles);
            return self::SUCCESS;
        }

        $dataDir = $this->configStore->getDataDir($this->projectDir);
        $outRel = $dataDir . '/' . AnalysisPackageBuilder::OUT_DIR;
        $this->line("Запуск OPENCODE автономно (--dangerously-skip-permissions). Агент пишет только в {$outRel}/.");
        foreach ($schemaFiles as $schema => $absPath) {
            $relFile = $dataDir . '/' . AnalysisPackageBuilder::ANALYSIS_DIR . '/schema_inventory.' . $schema . '.json';
            $prompt = "Обработай схему {$schema} по инструкции; результат запиши в {$outRel}/{$schema}.json";
            $this->line("— схема: {$schema}");
            $code = $this->runner->runAgent($this->projectDir, $relFile, $prompt);
            if ($code !== 0) {
                $this->warn("OPENCODE завершился с кодом {$code} для схемы {$schema}");
            }
        }

        $outDir = $this->projectDir . '/' . $outRel;
        $ingested = $this->ingestor->ingest($outDir);
        $stats = $this->enricher->enrich($this->configPath, $ingested);

        $this->info(sprintf(
            'Готово одной командой: cascade_from +%d, sample.criteria +%d',
            $stats['cascade_added'],
            $stats['criteria_added']
        ));
        return self::SUCCESS;
    }

    /**
     * @param array<string, string> $schemaFiles
     */
    private function printManualInstructions(array $schemaFiles): void
    {
        $this->line('');
        $dataDir = $this->configStore->getDataDir($this->projectDir);
        $this->line('Запуск OPENCODE вручную (скопируйте команды):');
        if (empty($schemaFiles)) {
            $this->line($this->runner->manualCommandHint($dataDir . '/' . AnalysisPackageBuilder::ANALYSIS_DIR . '/schema_inventory.json'));
        } else {
            $this->line('# по чанку на схему (рекомендуется для больших БД):');
            foreach ($schemaFiles as $schema => $absPath) {
                $relFile = $dataDir . '/' . AnalysisPackageBuilder::ANALYSIS_DIR . '/schema_inventory.' . $schema . '.json';
                $this->line($this->runner->manualCommandHint($relFile));
            }
        }
        $this->line('');
        $this->line('Затем примените результат: php artisan dbdump:apply-analysis');
        $this->line('Или повторите с флагом --run, чтобы сделать всё автоматически (нужен opencode в PATH).');
    }

    private function setupLogger(): void
    {
        if ($this->logger instanceof LaravelLogger) {
            $cmd = $this;
            $this->logger->setOutputCallback(function ($message) use ($cmd) {
                $cmd->line($message);
            });
        }
    }
}
