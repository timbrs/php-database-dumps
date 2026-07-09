<?php

namespace Timbrs\DatabaseDumps\Bridge\Laravel\Command;

use Illuminate\Console\Command;
use Timbrs\DatabaseDumps\Bridge\Laravel\LaravelLogger;
use Timbrs\DatabaseDumps\Contract\LoggerInterface;
use Timbrs\DatabaseDumps\Service\Ai\DbdumpConfigStore;
use Timbrs\DatabaseDumps\Service\Analysis\AnalysisIngestor;
use Timbrs\DatabaseDumps\Service\Analysis\AnalysisPackageBuilder;
use Timbrs\DatabaseDumps\Service\Analysis\ConfigEnricher;

class ApplyAnalysisCommand extends Command
{
    /** @var string */
    protected $signature = 'dbdump:apply-analysis';

    /** @var string */
    protected $description = 'Применить результаты OPENCODE (database/analysis/out/*.json) к dump_config.yaml';

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
        AnalysisIngestor $ingestor,
        ConfigEnricher $enricher,
        LoggerInterface $logger,
        string $projectDir,
        string $configPath,
        DbdumpConfigStore $configStore
    ) {
        parent::__construct();
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
        $this->info('Применение результатов анализа кода');

        $outDir = $this->projectDir . '/' . $this->configStore->getDataDir($this->projectDir)
            . '/' . AnalysisPackageBuilder::OUT_DIR;

        try {
            $ingested = $this->ingestor->ingest($outDir);
            $stats = $this->enricher->enrich($this->configPath, $ingested);
            $this->info(sprintf(
                'Обогащено: cascade_from +%d, sample.criteria +%d',
                $stats['cascade_added'],
                $stats['criteria_added']
            ));
            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->error('Ошибка применения анализа: ' . $e->getMessage());
            return self::FAILURE;
        }
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
