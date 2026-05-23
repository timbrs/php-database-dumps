<?php

namespace Timbrs\DatabaseDumps\Bridge\Symfony\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Timbrs\DatabaseDumps\Config\AiConfig;
use Timbrs\DatabaseDumps\Contract\HttpTransportInterface;
use Timbrs\DatabaseDumps\Service\Ai\AiConfigStore;
use Timbrs\DatabaseDumps\Service\Ai\OpenAiClient;

/**
 * Интерактивная настройка LLM: спрашивает наличие LLM, URL, модель и token,
 * сохраняет в database/dbdump_llm.json (AiConfigStore).
 */
class ConfigureLlmCommand extends Command
{
    /** @var AiConfigStore */
    private $store;

    /** @var HttpTransportInterface */
    private $transport;

    /** @var string */
    private $projectDir;

    public function __construct(AiConfigStore $store, HttpTransportInterface $transport, string $projectDir)
    {
        $this->store = $store;
        $this->transport = $transport;
        $this->projectDir = rtrim($projectDir, '/\\');
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('app:dbdump:configure-llm')
            ->setDescription('Настроить LLM для анализа (URL, модель, token) и сохранить');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Настройка LLM для анализа данных');

        $current = $this->store->resolve($this->projectDir);

        if (!$io->confirm('Использовать LLM для анализа (PII, профилирование, подсказки)?', $current->isEnabled())) {
            $this->store->save($this->projectDir, AiConfig::fromArray(['url' => '', 'enabled' => false]));
            $io->success('LLM отключён. Анализ будет работать на regex-эвристиках.');
            $io->text('Файл настроек: ' . $this->store->path($this->projectDir));
            return Command::SUCCESS;
        }

        $url = $io->ask(
            'Адрес LLM (base URL, например https://gpt.example.com/v1)',
            $current->getUrl() !== '' ? $current->getUrl() : null,
            function ($value) {
                $value = is_string($value) ? trim($value) : '';
                if (!self::isValidUrl($value)) {
                    throw new \RuntimeException('Нужен корректный http(s) URL с хостом.');
                }
                return $value;
            }
        );

        $model = $io->ask('Модель', $current->getModel());

        $hasToken = $current->getToken() !== null;
        $tokenInput = $io->askHidden(
            'Token' . ($hasToken ? ' (Enter — оставить текущий)' : ' (Enter — без токена)')
        );
        if ($tokenInput === null || $tokenInput === '') {
            $token = $current->getToken(); // пусто = оставить текущий (или null)
        } else {
            $token = $tokenInput;
        }

        $config = AiConfig::fromArray([
            'url' => $url,
            'model' => $model,
            'token' => $token,
            'timeout' => $current->getTimeout(),
            'enabled' => true,
        ]);

        if ($io->confirm('Проверить соединение с LLM сейчас?', true)) {
            $result = (new OpenAiClient($this->transport, $config))->ping();
            if ($result['ok']) {
                $io->success('Соединение с LLM успешно.');
            } else {
                $io->warning('Не удалось соединиться с LLM: ' . ($result['error'] ?? 'неизвестная ошибка'));
                if (!$io->confirm('Сохранить настройки всё равно?', true)) {
                    $io->note('Отменено, ничего не сохранено.');
                    return Command::SUCCESS;
                }
            }
        }

        $this->store->save($this->projectDir, $config);
        $io->success('Настройки LLM сохранены.');
        $io->text('Файл: ' . $this->store->path($this->projectDir));
        $io->note([
            'Файл может содержать token — добавьте его в .gitignore.',
            'Переменные окружения DBDUMP_LLM_* (если заданы) перекрывают этот файл.',
        ]);

        return Command::SUCCESS;
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
