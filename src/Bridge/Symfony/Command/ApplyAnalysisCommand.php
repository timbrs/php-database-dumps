<?php

namespace Timbrs\DatabaseDumps\Bridge\Symfony\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Timbrs\DatabaseDumps\Service\Analysis\AnalysisIngestor;
use Timbrs\DatabaseDumps\Service\Analysis\AnalysisPackageBuilder;
use Timbrs\DatabaseDumps\Service\Analysis\ConfigEnricher;

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

    public function __construct(AnalysisIngestor $ingestor, ConfigEnricher $enricher, string $projectDir, string $configPath)
    {
        $this->ingestor = $ingestor;
        $this->enricher = $enricher;
        $this->projectDir = rtrim($projectDir, '/\\');
        $this->configPath = $configPath;
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('app:dbdump:apply-analysis')
            ->setDescription('Применить результаты OPENCODE (database/analysis/out/*.json) к dump_config.yaml');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Применение результатов анализа кода');

        $outDir = $this->projectDir . '/' . AnalysisPackageBuilder::OUT_DIR;

        try {
            $ingested = $this->ingestor->ingest($outDir);
            $stats = $this->enricher->enrich($this->configPath, $ingested);
            $io->success(sprintf(
                'Обогащено: cascade_from +%d, sample.criteria +%d',
                $stats['cascade_added'],
                $stats['criteria_added']
            ));
            return Command::SUCCESS;
        } catch (\Exception $e) {
            $io->error('Ошибка применения анализа: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }
}
