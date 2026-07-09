<?php

namespace Timbrs\DatabaseDumps\Bridge\Symfony\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Timbrs\DatabaseDumps\Bridge\Symfony\ConsoleLogger;
use Timbrs\DatabaseDumps\Config\AiConfig;
use Timbrs\DatabaseDumps\Contract\HttpTransportInterface;
use Timbrs\DatabaseDumps\Contract\LoggerInterface;
use Timbrs\DatabaseDumps\Service\Ai\AiClientFactory;
use Timbrs\DatabaseDumps\Service\Ai\DbdumpConfigStore;
use Timbrs\DatabaseDumps\Service\ConfigGenerator\ConfigGenerator;
use Timbrs\DatabaseDumps\Service\ConfigGenerator\ModeParser;
use Timbrs\DatabaseDumps\Util\EnvFileWriter;

class PrepareConfigCommand extends Command
{
    /** @var ConfigGenerator */
    private $generator;

    /** @var ModeParser */
    private $modeParser;

    /** @var string */
    private $configPath;

    /** @var DbdumpConfigStore */
    private $aiConfigStore;

    /** @var HttpTransportInterface */
    private $transport;

    /** @var string */
    private $projectDir;

    /** @var LoggerInterface */
    private $logger;

    /** @var EnvFileWriter */
    private $envWriter;

    public function __construct(
        ConfigGenerator $generator,
        ModeParser $modeParser,
        string $configPath,
        DbdumpConfigStore $aiConfigStore,
        HttpTransportInterface $transport,
        string $projectDir,
        LoggerInterface $logger,
        EnvFileWriter $envWriter
    ) {
        $this->generator = $generator;
        $this->modeParser = $modeParser;
        $this->configPath = $configPath;
        $this->aiConfigStore = $aiConfigStore;
        $this->transport = $transport;
        $this->projectDir = rtrim($projectDir, '/\\');
        $this->logger = $logger;
        $this->envWriter = $envWriter;
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
        // Роутим пошаговый прогресс ConfigGenerator в консоль — иначе длинная
        // инспекция таблиц / LLM-анализ выглядят как «зависание».
        if ($this->logger instanceof ConsoleLogger) {
            $this->logger->setIo($io);
        }
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

            // Первый запуск без настроек LLM — предложить настроить (основной сценарий).
            $this->ensureLlmConfigured($io, $input);

            // AI: --no-ai отключает; --ai/--deep включают; иначе авто (если LLM сконфигурирован).
            if ($input->getOption('no-ai')) {
                $this->generator->setAiEnabled(false);
                $aiActive = false;
            } elseif ($input->getOption('ai') || $deep) {
                $this->generator->setAiEnabled(true);
                $aiActive = true;
            } else {
                $aiActive = $this->generator->isLlmAvailable();
                $this->generator->setAiEnabled($aiActive);
            }

            $this->generator->setMode($parsed['mode'], $parsed['scope']);

            $io->section('Анализ структуры БД');
            if ($aiActive) {
                $io->text('LLM-детекция ПД включена: анализ идёт по таблицам, каждая — запрос к LLM.');
                $io->text('На больших схемах это может занять минуты. Прогресс — ниже (строки [N/Всего]).');
            } else {
                $io->text('Детекция ПД — regex-эвристики. Прогресс — ниже (строки [N/Всего]).');
            }
            $io->newLine();

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

    /**
     * Первый запуск: если LLM ещё не настроен (нет ни env, ни сохранённого файла) —
     * предложить задать API URL, модель и token. Несекретное сохраняется в
     * config/database-dumps.php, токен — в .env.local; применяется немедленно.
     * LLM — основной сценарий.
     */
    private function ensureLlmConfigured(SymfonyStyle $io, InputInterface $input): void
    {
        // Пользователь явно отказался от ИИ или среда неинтерактивна — не спрашиваем.
        if ($input->getOption('no-ai') || !$input->isInteractive()) {
            return;
        }
        // Уже настроено (env-переменные или сохранённый файл) — ничего не спрашиваем.
        if ($this->aiConfigStore->resolve($this->projectDir)->getUrl() !== ''
            || $this->aiConfigStore->exists($this->projectDir)
        ) {
            return;
        }

        $io->section('Настройка LLM — основной сценарий анализа');
        $io->text([
            'LLM уточняет анализ: PII-классификация (точнее regex), профилирование,',
            'подсказки по выборке. Настройки сохранятся в '
                . $this->aiConfigStore->path($this->projectDir) . '.',
        ]);

        if (!$io->confirm('Настроить LLM сейчас?', true)) {
            // Запоминаем отказ, чтобы не спрашивать при каждом запуске.
            $this->aiConfigStore->save($this->projectDir, AiConfig::fromArray(['url' => '', 'enabled' => false]));
            $io->note('Пропущено. Анализ пойдёт на regex-эвристиках. Позже: app:dbdump:configure-llm');
            return;
        }

        $url = $io->ask(
            'API URL (base, например https://gpt.example.com/v1)',
            null,
            function ($value) {
                $value = is_string($value) ? trim($value) : '';
                if (!self::isValidUrl($value)) {
                    throw new \RuntimeException('Нужен корректный http(s) URL с хостом.');
                }
                return $value;
            }
        );

        $model = (string) $io->ask('Модель', AiConfig::DEFAULT_MODEL);

        // Ввод видимый (не askHidden), чтобы было видно вставляемый токен.
        $tokenInput = $io->ask('Token (Enter — без токена; ввод виден)');
        $token = ($tokenInput === null || $tokenInput === '') ? null : $tokenInput;

        $config = AiConfig::fromArray([
            'url' => $url,
            'model' => $model,
            'token' => $token,
            'enabled' => true,
        ]);
        $this->aiConfigStore->save($this->projectDir, $config);
        if ($token !== null) {
            $envPath = $this->envWriter->setVar($this->projectDir, AiConfig::ENV_TOKEN, $token);
            $io->note('Токен записан в ' . $envPath . '. Настройки — в ' . $this->aiConfigStore->path($this->projectDir) . '.');
        }
        $io->success('Настройки LLM сохранены. ИИ-детекция включена для этого запуска.');

        // Применяем немедленно: подменяем клиент в уже построенном детекторе.
        // Логгер передаём, чтобы запросы/ретраи нового клиента были видны в консоли.
        $this->generator->refreshLlmClient(AiClientFactory::create($this->transport, $config, $this->logger));
    }

    private static function isValidUrl(string $url): bool
    {
        if ($url === '') {
            return false;
        }
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            return false;
        }
        $scheme = strtolower($parts['scheme']);
        return ($scheme === 'http' || $scheme === 'https') && $parts['host'] !== '';
    }
}
