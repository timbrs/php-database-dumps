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
use Timbrs\DatabaseDumps\Service\Analysis\AnalysisPackageBuilder;

/**
 * Собирает пакет для анализа кода внешним агентом: инвентарь схемы (без значений данных),
 * пер-схемные инвентари, JSON-контракт вывода и инструкцию запуска.
 *
 * Команда НЕ запускает агента. Раньше запускала — через exec() из PHP, — но управлять агентом
 * из библиотеки оказалось неверным слоем: агент сам решает, чем и в каком порядке пользоваться,
 * и умеет вызвать эту команду, `validate` и `repair-configs` куда осмысленнее, чем PHP умеет
 * вызвать агента. Здесь остаётся детерминированная часть: собрать факты и сказать, где они лежат.
 * Применяет результат агента `app:dbdump:apply-analysis`.
 */
class PrepareAnalysisCommand extends Command
{
    /** @var AnalysisPackageBuilder */
    private $builder;

    /** @var string */
    private $projectDir;

    /** @var DbdumpConfigStore */
    private $configStore;

    /** @var LoggerInterface */
    private $logger;

    public function __construct(
        AnalysisPackageBuilder $builder,
        string $projectDir,
        DbdumpConfigStore $configStore,
        LoggerInterface $logger
    ) {
        $this->builder = $builder;
        $this->projectDir = rtrim($projectDir, '/\\');
        $this->configStore = $configStore;
        $this->logger = $logger;
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('app:dbdump:prepare-analysis')
            ->setDescription('Собрать пакет для анализа кода внешним агентом (инвентарь схемы + контракт вывода)')
            ->addOption('connection', 'c', InputOption::VALUE_REQUIRED, 'Имя подключения (по умолчанию — дефолтное)')
            ->addOption(
                'exact-counts',
                null,
                InputOption::VALUE_NONE,
                'Точный COUNT(*) по каждой таблице вместо оценки планировщика (полный проход по каждой таблице — не для боевой БД)'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        if ($this->logger instanceof ConsoleLogger) {
            $this->logger->setIo($io);
        }

        $io->title('Пакет для анализа кода');

        $connectionName = $input->getOption('connection');
        $connectionName = is_string($connectionName) && $connectionName !== '' ? $connectionName : null;

        $this->builder->setExactCounts((bool) $input->getOption('exact-counts'));

        try {
            $result = $this->builder->build($connectionName);
        } catch (\Exception $e) {
            $io->error('Не удалось собрать пакет: ' . $e->getMessage());

            return Command::FAILURE;
        }

        $io->success(sprintf('Собрано: %d таблиц, %d файлов', $result['tables'], count($result['paths'])));
        $this->printNextSteps($io, $result['schema_files']);

        return Command::SUCCESS;
    }

    /**
     * @param array<string, string> $schemaFiles
     */
    private function printNextSteps(SymfonyStyle $io, array $schemaFiles): void
    {
        $dataDir = $this->configStore->getDataDir($this->projectDir);
        $analysisDir = $dataDir . '/' . AnalysisPackageBuilder::ANALYSIS_DIR;

        $io->section('Что дальше');
        $io->text([
            'Пакет собран, запускать агента команда не будет — это делается снаружи.',
            '',
            'Вход для агента:',
            '  ' . $analysisDir . '/schema_inventory.json          полный инвентарь',
            '  ' . $analysisDir . '/schema_inventory.<schema>.json  по одной схеме (для прогона по чанку)',
            '  ' . $analysisDir . '/output_schema.json              контракт JSON-вывода',
            '',
            'Документация инструмента (рабочий цикл, команды, коды находок):',
            '  app:dbdump:docs → ' . $analysisDir . '/docs/',
            '',
            'Результат агент кладёт в ' . $dataDir . '/' . AnalysisPackageBuilder::OUT_DIR . '/<schema>.json,',
            'затем: app:dbdump:apply-analysis — применить, app:dbdump:check — проверить.',
        ]);

        if (!empty($schemaFiles)) {
            $io->text('Схем в пакете: ' . count($schemaFiles) . ' (' . implode(', ', array_keys($schemaFiles)) . ')');
        }
    }
}
