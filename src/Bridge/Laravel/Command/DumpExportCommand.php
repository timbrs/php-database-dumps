<?php

namespace Timbrs\DatabaseDumps\Bridge\Laravel\Command;

use Illuminate\Console\Command;
use Timbrs\DatabaseDumps\Bridge\Laravel\LaravelLogger;
use Timbrs\DatabaseDumps\Contract\LoggerInterface;
use Timbrs\DatabaseDumps\Service\Dumper\DatabaseDumper;
use Timbrs\DatabaseDumps\Service\Dumper\TableConfigResolver;

class DumpExportCommand extends Command
{
    /** @var string */
    protected $signature = 'dbdump:export'
        . ' {table? : Имя таблицы (schema.table) или "all"}'
        . ' {--s|schema= : Фильтр по схеме для "all"}'
        . ' {--c|connection= : Имя подключения (или "all")}'
        . ' {--dry-run : Показать план экспорта без выполнения}'
        . ' {--allow-prod-export : Разрешить экспорт на production}';

    /** @var string */
    protected $description = 'Экспорт SQL дампа таблицы из БД';

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

        if ($this->option('allow-prod-export')) {
            $this->dumper->setAllowProdExport(true);
        }

        $table = $this->argument('table');
        if ($table === null) {
            $this->showUsage();
            return self::FAILURE;
        }

        if ($table === 'all') {
            return $this->exportAll();
        }

        return $this->exportTable($table);
    }

    private function showUsage(): void
    {
        $this->line('');
        $this->line('Использование:');
        $this->line('  export <schema.table>    Экспорт одной таблицы');
        $this->line('  export all               Экспорт всех таблиц');
        $this->line('');
        $this->line('Опции:');
        $this->line('  -s, --schema=SCHEMA          Фильтр по схеме (для "all")');
        $this->line('  -c, --connection=CONN        Имя подключения (или "all")');
        $this->line('  --allow-prod-export          Разрешить экспорт с production');
    }

    private function exportAll(): int
    {
        $schemaFilter = $this->option('schema');
        $connectionFilter = $this->option('connection');

        try {
            $tables = $this->configResolver->resolveAll($schemaFilter, $connectionFilter);

            if (empty($tables)) {
                $this->warn('Нет таблиц для экспорта в конфигурации');
                return self::FAILURE;
            }

            if ($this->option('dry-run')) {
                return $this->dryRun($tables);
            }

            $this->info('Экспорт всех таблиц согласно конфигурации');
            $startTime = microtime(true);

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

    /**
     * @param array<\Timbrs\DatabaseDumps\Config\TableConfig> $tables
     */
    private function dryRun(array $tables): int
    {
        $this->info('Dry-run: план экспорта');
        $headers = ['Таблица', 'Режим', 'WHERE', 'Cascade'];
        $rows = [];
        foreach ($tables as $config) {
            $mode = $config->isFullExport() ? 'full' : 'partial (limit ' . $config->getLimit() . ')';
            $where = $config->getWhere() ?? '-';
            $cascade = $config->getCascadeFrom() !== null ? count($config->getCascadeFrom()) . ' связей' : '-';
            $rows[] = [$config->getFullTableName(), $mode, $where, $cascade];
        }
        $this->table($headers, $rows);
        $this->info('Всего таблиц: ' . count($tables));
        return self::SUCCESS;
    }

    private function exportTable(string $fullTableName): int
    {
        if (strpos($fullTableName, '.') === false) {
            $this->error('Неверный формат таблицы. Используйте: schema.table');
            return self::FAILURE;
        }

        $connectionFilter = $this->option('connection');
        [$schema, $table] = explode('.', $fullTableName, 2);

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
            $cmd = $this;
            $this->logger->setOutputCallback(function ($message) use ($cmd) {
                $cmd->line($message);
            });
        }
    }
}
