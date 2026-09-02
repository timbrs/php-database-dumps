<?php

namespace Timbrs\DatabaseDumps\Bridge\Symfony\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Timbrs\DatabaseDumps\Bridge\Symfony\ConsoleLogger;
use Timbrs\DatabaseDumps\Contract\LoggerInterface;
use Timbrs\DatabaseDumps\Service\Analysis\ConfigCriteriaRepairer;

/**
 * check-criteria: прогнать каждый sample.criterion уже сгенерированного конфига в БД и
 * записать отчёт о падающих — с текстом ошибки СУБД. Правит конфиг тот, кто читает отчёт.
 * См. ConfigCriteriaRepairer.
 */
class RepairConfigsCommand extends Command
{
    /** @var ConfigCriteriaRepairer */
    private $repairer;

    /** @var string */
    private $configPath;

    /** @var LoggerInterface */
    private $logger;

    public function __construct(ConfigCriteriaRepairer $repairer, string $configPath, LoggerInterface $logger)
    {
        $this->repairer = $repairer;
        $this->configPath = $configPath;
        $this->logger = $logger;
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('app:dbdump:check-criteria')
            // Прежнее имя команды: у неё был режим автопочинки через внешнего агента.
            ->setAliases(['app:dbdump:repair-configs'])
            ->setDescription('Прогнать sample.criteria конфига в БД и показать падающие (с ошибкой СУБД)')
            ->addOption('connection', 'c', InputOption::VALUE_REQUIRED, 'Имя подключения (по умолчанию — дефолтное)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        if ($this->logger instanceof ConsoleLogger) {
            $this->logger->setIo($io);
        }
        $io->title('Проверка criteria в конфиге');

        $connection = $input->getOption('connection');

        try {
            $result = $this->repairer->inspect(
                $this->configPath,
                $connection !== null ? (string) $connection : null
            );
        } catch (\Exception $e) {
            $io->error('Ошибка проверки criteria: ' . $e->getMessage());

            return Command::FAILURE;
        }

        if ($result['failing'] === 0) {
            $io->success(sprintf('Проверено criteria: %d — все исполняются в БД.', $result['tested']));

            return Command::SUCCESS;
        }

        $io->warning(sprintf(
            'Падающих criteria: %d в %d схемах (проверено %d).',
            $result['failing'],
            $result['schemas'],
            $result['tested']
        ));

        if ($result['report'] !== null) {
            $io->text('Отчёт с текстом ошибок: ' . $result['report']);
            $io->text('Экспорт такие criteria пропускает (SampleQueryBuilder), то есть выборка беднее задуманной.');
        }

        return Command::SUCCESS;
    }
}
