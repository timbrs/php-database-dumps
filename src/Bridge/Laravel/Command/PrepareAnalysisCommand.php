<?php

namespace Timbrs\DatabaseDumps\Bridge\Laravel\Command;

use Illuminate\Console\Command;
use Timbrs\DatabaseDumps\Bridge\Laravel\LaravelLogger;
use Timbrs\DatabaseDumps\Contract\LoggerInterface;
use Timbrs\DatabaseDumps\Service\Ai\DbdumpConfigStore;
use Timbrs\DatabaseDumps\Service\Analysis\AnalysisPackageBuilder;

/**
 * Собирает пакет для анализа кода внешним агентом. Агента НЕ запускает — см. одноимённую
 * команду Symfony-бриджа, там же и причина.
 */
class PrepareAnalysisCommand extends Command
{
    /** @var string */
    protected $signature = 'dbdump:prepare-analysis'
        . ' {--c|connection= : Имя подключения (по умолчанию — дефолтное)}';

    /** @var string */
    protected $description = 'Собрать пакет для анализа кода внешним агентом (инвентарь схемы + контракт вывода)';

    /** @var AnalysisPackageBuilder */
    private $builder;

    /** @var LoggerInterface */
    private $logger;

    /** @var string */
    private $projectDir;

    /** @var DbdumpConfigStore */
    private $configStore;

    public function __construct(
        AnalysisPackageBuilder $builder,
        LoggerInterface $logger,
        string $projectDir,
        DbdumpConfigStore $configStore
    ) {
        parent::__construct();
        $this->builder = $builder;
        $this->logger = $logger;
        $this->projectDir = rtrim($projectDir, '/\\');
        $this->configStore = $configStore;
    }

    public function handle(): int
    {
        if ($this->logger instanceof LaravelLogger) {
            $this->logger->setCommand($this);
        }

        $this->info('Пакет для анализа кода');

        $connection = $this->option('connection');
        $connectionName = is_string($connection) && $connection !== '' ? $connection : null;

        try {
            $result = $this->builder->build($connectionName);
        } catch (\Exception $e) {
            $this->error('Не удалось собрать пакет: ' . $e->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf('Собрано: %d таблиц, %d файлов', $result['tables'], count($result['paths'])));
        $this->printNextSteps($result['schema_files']);

        return self::SUCCESS;
    }

    /**
     * @param array<string, string> $schemaFiles
     */
    private function printNextSteps(array $schemaFiles): void
    {
        $dataDir = $this->configStore->getDataDir($this->projectDir);
        $analysisDir = $dataDir . '/' . AnalysisPackageBuilder::ANALYSIS_DIR;

        $this->line('');
        $this->line('Пакет собран, запускать агента команда не будет — это делается снаружи.');
        $this->line('');
        $this->line('Вход для агента:');
        $this->line('  ' . $analysisDir . '/schema_inventory.json          полный инвентарь');
        $this->line('  ' . $analysisDir . '/schema_inventory.<schema>.json  по одной схеме');
        $this->line('  ' . $analysisDir . '/output_schema.json              контракт JSON-вывода');
        $this->line('');
        $this->line('Результат агент кладёт в ' . $dataDir . '/' . AnalysisPackageBuilder::OUT_DIR . '/<schema>.json,');
        $this->line('затем: dbdump:apply-analysis — применить, dbdump:validate — проверить.');

        if (!empty($schemaFiles)) {
            $this->line('Схем в пакете: ' . count($schemaFiles) . ' (' . implode(', ', array_keys($schemaFiles)) . ')');
        }
    }
}
