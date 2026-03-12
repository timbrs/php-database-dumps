<?php

namespace Timbrs\DatabaseDumps\Bridge\Symfony\Command;

use Timbrs\DatabaseDumps\Service\Dumper\DatabaseDumper;
use Timbrs\DatabaseDumps\Service\Dumper\TableConfigResolver;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class DumpExportCommand extends Command
{
    /** @var DatabaseDumper */
    private $dumper;
    /** @var TableConfigResolver */
    private $configResolver;

    public function __construct(
        DatabaseDumper $dumper,
        TableConfigResolver $configResolver
    ) {
        $this->dumper = $dumper;
        $this->configResolver = $configResolver;
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('app:dbdump:export')
            ->setDescription('Экспорт SQL дампа таблицы из БД (schema.table или "all")')
            ->addArgument('table', InputArgument::OPTIONAL, 'Имя таблицы (schema.table) или "all" для всех таблиц')
            ->addOption('schema', 's', InputOption::VALUE_REQUIRED, 'Фильтр по схеме для "all"')
            ->addOption('connection', 'c', InputOption::VALUE_REQUIRED, 'Имя подключения (или "all" для всех)')
            ->addOption('no-cascade', null, InputOption::VALUE_NONE, 'Пропустить каскадную фильтрацию WHERE')
            ->addOption('no-faker', null, InputOption::VALUE_NONE, 'Пропустить замену персональных данных')
            ->setHelp(<<<'HELP'
Примеры:
  php bin/console app:dbdump:export public.users              Экспорт таблицы users из схемы public
  php bin/console app:dbdump:export all                       Экспорт всех настроенных таблиц
  php bin/console app:dbdump:export all --schema=public       Экспорт таблиц схемы public
  php bin/console app:dbdump:export all --connection=secondary Экспорт из подключения secondary
  php bin/console app:dbdump:export all --connection=all      Экспорт из всех подключений
HELP
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $table = $input->getArgument('table');

        if ($table === null) {
            $this->showUsage($io);
            return Command::SUCCESS;
        }

        if ($table === 'all') {
            return $this->exportAll($input, $io);
        }

        return $this->exportTable($input, $table, $io);
    }

    private function showUsage(SymfonyStyle $io): void
    {
        $io->text([
            '<info>Использование:</info>',
            '  export <comment><schema.table></comment>    Экспорт одной таблицы',
            '  export <comment>all</comment>               Экспорт всех таблиц из конфигурации',
            '',
            '<info>Примеры:</info>',
            '  export <comment>public.users</comment>              — Экспорт таблицы users из схемы public',
            '  export <comment>all</comment>                       — Экспорт всех настроенных таблиц',
            '  export <comment>all --schema=public</comment>       — Экспорт таблиц схемы public',
            '  export <comment>all --connection=secondary</comment> — Экспорт из подключения secondary',
            '',
            '<info>Опции:</info>',
            '  <comment>-s, --schema=SCHEMA</comment>           Фильтр по схеме (для "all")',
            '  <comment>-c, --connection=CONNECTION</comment>   Имя подключения (или "all" для всех)',
            '  <comment>-h, --help</comment>                    Вывод справки',
        ]);
    }

    private function exportAll(InputInterface $input, SymfonyStyle $io): int
    {
        $io->title('Экспорт всех таблиц согласно конфигурации');

        $schemaFilter = $input->getOption('schema');
        $connectionFilter = $input->getOption('connection');
        $startTime = microtime(true);

        try {
            $tables = $this->configResolver->resolveAll($schemaFilter, $connectionFilter);

            if (empty($tables)) {
                $io->warning('Нет таблиц для экспорта в конфигурации');
                return Command::FAILURE;
            }

            $io->section('Полный экспорт таблиц');
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

    private function exportTable(InputInterface $input, string $fullTableName, SymfonyStyle $io): int
    {
        // Разбор schema.table
        if (strpos($fullTableName, '.') === false) {
            $io->error("Неверный формат таблицы. Используйте format: schema.table");
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
            $io->error("Ошибка: " . $e->getMessage());
            return Command::FAILURE;
        }
    }
}
