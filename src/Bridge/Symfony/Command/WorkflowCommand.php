<?php

namespace Timbrs\DatabaseDumps\Bridge\Symfony\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * app:dbdump — точка входа для человека и агента: рабочий цикл кратко и список команд.
 * Подробности пишет app:dbdump:docs.
 */
class WorkflowCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->setName('app:dbdump')
            ->setDescription('Рабочий цикл настройки выгрузки БД: какие команды и в каком порядке');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $workflow = DocsCommand::workflow();
        if ($workflow !== '') {
            $output->writeln($workflow, OutputInterface::OUTPUT_RAW);
        }

        $application = $this->getApplication();
        if ($application !== null) {
            $rows = [];
            foreach ($application->all() as $name => $command) {
                if (strpos($name, 'app:dbdump:') !== 0 || $command->getName() !== $name) {
                    continue;
                }
                $rows[] = [$name, $command->getDescription()];
            }
            usort($rows, function (array $a, array $b): int {
                return strcmp($a[0], $b[0]);
            });
            $io->section('Команды');
            $io->table(['команда', 'что делает'], $rows);
        }

        $io->text('Подробная документация: php bin/console app:dbdump:docs → {data_dir}/analysis/docs/');

        return Command::SUCCESS;
    }
}
