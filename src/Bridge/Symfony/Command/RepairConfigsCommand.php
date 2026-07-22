<?php

namespace Timbrs\DatabaseDumps\Bridge\Symfony\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Timbrs\DatabaseDumps\Bridge\Symfony\ConsoleLogger;
use Timbrs\DatabaseDumps\Contract\LoggerInterface;
use Timbrs\DatabaseDumps\Service\Analysis\AnalysisRepairLoop;
use Timbrs\DatabaseDumps\Service\Analysis\ConfigCriteriaRepairer;

/**
 * repair-configs: прогнать каждый sample.criterion уже сгенерированного конфига в БД и точечно
 * доисправить падающие через opencode (без полного пересбора инвентаря). См. ConfigCriteriaRepairer.
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
            ->setName('app:dbdump:repair-configs')
            ->setDescription('Прогнать sample.criteria конфига в БД и точечно доисправить падающие (opencode)')
            ->addOption('connection', 'c', InputOption::VALUE_REQUIRED, 'Имя подключения (по умолчанию — дефолтное)')
            ->addOption('repair-attempts', null, InputOption::VALUE_REQUIRED, 'Корректирующих перепрогонов на схему (0 — только проверка)', (string) AnalysisRepairLoop::DEFAULT_MAX_ATTEMPTS)
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Только проверить и показать падающие criteria, без починки');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        if ($this->logger instanceof ConsoleLogger) {
            $this->logger->setIo($io);
        }
        $io->title('Проверка и починка criteria в конфиге');

        $connection = $input->getOption('connection');
        $attempts = $input->getOption('dry-run') ? 0 : (int) $input->getOption('repair-attempts');

        try {
            $result = $this->repairer->repair(
                $this->configPath,
                $attempts,
                $connection !== null ? (string) $connection : null
            );
        } catch (\Exception $e) {
            $io->error('Ошибка repair-configs: ' . $e->getMessage());
            return Command::FAILURE;
        }

        if ($result['failing'] === 0) {
            $io->success(sprintf('Проверено criteria: %d — все исполняются в БД.', $result['tested']));
            return Command::SUCCESS;
        }

        if (!$result['repaired']) {
            $io->warning(sprintf(
                'Падающих criteria: %d в %d схемах (проверено %d). Починка не выполнялась (dry-run / нет opencode).',
                $result['failing'],
                $result['schemas'],
                $result['tested']
            ));
            return Command::SUCCESS;
        }

        $io->success(sprintf(
            'Проверено %d, падало %d в %d схемах; применено исправлений criteria: +%d.',
            $result['tested'],
            $result['failing'],
            $result['schemas'],
            $result['criteria_added']
        ));
        return Command::SUCCESS;
    }
}
