<?php

namespace Timbrs\DatabaseDumps\Bridge\Symfony\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Timbrs\DatabaseDumps\Bridge\Symfony\ConsoleLogger;
use Timbrs\DatabaseDumps\Contract\LoggerInterface;
use Timbrs\DatabaseDumps\Service\Importer\DatabaseImporter;
use Timbrs\DatabaseDumps\Service\Importer\ImportReport;

class DbInitCommand extends Command
{
    /** @var DatabaseImporter */
    private $importer;
    /** @var LoggerInterface */
    private $logger;

    public function __construct(DatabaseImporter $importer, LoggerInterface $logger)
    {
        $this->importer = $importer;
        $this->logger = $logger;
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
            ->addOption('ignore-schema-mismatch', null, InputOption::VALUE_NONE, 'Импортировать даже при расхождении схемы дампа и БД')
            ->addOption('out', null, InputOption::VALUE_REQUIRED, 'Записать отчёт импорта (JSON) в файл')
            ->setHelp(<<<'HELP'
Примеры:
  php bin/console app:dbdump:import                          Импорт всех дампов
  php bin/console app:dbdump:import --schema=public          Импорт только схемы public
  php bin/console app:dbdump:import --skip-before            Пропустить before_exec скрипты
  php bin/console app:dbdump:import --skip-after             Пропустить after_exec скрипты
  php bin/console app:dbdump:import --connection=secondary   Импорт из подключения secondary
  php bin/console app:dbdump:import --out=var/import.json    Отчёт импорта в файл

Скрипты:
  {data_dir}/before_exec/*.sql    Выполняются до импорта (по алфавиту)
  {data_dir}/after_exec/*.sql     Выполняются после импорта (по алфавиту)
  data_dir по умолчанию docker/database (config/packages/database_dumps.yaml)

Отчёт импорта (коды находок):
  I-1  таблица пропущена: колонки дампа расходятся со схемой БД (error)
  I-2  после заливки строк в таблице не столько, сколько в файле (error)
  I-3  sequence отстаёт от максимума колонки (warning, PostgreSQL)
  I-4  внешний ключ нарушен — строки без родителя (error)
Ошибки в отчёте дают ненулевой код возврата, даже если транзакция прошла.
HELP
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        if ($this->logger instanceof ConsoleLogger) {
            $this->logger->setIo($io);
        }

        $io->title('Инициализация БД с импортом дампов');

        $startTime = microtime(true);

        try {
            if ($input->getOption('ignore-schema-mismatch')) {
                $this->importer->setIgnoreSchemaMismatch(true);
            }

            $report = $this->importer->import(
                (bool) $input->getOption('skip-before'),
                (bool) $input->getOption('skip-after'),
                $input->getOption('schema') !== null ? (string) $input->getOption('schema') : null,
                $input->getOption('connection') !== null ? (string) $input->getOption('connection') : null
            );

            $duration = round(microtime(true) - $startTime, 2);
            $this->writeReport($input, $io, $report);
            $this->renderFindings($io, $report);

            if ($report->hasErrors()) {
                $io->error(sprintf(
                    'Импорт прошёл за %s сек, но отчёт содержит ошибки: %d. Смотрите таблицу выше.',
                    $duration,
                    $report->countBySeverity('error')
                ));

                return Command::FAILURE;
            }

            $io->success(sprintf(
                'БД успешно инициализирована за %s сек: таблиц %d, строк %d%s',
                $duration,
                $report->getTablesImported(),
                $report->getRowsLoaded(),
                $report->getTablesSkipped() > 0 ? sprintf(', пропущено %d', $report->getTablesSkipped()) : ''
            ));

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->writeReport($input, $io, $this->importer->getReport());
            $this->renderFindings($io, $this->importer->getReport());
            $io->error('Ошибка импорта: ' . $e->getMessage());
            $io->warning('Все изменения отменены (rollback)');

            if ($io->isVerbose()) {
                $io->note('Трейс: ' . $e->getTraceAsString());
            }

            return Command::FAILURE;
        }
    }

    private function renderFindings(SymfonyStyle $io, ImportReport $report): void
    {
        $findings = $report->getFindings();
        if ($findings === []) {
            return;
        }
        $rows = [];
        foreach ($findings as $finding) {
            $rows[] = [$finding->getCode(), $finding->getSeverity(), $finding->getTarget(), $finding->getMessage()];
        }
        $io->table(['код', 'уровень', 'таблица', 'что не так'], $rows);
    }

    private function writeReport(InputInterface $input, SymfonyStyle $io, ImportReport $report): void
    {
        $out = $input->getOption('out');
        if ($out === null || $out === '') {
            return;
        }
        $path = (string) $out;
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        $payload = array_merge(['generated_at' => gmdate('Y-m-d\TH:i:s\Z')], $report->toArray());
        file_put_contents(
            $path,
            (string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL
        );
        $io->writeln('Отчёт импорта записан: ' . $path);
    }
}
