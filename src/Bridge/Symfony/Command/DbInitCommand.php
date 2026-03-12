<?php

namespace Timbrs\DatabaseDumps\Bridge\Symfony\Command;

use Timbrs\DatabaseDumps\Service\Importer\DatabaseImporter;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class DbInitCommand extends Command
{
    /** @var DatabaseImporter */
    private $importer;

    public function __construct(DatabaseImporter $importer)
    {
        $this->importer = $importer;
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('app:dbdump:import')
            ->setDescription('Инициализация БД с импортом SQL дампов')
            ->addOption('skip-before', null, InputOption::VALUE_NONE, 'Пропустить before_exec скрипты')
            ->addOption('skip-after', null, InputOption::VALUE_NONE, 'Пропустить after_exec скрипты')
            ->addOption('schema', 's', InputOption::VALUE_REQUIRED, 'Импорт только указанной схемы')
            ->addOption('connection', 'c', InputOption::VALUE_REQUIRED, 'Имя подключения (или "all" для всех)')
            ->addOption('no-cascade', null, InputOption::VALUE_NONE, 'Пропустить топологическую сортировку импорта')
            ->setHelp(<<<'HELP'
Примеры:
  php bin/console app:dbdump:import                          Импорт всех дампов
  php bin/console app:dbdump:import --schema=public          Импорт только схемы public
  php bin/console app:dbdump:import --skip-before            Пропустить before_exec скрипты
  php bin/console app:dbdump:import --skip-after             Пропустить after_exec скрипты
  php bin/console app:dbdump:import --connection=secondary   Импорт из подключения secondary

Скрипты:
  database/before_exec/*.sql      Выполняются до импорта (по алфавиту)
  database/after_exec/*.sql       Выполняются после импорта (по алфавиту)
HELP
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->title('Инициализация БД с импортом дампов');

        $startTime = microtime(true);

        try {
            $this->importer->import(
                $input->getOption('skip-before'),
                $input->getOption('skip-after'),
                $input->getOption('schema'),
                $input->getOption('connection')
            );

            $duration = round(microtime(true) - $startTime, 2);
            $io->success("БД успешно инициализирована за {$duration} сек!");

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $io->error('Ошибка импорта: ' . $e->getMessage());
            $io->warning('Все изменения отменены (rollback)');

            if ($io->isVerbose()) {
                $io->note('Трейс: ' . $e->getTraceAsString());
            }

            return Command::FAILURE;
        }
    }
}
