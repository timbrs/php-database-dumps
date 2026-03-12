<?php

namespace Timbrs\DatabaseDumps\Bridge\Laravel\Command;

use Timbrs\DatabaseDumps\Bridge\Laravel\LaravelLogger;
use Timbrs\DatabaseDumps\Contract\LoggerInterface;
use Timbrs\DatabaseDumps\Service\Importer\DatabaseImporter;
use Illuminate\Console\Command;

class DbInitCommand extends Command
{
    /** @var string */
    protected $signature = 'dbdump:import {--skip-before : Пропустить before_exec скрипты} {--skip-after : Пропустить after_exec скрипты} {--schema= : Импорт только указанной схемы} {--connection= : Имя подключения (или "all" для всех)} {--no-cascade : Пропустить топологическую сортировку импорта}';

    /** @var string */
    protected $description = 'Инициализация БД с импортом SQL дампов';

    /** @var string */
    protected $help = <<<'HELP'
Примеры:
  php artisan dbdump:import                          Импорт всех дампов
  php artisan dbdump:import --schema=public          Импорт только схемы public
  php artisan dbdump:import --skip-before            Пропустить before_exec скрипты
  php artisan dbdump:import --skip-after             Пропустить after_exec скрипты
  php artisan dbdump:import --connection=secondary   Импорт из подключения secondary

Скрипты:
  database/before_exec/*.sql      Выполняются до импорта (по алфавиту)
  database/after_exec/*.sql       Выполняются после импорта (по алфавиту)
HELP;

    /** @var DatabaseImporter */
    private $importer;

    /** @var LoggerInterface */
    private $logger;

    public function __construct(DatabaseImporter $importer, LoggerInterface $logger)
    {
        parent::__construct();
        $this->importer = $importer;
        $this->logger = $logger;
    }

    public function handle(): int
    {
        $this->setupLogger();

        $this->info('Инициализация БД с импортом дампов');

        $startTime = microtime(true);

        try {
            /** @var string|null $schema */
            $schema = $this->option('schema');
            /** @var string|null $connection */
            $connection = $this->option('connection');
            $this->importer->import(
                (bool) $this->option('skip-before'),
                (bool) $this->option('skip-after'),
                $schema,
                $connection
            );

            $duration = round(microtime(true) - $startTime, 2);
            $this->info("БД успешно инициализирована за {$duration} сек!");

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->error('Ошибка импорта: ' . $e->getMessage());
            $this->warn('Все изменения отменены (rollback)');

            if ($this->getOutput()->isVerbose()) {
                $this->line('Трейс: ' . $e->getTraceAsString());
            }

            return self::FAILURE;
        }
    }

    private function setupLogger(): void
    {
        if ($this->logger instanceof LaravelLogger) {
            $command = $this;
            $this->logger->setOutputCallback(function ($message) use ($command) {
                $command->line($message);
            });
        }
    }
}
