<?php

namespace Timbrs\DatabaseDumps\Bridge\Laravel\Command;

use Illuminate\Console\Command;
use Timbrs\DatabaseDumps\Bridge\Laravel\LaravelLogger;
use Timbrs\DatabaseDumps\Contract\LoggerInterface;
use Timbrs\DatabaseDumps\Service\Analysis\ConfigCriteriaRepairer;

/**
 * check-criteria: прогнать каждый sample.criterion уже сгенерированного конфига в БД и
 * записать отчёт о падающих — с текстом ошибки СУБД. Правит конфиг тот, кто читает отчёт.
 * См. ConfigCriteriaRepairer.
 */
class RepairConfigsCommand extends Command
{
    /** @var string */
    protected $signature = 'dbdump:check-criteria'
        . ' {--c|connection= : Имя подключения (по умолчанию — дефолтное)}';

    /** @var string */
    protected $description = 'Прогнать sample.criteria конфига в БД и показать падающие (с ошибкой СУБД)';

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
        // Прежнее имя команды: у неё был режим автопочинки через внешнего агента.
        $this->setAliases(['dbdump:repair-configs']);
    }

    public function handle(): int
    {
        if ($this->logger instanceof LaravelLogger) {
            $cmd = $this;
            $this->logger->setOutputCallback(function ($message) use ($cmd) {
                $cmd->line($message);
            });
        }
        $this->info('Проверка criteria в конфиге');

        $connection = $this->option('connection');

        try {
            $result = $this->repairer->inspect(
                $this->configPath,
                ($connection !== null && $connection !== '') ? (string) $connection : null
            );
        } catch (\Exception $e) {
            $this->error('Ошибка проверки criteria: ' . $e->getMessage());

            return self::FAILURE;
        }

        if ($result['failing'] === 0) {
            $this->info(sprintf('Проверено criteria: %d — все исполняются в БД.', $result['tested']));

            return self::SUCCESS;
        }

        $this->warn(sprintf(
            'Падающих criteria: %d в %d схемах (проверено %d).',
            $result['failing'],
            $result['schemas'],
            $result['tested']
        ));

        if ($result['report'] !== null) {
            $this->line('Отчёт с текстом ошибок: ' . $result['report']);
            $this->line('Экспорт такие criteria пропускает (SampleQueryBuilder), то есть выборка беднее задуманной.');
        }

        return self::SUCCESS;
    }
}
