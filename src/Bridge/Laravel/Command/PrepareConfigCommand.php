<?php

namespace Timbrs\DatabaseDumps\Bridge\Laravel\Command;

use Illuminate\Console\Command;
use Timbrs\DatabaseDumps\Bridge\Laravel\LaravelLogger;
use Timbrs\DatabaseDumps\Contract\LoggerInterface;
use Timbrs\DatabaseDumps\Service\ConfigGenerator\ConfigGenerator;
use Timbrs\DatabaseDumps\Service\ConfigGenerator\ModeParser;

class PrepareConfigCommand extends Command
{
    /** @var string */
    protected $signature = 'dbdump:prepare-config'
        . ' {mode? : Режим: all, schema=<name>, table=<schema.table>, new}'
        . ' {--t|threshold=500 : Порог строк для partial_export}'
        . ' {--f|force : Перезаписать без подтверждения}'
        . ' {--no-cascade : Пропустить обнаружение FK}'
        . ' {--no-faker : Пропустить обнаружение ПД}'
        . ' {--no-split : Единый YAML}'
        . ' {--deep : Глубокий анализ: профилирование + ИИ + отчёт}'
        . ' {--criteria : Авто-генерация sample.criteria из категориальных колонок}'
        . ' {--ai : Включить LLM-детекцию ПД}'
        . ' {--no-ai : Отключить LLM-детекцию ПД}';

    /** @var string */
    protected $description = 'Автоматическая генерация dump_config.yaml на основе структуры БД';

    /** @var ConfigGenerator */
    private $generator;

    /** @var ModeParser */
    private $modeParser;

    /** @var LoggerInterface */
    private $logger;

    /** @var string */
    private $configPath;

    public function __construct(
        ConfigGenerator $generator,
        ModeParser $modeParser,
        LoggerInterface $logger,
        string $configPath
    ) {
        parent::__construct();
        $this->generator = $generator;
        $this->modeParser = $modeParser;
        $this->logger = $logger;
        $this->configPath = $configPath;
    }

    public function handle(): int
    {
        $this->setupLogger();
        $this->info('Генерация dump_config.yaml');

        $modeArg = $this->argument('mode');
        if ($modeArg === null || $modeArg === '') {
            $this->error('Не указан режим работы.');
            $this->printUsage();
            return self::FAILURE;
        }

        $parsed = $this->modeParser->parse($modeArg);
        if ($parsed === null) {
            $this->error("Неизвестный режим: {$modeArg}");
            $this->printUsage();
            return self::FAILURE;
        }

        $threshold = (int) $this->option('threshold');
        if ($threshold <= 0) {
            $this->error('Порог должен быть положительным числом');
            return self::FAILURE;
        }

        if ($parsed['mode'] === ConfigGenerator::MODE_ALL) {
            if (!$this->option('force') && file_exists($this->configPath)) {
                if (!$this->confirm("Файл {$this->configPath} уже существует. Перезаписать?", false)) {
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

            $deep = (bool) $this->option('deep');
            if ($deep) {
                $this->generator->setDeepEnabled(true);
                $this->generator->setCriteriaEnabled(true);
            }
            if ($this->option('criteria')) {
                $this->generator->setCriteriaEnabled(true);
            }

            // AI: --no-ai отключает; --ai/--deep включают; иначе авто (если LLM сконфигурирован).
            if ($this->option('no-ai')) {
                $this->generator->setAiEnabled(false);
            } elseif ($this->option('ai') || $deep) {
                $this->generator->setAiEnabled(true);
            } else {
                $this->generator->setAiEnabled($this->generator->isLlmAvailable());
            }

            $this->line("Режим: {$modeArg}");
            $this->line("Порог строк: {$threshold}");
            $this->line("Путь: {$this->configPath}");

            $this->generator->setMode($parsed['mode'], $parsed['scope']);
            $stats = $this->generator->generate($this->configPath, $threshold);

            $this->info(sprintf(
                'Конфигурация сгенерирована: full=%d, partial=%d, пропущено=%d, пустых=%d',
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

    private function printUsage(): void
    {
        foreach ($this->modeParser->getUsageLines() as $line) {
            $this->line($line);
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
