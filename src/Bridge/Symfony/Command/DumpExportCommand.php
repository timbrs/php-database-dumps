<?php

namespace Timbrs\DatabaseDumps\Bridge\Symfony\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Timbrs\DatabaseDumps\Bridge\Symfony\ConsoleLogger;
use Timbrs\DatabaseDumps\Contract\LoggerInterface;
use Timbrs\DatabaseDumps\Service\Dumper\DatabaseDumper;
use Timbrs\DatabaseDumps\Service\Dumper\TableConfigResolver;

class DumpExportCommand extends Command
{
    /** @var DatabaseDumper */
    private $dumper;
    /** @var TableConfigResolver */
    private $configResolver;
    /** @var LoggerInterface */
    private $logger;

    public function __construct(DatabaseDumper $dumper, TableConfigResolver $configResolver, LoggerInterface $logger)
    {
        $this->dumper = $dumper;
        $this->configResolver = $configResolver;
        $this->logger = $logger;
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('app:dbdump:export')
            ->setDescription('Экспорт SQL дампа таблицы из БД (schema.table или "all")')
            ->addArgument('table', InputArgument::OPTIONAL, 'Имя таблицы (schema.table) или "all"')
            ->addOption('schema', 's', InputOption::VALUE_REQUIRED, 'Фильтр по схеме для "all"')
            ->addOption('connection', 'c', InputOption::VALUE_REQUIRED, 'Имя подключения (или "all")')
            ->addOption('no-cascade', null, InputOption::VALUE_NONE, 'Пропустить каскадную фильтрацию WHERE')
            ->addOption('no-faker', null, InputOption::VALUE_NONE, 'Пропустить замену ПД')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Показать план экспорта без выполнения')
            ->addOption('allow-prod-export', null, InputOption::VALUE_NONE,
                'Разрешить экспорт на production (после ручной верификации faker-конфига)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        if ($this->logger instanceof ConsoleLogger) {
            $this->logger->setIo($io);
        }

        if ($input->getOption('allow-prod-export')) {
            $this->dumper->setAllowProdExport(true);
        }

        $table = $input->getArgument('table');
        if ($table === null) {
            $this->showUsage($io);
            return Command::FAILURE;
        }

        if ($table === 'all') {
            return $this->exportAll($input, $io);
        }

        return $this->exportTable($input, $table, $io);
    }

    private function showUsage(SymfonyStyle $io): void
    {
        $io->text([
            'Использование:',
            '  export <schema.table>    Экспорт одной таблицы',
            '  export all               Экспорт всех таблиц из конфигурации',
            '',
            'Опции:',
            '  -s, --schema=SCHEMA              Фильтр по схеме (для "all")',
            '  -c, --connection=CONNECTION      Имя подключения (или "all")',
            '  --allow-prod-export              Разрешить экспорт с production',
        ]);
    }

    private function exportAll(InputInterface $input, SymfonyStyle $io): int
    {
        $schemaFilter = $input->getOption('schema');
        $connectionFilter = $input->getOption('connection');

        try {
            $tables = $this->configResolver->resolveAll($schemaFilter, $connectionFilter);

            if (empty($tables)) {
                $io->warning('Нет таблиц для экспорта в конфигурации');
                return Command::FAILURE;
            }

            if ($input->getOption('dry-run')) {
                return $this->dryRun($tables, $io);
            }

            $io->title('Экспорт всех таблиц согласно конфигурации');
            $startTime = microtime(true);

            $this->dumper->exportAll($tables);

            $duration = round(microtime(true) - $startTime, 2);
            $totalTables = count($tables);
            $io->success("Экспортировано таблиц: {$totalTables} за {$duration} сек");

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $io->error('Ошибка экспорта: ' . $e->getMessage());
            if ($io->isVerbose()) {
                $io->note('Трейс: ' . $e->getTraceAsString());
            }
            return Command::FAILURE;
        }
    }

    /**
     * @param array<\Timbrs\DatabaseDumps\Config\TableConfig> $tables
     */
    private function dryRun(array $tables, SymfonyStyle $io): int
    {
        $io->title('Dry-run: план экспорта');

        $rows = [];
        foreach ($tables as $config) {
            $mode = $config->isFullExport() ? 'full' : 'partial (limit ' . $config->getLimit() . ')';
            $where = $config->getWhere() ?? '-';
            $cascade = $config->getCascadeFrom() !== null ? count($config->getCascadeFrom()) . ' связей' : '-';
            $rows[] = [$config->getFullTableName(), $mode, $where, $cascade];
        }

        $io->table(['Таблица', 'Режим', 'WHERE', 'Cascade'], $rows);
        $io->note('Всего таблиц: ' . count($tables));
        return Command::SUCCESS;
    }

    private function exportTable(InputInterface $input, string $fullTableName, SymfonyStyle $io): int
    {
        if (strpos($fullTableName, '.') === false) {
            $io->error('Неверный формат таблицы. Используйте: schema.table');
            return Command::FAILURE;
        }

        $connectionFilter = $input->getOption('connection');
        [$schema, $table] = explode('.', $fullTableName, 2);

        $io->text("Экспорт: {$fullTableName}");

        try {
            $config = $this->configResolver->resolve($schema, $table, $connectionFilter);
            $this->dumper->exportTable($config);
            return Command::SUCCESS;
        } catch (\Exception $e) {
            $io->error('Ошибка: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }
}
