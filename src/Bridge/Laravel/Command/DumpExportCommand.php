<?php

namespace Timbrs\DatabaseDumps\Bridge\Laravel\Command;

use Timbrs\DatabaseDumps\Bridge\Laravel\LaravelLogger;
use Timbrs\DatabaseDumps\Contract\LoggerInterface;
use Timbrs\DatabaseDumps\Service\Dumper\DatabaseDumper;
use Timbrs\DatabaseDumps\Service\Dumper\TableConfigResolver;
use Illuminate\Console\Command;

class DumpExportCommand extends Command
{
    /** @var string */
    protected $signature = 'dbdump:export {table? : Имя таблицы (schema.table) или "all"} {--schema= : Фильтр по схеме для "all"} {--connection= : Имя подключения (или "all" для всех)} {--no-cascade : Пропустить каскадную фильтрацию WHERE} {--no-faker : Пропустить замену персональных данных}';

    /** @var string */
    protected $description = 'Экспорт SQL дампа таблицы из БД';

    /** @var string */
    protected $help = <<<'HELP'
Примеры:
  php artisan dbdump:export public.users              Экспорт таблицы users из схемы public
  php artisan dbdump:export all                       Экспорт всех настроенных таблиц
  php artisan dbdump:export all --schema=public       Экспорт таблиц схемы public
  php artisan dbdump:export all --connection=secondary Экспорт из подключения secondary
  php artisan dbdump:export all --connection=all      Экспорт из всех подключений
HELP;

    /** @var DatabaseDumper */
    private $dumper;

    /** @var TableConfigResolver */
    private $configResolver;

    /** @var LoggerInterface */
    private $logger;

    public function __construct(DatabaseDumper $dumper, TableConfigResolver $configResolver, LoggerInterface $logger)
    {
        parent::__construct();
        $this->dumper = $dumper;
        $this->configResolver = $configResolver;
        $this->logger = $logger;
    }

    public function handle(): int
    {
        $this->setupLogger();

        /** @var string|null $table */
        $table = $this->argument('table');

        if ($table === null) {
            $this->showUsage();
            return self::SUCCESS;
        }

        if ($table === 'all') {
            return $this->exportAll();
        }

        return $this->exportTable($table);
    }

    private function showUsage(): void
    {
        $this->line('');
        $this->line('<info>Использование:</info>');
        $this->line('  export <comment><schema.table></comment>    Экспорт одной таблицы');
        $this->line('  export <comment>all</comment>               Экспорт всех таблиц из конфигурации');
        $this->line('');
        $this->line('<info>Примеры:</info>');
        $this->line('  export <comment>public.users</comment>              — Экспорт таблицы users из схемы public');
        $this->line('  export <comment>all</comment>                       — Экспорт всех настроенных таблиц');
        $this->line('  export <comment>all --schema=public</comment>       — Экспорт таблиц схемы public');
        $this->line('  export <comment>all --connection=secondary</comment> — Экспорт из подключения secondary');
        $this->line('');
        $this->line('<info>Опции:</info>');
        $this->line('  <comment>-s, --schema=SCHEMA</comment>           Фильтр по схеме (для "all")');
        $this->line('  <comment>-c, --connection=CONNECTION</comment>   Имя подключения (или "all" для всех)');
        $this->line('  <comment>-h, --help</comment>                    Вывод справки');
    }

    private function exportAll(): int
    {
        $this->info('Экспорт всех таблиц согласно конфигурации');

        /** @var string|null $schemaFilter */
        $schemaFilter = $this->option('schema');
        /** @var string|null $connectionFilter */
        $connectionFilter = $this->option('connection');
        $startTime = microtime(true);

        try {
            $tables = $this->configResolver->resolveAll($schemaFilter, $connectionFilter);

            if (empty($tables)) {
                $this->warn('Нет таблиц для экспорта в конфигурации');
                return self::FAILURE;
            }

            $this->dumper->exportAll($tables);

            $duration = round(microtime(true) - $startTime, 2);
            $totalTables = count($tables);
            $this->info("Экспортировано таблиц: {$totalTables} за {$duration} сек");

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->error('Ошибка экспорта: ' . $e->getMessage());

            if ($this->getOutput()->isVerbose()) {
                $this->line('Трейс: ' . $e->getTraceAsString());
            }

            return self::FAILURE;
        }
    }

    private function exportTable(string $fullTableName): int
    {
        if (strpos($fullTableName, '.') === false) {
            $this->error('Неверный формат таблицы. Используйте формат: schema.table');
            return self::FAILURE;
        }

        /** @var string|null $connectionFilter */
        $connectionFilter = $this->option('connection');

        /** @var array{0: string, 1: string} $parts */
        $parts = explode('.', $fullTableName, 2);
        $schema = $parts[0];
        $table = $parts[1];

        $this->line("Экспорт: {$fullTableName}");

        try {
            $config = $this->configResolver->resolve($schema, $table, $connectionFilter);
            $this->dumper->exportTable($config);

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->error('Ошибка: ' . $e->getMessage());
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
