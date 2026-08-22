<?php

namespace Timbrs\DatabaseDumps\Bridge\Laravel\Command;

use Illuminate\Console\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Timbrs\DatabaseDumps\Bridge\Laravel\LaravelLogger;
use Timbrs\DatabaseDumps\Contract\FileSystemInterface;
use Timbrs\DatabaseDumps\Contract\LoggerInterface;
use Timbrs\DatabaseDumps\Service\Ai\DbdumpConfigStore;
use Timbrs\DatabaseDumps\Service\Validation\AuditFixer;
use Timbrs\DatabaseDumps\Service\Validation\ConfigAuditor;
use Timbrs\DatabaseDumps\Service\Validation\ConfigDocument;
use Timbrs\DatabaseDumps\Service\Validation\Finding;
use Timbrs\DatabaseDumps\Service\Validation\InventoryReader;
use Timbrs\DatabaseDumps\Service\Validation\JsonReportWriter;
use Timbrs\DatabaseDumps\Service\Validation\TextReportRenderer;

/**
 * validate: детерминированный аудит конфигурации выгрузки БЕЗ подключения к БД.
 * Тот же аудитор, что и в Symfony-бридже (см. ValidateConfigCommand там).
 */
class ValidateConfigCommand extends Command
{
    /** @var string */
    protected $signature = 'dbdump:validate'
        . ' {--config= : Путь к dump_config.yaml}'
        . ' {--inventory= : Путь к schema_inventory.json}'
        . ' {--s|schema=* : Проверить только эту схему (можно повторять)}'
        . ' {--format=text : Формат вывода: text|json}'
        . ' {--out= : Записать отчёт в файл}'
        . ' {--severity=note : Порог вывода находок: error|warning|note}'
        . ' {--fix : Применить механически однозначные правки}';

    /** @var string */
    protected $description = 'Проверить конфиг выгрузки по слепку схемы (без подключения к БД)';

    /** @var ConfigAuditor */
    private $auditor;

    /** @var AuditFixer */
    private $fixer;

    /** @var JsonReportWriter */
    private $reportWriter;

    /** @var FileSystemInterface */
    private $fileSystem;

    /** @var DbdumpConfigStore */
    private $configStore;

    /** @var string */
    private $projectDir;

    /** @var string */
    private $configPath;

    /** @var LoggerInterface */
    private $logger;

    public function __construct(
        ConfigAuditor $auditor,
        AuditFixer $fixer,
        JsonReportWriter $reportWriter,
        FileSystemInterface $fileSystem,
        DbdumpConfigStore $configStore,
        string $projectDir,
        string $configPath,
        LoggerInterface $logger
    ) {
        parent::__construct();
        $this->auditor = $auditor;
        $this->fixer = $fixer;
        $this->reportWriter = $reportWriter;
        $this->fileSystem = $fileSystem;
        $this->configStore = $configStore;
        $this->projectDir = rtrim($projectDir, '/\\');
        $this->configPath = $configPath;
        $this->logger = $logger;
    }

    public function handle(): int
    {
        $format = (string) $this->option('format');
        if (!in_array($format, ['text', 'json'], true)) {
            $this->error('Неизвестный формат: ' . $format . ' (допустимы text и json)');
            return self::FAILURE;
        }

        $severity = (string) $this->option('severity');
        if (!in_array($severity, Finding::SEVERITIES, true)) {
            $this->error('Неизвестный порог: ' . $severity . ' (допустимы ' . implode(', ', Finding::SEVERITIES) . ')');
            return self::FAILURE;
        }

        if ($this->logger instanceof LaravelLogger && $format !== 'json') {
            $cmd = $this;
            $this->logger->setOutputCallback(function ($message) use ($cmd) {
                $cmd->line($message);
            });
        }

        $configPath = $this->resolveConfigPath();
        $inventoryPath = $this->resolveInventoryPath();
        /** @var array<int, string> $schemas */
        $schemas = (array) $this->option('schema');

        $inventory = new InventoryReader($this->fileSystem, $inventoryPath);
        $result = $this->auditor->audit($configPath, $inventory, $schemas);

        if ($this->option('fix')) {
            $fixReport = $this->fixer->fix(
                ConfigDocument::load($this->fileSystem, $configPath),
                $result->getFindings()
            );
            $this->reportFixes($fixReport, $format);

            if ($fixReport['applied'] > 0) {
                $inventory = new InventoryReader($this->fileSystem, $inventoryPath);
                $result = $this->auditor->audit($configPath, $inventory, $schemas);
            }
        }

        $rendered = $format === 'json'
            ? $this->reportWriter->toJson($result)
            : implode(PHP_EOL, (new TextReportRenderer())->render($result, $severity));

        $out = $this->option('out');
        if ($out !== null && $out !== '') {
            $path = $this->absolutize((string) $out);
            $dir = dirname($path);
            if ($dir !== '' && !$this->fileSystem->isDirectory($dir)) {
                $this->fileSystem->createDirectory($dir);
            }
            $this->fileSystem->writeAtomic($path, $rendered . PHP_EOL);
            if ($format !== 'json') {
                $this->raw('Отчёт записан: ' . $path);
            }
        }

        $this->raw($rendered);

        return $result->hasErrors() ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @param array{applied: int, skipped: int, files: array<int, string>, by_code: array<string, int>, errors: array<int, string>} $report
     */
    private function reportFixes(array $report, string $format): void
    {
        if ($format === 'json') {
            $this->logger->info(sprintf(
                'validate --fix: применено %d, пропущено %d, файлов %d',
                $report['applied'],
                $report['skipped'],
                count($report['files'])
            ));
            return;
        }

        $parts = [];
        foreach ($report['by_code'] as $code => $count) {
            $parts[] = $code . ': ' . $count;
        }
        $this->raw(sprintf(
            'Автоправки: применено %d (%s), пропущено %d, файлов изменено %d',
            $report['applied'],
            empty($parts) ? '—' : implode(', ', $parts),
            $report['skipped'],
            count($report['files'])
        ));

        foreach ($report['files'] as $file) {
            $this->raw('  изменён ' . $file);
        }
        foreach ($report['errors'] as $error) {
            $this->raw('  ! ' . $error);
        }
    }

    /**
     * Вывод без разбора консольных тегов: в сообщениях находок попадаются «<» из SQL.
     */
    private function raw(string $message): void
    {
        $this->getOutput()->writeln($message, OutputInterface::OUTPUT_RAW);
    }

    private function resolveConfigPath(): string
    {
        $option = $this->option('config');
        if ($option !== null && $option !== '') {
            return $this->absolutize((string) $option);
        }
        return $this->configPath;
    }

    private function resolveInventoryPath(): string
    {
        $option = $this->option('inventory');
        if ($option !== null && $option !== '') {
            return $this->absolutize((string) $option);
        }

        $dataDir = $this->configStore->getDataDir($this->projectDir);

        return $this->projectDir . '/' . $dataDir . '/analysis/' . InventoryReader::DEFAULT_FILE;
    }

    private function absolutize(string $path): string
    {
        if ($path === '') {
            return $path;
        }
        $isAbsolute = substr($path, 0, 1) === '/'
            || preg_match('#^[a-zA-Z]:[\\\\/]#', $path) === 1
            || substr($path, 0, 2) === '\\\\';

        return $isAbsolute ? $path : $this->projectDir . '/' . $path;
    }
}
