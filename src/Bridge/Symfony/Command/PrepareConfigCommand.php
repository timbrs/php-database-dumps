<?php

namespace Timbrs\DatabaseDumps\Bridge\Symfony\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Timbrs\DatabaseDumps\Service\ConfigGenerator\ConfigGenerator;
use Timbrs\DatabaseDumps\Service\ConfigGenerator\ModeParser;

class PrepareConfigCommand extends Command
{
    /** @var ConfigGenerator */
    private $generator;

    /** @var ModeParser */
    private $modeParser;

    /** @var string */
    private $configPath;

    public function __construct(ConfigGenerator $generator, ModeParser $modeParser, string $configPath)
    {
        $this->generator = $generator;
        $this->modeParser = $modeParser;
        $this->configPath = $configPath;
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('app:dbdump:prepare-config')
            ->setDescription('Автоматическая генерация dump_config.yaml на основе структуры БД')
            ->addArgument('mode', InputArgument::OPTIONAL, 'Режим: all, schema=<name>, table=<schema.table>, new')
            ->addOption('threshold', 't', InputOption::VALUE_REQUIRED, 'Порог строк для partial_export', '500')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Перезаписать без подтверждения')
            ->addOption('no-cascade', null, InputOption::VALUE_NONE, 'Пропустить обнаружение FK и cascade_from')
            ->addOption('no-faker', null, InputOption::VALUE_NONE, 'Пропустить обнаружение ПД')
            ->addOption('no-split', null, InputOption::VALUE_NONE, 'Единый YAML без разделения по схемам')
            ->addOption('deep', null, InputOption::VALUE_NONE, 'Глубокий анализ: профилирование + ИИ + отчёт (REPORT.md)')
            ->addOption('criteria', null, InputOption::VALUE_NONE, 'Авто-генерация sample.criteria из категориальных колонок')
            ->addOption('ai', null, InputOption::VALUE_NONE, 'Включить LLM-детекцию ПД')
            ->addOption('no-ai', null, InputOption::VALUE_NONE, 'Отключить LLM-детекцию ПД');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Генерация dump_config.yaml');

        $modeArg = $input->getArgument('mode');
        if ($modeArg === null || $modeArg === '') {
            $io->error('Не указан режим работы.');
            $io->text($this->modeParser->getUsageLines());
            return Command::FAILURE;
        }

        $parsed = $this->modeParser->parse($modeArg);
        if ($parsed === null) {
            $io->error("Неизвестный режим: {$modeArg}");
            $io->text($this->modeParser->getUsageLines());
            return Command::FAILURE;
        }

        $outputPath = $this->configPath;
        $threshold = (int) $input->getOption('threshold');
        if ($threshold <= 0) {
            $io->error('Порог должен быть положительным числом');
            return Command::FAILURE;
        }

        if ($parsed['mode'] === ConfigGenerator::MODE_ALL) {
            if (!$input->getOption('force') && file_exists($outputPath)) {
                if (!$io->confirm("Файл {$outputPath} уже существует. Перезаписать?", false)) {
                    $io->warning('Отменено');
                    return Command::SUCCESS;
                }
            }
        }

        try {
            $io->text("Режим: {$modeArg}");
            $io->text("Порог строк: {$threshold}");
            $io->text("Путь: {$outputPath}");
            $io->newLine();

            if ($input->getOption('no-cascade')) {
                $this->generator->setCascadeEnabled(false);
            }
            if ($input->getOption('no-faker')) {
                $this->generator->setFakerEnabled(false);
            }
            if ($input->getOption('no-split')) {
                $this->generator->setSplitBySchema(false);
            }

            $deep = (bool) $input->getOption('deep');
            if ($deep) {
                $this->generator->setDeepEnabled(true);
                $this->generator->setCriteriaEnabled(true);
            }
            if ($input->getOption('criteria')) {
                $this->generator->setCriteriaEnabled(true);
            }

            // AI: --no-ai отключает; --ai/--deep включают; иначе авто (если LLM сконфигурирован).
            if ($input->getOption('no-ai')) {
                $this->generator->setAiEnabled(false);
            } elseif ($input->getOption('ai') || $deep) {
                $this->generator->setAiEnabled(true);
            } else {
                $this->generator->setAiEnabled($this->generator->isLlmAvailable());
            }

            $this->generator->setMode($parsed['mode'], $parsed['scope']);
            $stats = $this->generator->generate($outputPath, $threshold);

            $io->success(sprintf(
                'Конфигурация сгенерирована: full=%d, partial=%d, пропущено=%d, пустых=%d',
                $stats['full'],
                $stats['partial'],
                $stats['skipped'],
                $stats['empty']
            ));
            return Command::SUCCESS;
        } catch (\Exception $e) {
            $io->error('Ошибка генерации: ' . $e->getMessage());
            if ($io->isVerbose()) {
                $io->note('Трейс: ' . $e->getTraceAsString());
            }
            return Command::FAILURE;
        }
    }
}
