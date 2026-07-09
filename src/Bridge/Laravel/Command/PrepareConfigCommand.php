<?php

namespace Timbrs\DatabaseDumps\Bridge\Laravel\Command;

use Illuminate\Console\Command;
use Timbrs\DatabaseDumps\Bridge\Laravel\LaravelLogger;
use Timbrs\DatabaseDumps\Config\AiConfig;
use Timbrs\DatabaseDumps\Contract\HttpTransportInterface;
use Timbrs\DatabaseDumps\Contract\LoggerInterface;
use Timbrs\DatabaseDumps\Service\Ai\AiClientFactory;
use Timbrs\DatabaseDumps\Service\Ai\AiConfigStore;
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

    /** @var AiConfigStore */
    private $aiConfigStore;

    /** @var HttpTransportInterface */
    private $transport;

    /** @var string */
    private $projectDir;

    public function __construct(
        ConfigGenerator $generator,
        ModeParser $modeParser,
        LoggerInterface $logger,
        string $configPath,
        AiConfigStore $aiConfigStore,
        HttpTransportInterface $transport,
        string $projectDir
    ) {
        parent::__construct();
        $this->generator = $generator;
        $this->modeParser = $modeParser;
        $this->logger = $logger;
        $this->configPath = $configPath;
        $this->aiConfigStore = $aiConfigStore;
        $this->transport = $transport;
        $this->projectDir = rtrim($projectDir, '/\\');
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

            // Первый запуск без настроек LLM — предложить настроить (основной сценарий).
            $this->ensureLlmConfigured();

            // AI: --no-ai отключает; --ai/--deep включают; иначе авто (если LLM сконфигурирован).
            if ($this->option('no-ai')) {
                $this->generator->setAiEnabled(false);
                $aiActive = false;
            } elseif ($this->option('ai') || $deep) {
                $this->generator->setAiEnabled(true);
                $aiActive = true;
            } else {
                $aiActive = $this->generator->isLlmAvailable();
                $this->generator->setAiEnabled($aiActive);
            }

            $this->line("Режим: {$modeArg}");
            $this->line("Порог строк: {$threshold}");
            $this->line("Путь: {$this->configPath}");
            $this->line('');
            if ($aiActive) {
                $this->line('LLM-детекция ПД включена: анализ идёт по таблицам, каждая — запрос к LLM.');
                $this->line('На больших схемах это может занять минуты. Прогресс — ниже (строки [N/Всего]).');
            } else {
                $this->line('Детекция ПД — regex-эвристики. Прогресс — ниже (строки [N/Всего]).');
            }
            $this->line('');

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

    /**
     * Первый запуск: если LLM ещё не настроен (нет ни env, ни сохранённого файла) —
     * предложить задать API URL, модель и token. Ответ сохраняется в
     * database/dbdump_llm.json и применяется немедленно. LLM — основной сценарий.
     */
    private function ensureLlmConfigured(): void
    {
        // Пользователь явно отказался от ИИ или среда неинтерактивна — не спрашиваем.
        if ($this->option('no-ai') || ($this->input !== null && !$this->input->isInteractive())) {
            return;
        }
        // Уже настроено (env-переменные или сохранённый файл) — ничего не спрашиваем.
        if ($this->aiConfigStore->resolve($this->projectDir)->getUrl() !== ''
            || $this->aiConfigStore->exists($this->projectDir)
        ) {
            return;
        }

        $this->line('');
        $this->info('Настройка LLM — основной сценарий анализа');
        $this->line('LLM уточняет анализ: PII-классификация (точнее regex), профилирование, подсказки по выборке.');
        $this->line('Настройки сохранятся в ' . $this->aiConfigStore->path($this->projectDir) . '.');

        if (!$this->confirm('Настроить LLM сейчас?', true)) {
            // Запоминаем отказ, чтобы не спрашивать при каждом запуске.
            $this->aiConfigStore->save($this->projectDir, AiConfig::fromArray(['url' => '', 'enabled' => false]));
            $this->warn('Пропущено. Анализ пойдёт на regex-эвристиках. Позже: dbdump:configure-llm');
            return;
        }

        $url = null;
        while ($url === null) {
            $answer = trim((string) $this->ask('API URL (base, например https://gpt.example.com/v1)'));
            if (self::isValidUrl($answer)) {
                $url = $answer;
            } else {
                $this->error('Нужен корректный http(s) URL с хостом.');
            }
        }

        $model = (string) $this->ask('Модель', AiConfig::DEFAULT_MODEL);

        // Ввод видимый (не secret), чтобы было видно вставляемый токен.
        $tokenInput = $this->ask('Token (Enter — без токена; ввод виден)');
        $token = ($tokenInput === null || $tokenInput === '') ? null : $tokenInput;

        $config = AiConfig::fromArray([
            'url' => $url,
            'model' => $model,
            'token' => $token,
            'enabled' => true,
        ]);
        $this->aiConfigStore->save($this->projectDir, $config);
        $this->info('Настройки LLM сохранены.');
        $this->warn('Файл может содержать token — добавьте его в .gitignore. ИИ-детекция включена для этого запуска.');

        // Применяем немедленно: подменяем клиент в уже построенном детекторе.
        $this->generator->refreshLlmClient(AiClientFactory::create($this->transport, $config));
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
