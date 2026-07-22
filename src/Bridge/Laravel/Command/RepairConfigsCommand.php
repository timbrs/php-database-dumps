<?php

namespace Timbrs\DatabaseDumps\Bridge\Laravel\Command;

use Illuminate\Console\Command;
use Timbrs\DatabaseDumps\Bridge\Laravel\LaravelLogger;
use Timbrs\DatabaseDumps\Contract\LoggerInterface;
use Timbrs\DatabaseDumps\Service\Analysis\ConfigCriteriaRepairer;

/**
 * repair-configs: прогнать каждый sample.criterion уже сгенерированного конфига в БД и точечно
 * доисправить падающие через opencode (без полного пересбора инвентаря). См. ConfigCriteriaRepairer.
 */
class RepairConfigsCommand extends Command
{
    /** @var string */
    protected $signature = 'dbdump:repair-configs'
        . ' {--c|connection= : Имя подключения (по умолчанию — дефолтное)}'
        . ' {--repair-attempts=2 : Корректирующих перепрогонов на схему (0 — только проверка)}'
        . ' {--dry-run : Только проверить и показать падающие criteria, без починки}';

    /** @var string */
    protected $description = 'Прогнать sample.criteria конфига в БД и точечно доисправить падающие (opencode)';

    /** @var ConfigCriteriaRepairer */
    private $repairer;

    /** @var string */
    private $configPath;

    /** @var LoggerInterface */
    private $logger;

    public function __construct(ConfigCriteriaRepairer $repairer, string $configPath, LoggerInterface $logger)
    {
        parent::__construct();
        $this->repairer = $repairer;
        $this->configPath = $configPath;
        $this->logger = $logger;
    }

    public function handle(): int
    {
        if ($this->logger instanceof LaravelLogger) {
            $cmd = $this;
            $this->logger->setOutputCallback(function ($message) use ($cmd) {
                $cmd->line($message);
            });
        }
        $this->info('Проверка и починка criteria в конфиге');

        $connection = $this->option('connection');
        $attempts = $this->option('dry-run') ? 0 : (int) $this->option('repair-attempts');

        try {
            $result = $this->repairer->repair(
                $this->configPath,
                $attempts,
                ($connection !== null && $connection !== '') ? (string) $connection : null
            );
        } catch (\Exception $e) {
            $this->error('Ошибка repair-configs: ' . $e->getMessage());
            return self::FAILURE;
        }

        if ($result['failing'] === 0) {
            $this->info(sprintf('Проверено criteria: %d — все исполняются в БД.', $result['tested']));
            return self::SUCCESS;
        }

        if (!$result['repaired']) {
            $this->warn(sprintf(
                'Падающих criteria: %d в %d схемах (проверено %d). Починка не выполнялась (dry-run / нет opencode).',
                $result['failing'],
                $result['schemas'],
                $result['tested']
            ));
            return self::SUCCESS;
        }

        $this->info(sprintf(
            'Проверено %d, падало %d в %d схемах; применено исправлений criteria: +%d.',
            $result['tested'],
            $result['failing'],
            $result['schemas'],
            $result['criteria_added']
        ));
        return self::SUCCESS;
    }
}
