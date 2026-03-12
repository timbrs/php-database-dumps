<?php

namespace Timbrs\DatabaseDumps\Bridge\Laravel\Command;

use Timbrs\DatabaseDumps\Bridge\Laravel\LaravelLogger;
use Timbrs\DatabaseDumps\Contract\LoggerInterface;
use Timbrs\DatabaseDumps\Service\ConfigGenerator\ConfigGenerator;
use Illuminate\Console\Command;

class PrepareConfigCommand extends Command
{
    /** @var string */
    protected $signature = 'dbdump:prepare-config {mode? : Режим: all, schema=<name>, table=<schema.table>, new} {--threshold=500 : Порог строк для partial_export} {--force : Перезаписать без подтверждения} {--no-cascade : Пропустить обнаружение FK и генерацию cascade_from} {--no-faker : Пропустить обнаружение персональных данных} {--no-split : Генерировать единый YAML без разделения по схемам}';

    /** @var string */
    protected $description = 'Автоматическая генерация dump_config.yaml на основе структуры БД';

    /** @var string */
    protected $help = <<<'HELP'
Анализирует структуру БД и генерирует dump_config.yaml.
Таблицы с количеством строк <= threshold попадают в full_export,
остальные — в partial_export с лимитом.

Режимы:
  all           Полная регенерация конфигурации (перезаписывает файл)
  schema=<name> Перегенерация одной схемы, мёрж в существующий конфиг
  table=<s.t>   Перегенерация одной таблицы (schema.table), мёрж в существующий конфиг
  new           Обнаружение и дописывание новых таблиц (не затрагивает существующие)

Примеры:
  php artisan dbdump:prepare-config all                        Полная регенерация
  php artisan dbdump:prepare-config all --threshold=1000       Порог 1000 строк
  php artisan dbdump:prepare-config schema=billing             Перегенерировать схему billing
  php artisan dbdump:prepare-config table=public.users         Перегенерировать таблицу public.users
  php artisan dbdump:prepare-config new                        Добавить новые таблицы
HELP;

    /** @var ConfigGenerator */
    private $generator;

    /** @var LoggerInterface */
    private $logger;

    /** @var string */
    private $configPath;

    public function __construct(ConfigGenerator $generator, LoggerInterface $logger, string $configPath)
    {
        parent::__construct();
        $this->generator = $generator;
        $this->logger = $logger;
        $this->configPath = $configPath;
    }

    public function handle(): int
    {
        $this->setupLogger();

        $this->info('Генерация dump_config.yaml');

        /** @var string|null $modeArg */
        $modeArg = $this->argument('mode');

        if ($modeArg === null || $modeArg === '') {
            $this->error('Не указан режим работы.');
            $this->printModeUsage();
            return self::FAILURE;
        }

        $parsed = $this->parseMode($modeArg);

        if ($parsed === null) {
            $this->error("Неизвестный режим: {$modeArg}");
            $this->printModeUsage();
            return self::FAILURE;
        }

        /** @var string $thresholdValue */
        $thresholdValue = $this->option('threshold');
        $threshold = (int) $thresholdValue;

        if ($threshold <= 0) {
            $this->error('Порог должен быть положительным числом');
            return self::FAILURE;
        }

        // Подтверждение перезаписи только для режима all
        if ($parsed['mode'] === ConfigGenerator::MODE_ALL) {
            $force = $this->option('force');
            if (!$force && file_exists($this->configPath)) {
                /** @var bool $confirmed */
                $confirmed = $this->confirm("Файл {$this->configPath} уже существует. Перезаписать?", false);
                if (!$confirmed) {
                    $this->warn('Отменено');
                    return self::SUCCESS;
                }
            }
        }

        try {
            if ($this->option('no-cascade')) {
                $this->generator->setCascadeEnabled(false);
            }
            if ($this->option('no-faker')) {
                $this->generator->setFakerEnabled(false);
            }
            if ($this->option('no-split')) {
                $this->generator->setSplitBySchema(false);
            }

            $this->line("Режим: {$modeArg}");
            $this->line("Порог строк: {$threshold}");
            $this->line("Путь: {$this->configPath}");

            $this->generator->setMode($parsed['mode'], $parsed['scope']);
            $stats = $this->generator->generate($this->configPath, $threshold);

            $this->info(sprintf(
                "Конфигурация сгенерирована: full=%d, partial=%d, пропущено=%d, пустых=%d",
                $stats['full'],
                $stats['partial'],
                $stats['skipped'],
                $stats['empty']
            ));

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->error('Ошибка генерации: ' . $e->getMessage());

            if ($this->getOutput()->isVerbose()) {
                $this->line('Трейс: ' . $e->getTraceAsString());
            }

            return self::FAILURE;
        }
    }

    /**
     * @return array{mode: string, scope: string|null}|null
     */
    private function parseMode(string $arg): ?array
    {
        if ($arg === 'all') {
            return ['mode' => ConfigGenerator::MODE_ALL, 'scope' => null];
        }

        if ($arg === 'new') {
            return ['mode' => ConfigGenerator::MODE_NEW, 'scope' => null];
        }

        if (strpos($arg, 'schema=') === 0) {
            $scope = substr($arg, 7);
            if ($scope === '' || $scope === false) {
                return null;
            }
            return ['mode' => ConfigGenerator::MODE_SCHEMA, 'scope' => $scope];
        }

        if (strpos($arg, 'table=') === 0) {
            $scope = substr($arg, 6);
            if ($scope === '' || $scope === false || strpos($scope, '.') === false) {
                return null;
            }
            return ['mode' => ConfigGenerator::MODE_TABLE, 'scope' => $scope];
        }

        return null;
    }

    private function printModeUsage(): void
    {
        $this->line('');
        $this->line('Доступные режимы:');
        $this->line('  <info>all</info>              Полная регенерация конфигурации');
        $this->line('  <info>schema=</info><comment>name</comment>     Перегенерация одной схемы, мёрж в существующий конфиг');
        $this->line('  <info>table=</info><comment>schema.table</comment>  Перегенерация одной таблицы, мёрж в существующий конфиг');
        $this->line('  <info>new</info>              Обнаружение и дописывание новых таблиц');
        $this->line('');
        $this->line('Примеры:');
        $this->line('  php artisan dbdump:prepare-config <info>all</info>');
        $this->line('  php artisan dbdump:prepare-config <info>schema=billing</info>');
        $this->line('  php artisan dbdump:prepare-config <info>table=public.users</info>');
        $this->line('  php artisan dbdump:prepare-config <info>new</info>');
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
