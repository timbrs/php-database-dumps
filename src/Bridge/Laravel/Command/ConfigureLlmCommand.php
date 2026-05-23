<?php

namespace Timbrs\DatabaseDumps\Bridge\Laravel\Command;

use Illuminate\Console\Command;
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
    /** @var string */
    protected $signature = 'dbdump:configure-llm';

    /** @var string */
    protected $description = 'Настроить LLM для анализа (URL, модель, token) и сохранить';

    /** @var AiConfigStore */
    private $store;

    /** @var HttpTransportInterface */
    private $transport;

    /** @var string */
    private $projectDir;

    public function __construct(AiConfigStore $store, HttpTransportInterface $transport, string $projectDir)
    {
        parent::__construct();
        $this->store = $store;
        $this->transport = $transport;
        $this->projectDir = rtrim($projectDir, '/\\');
    }

    public function handle(): int
    {
        $this->info('Настройка LLM для анализа данных');

        $current = $this->store->resolve($this->projectDir);

        if (!$this->confirm('Использовать LLM для анализа (PII, профилирование, подсказки)?', $current->isEnabled())) {
            $this->store->save($this->projectDir, AiConfig::fromArray(['url' => '', 'enabled' => false]));
            $this->info('LLM отключён. Анализ будет работать на regex-эвристиках.');
            $this->line('Файл настроек: ' . $this->store->path($this->projectDir));
            return self::SUCCESS;
        }

        $url = null;
        while ($url === null) {
            $answer = (string) $this->ask('Адрес LLM (base URL, например https://gpt.example.com/v1)', $current->getUrl() ?: null);
            $answer = trim($answer);
            if (self::isValidUrl($answer)) {
                $url = $answer;
            } else {
                $this->error('Нужен корректный http(s) URL с хостом.');
            }
        }

        $model = (string) $this->ask('Модель', $current->getModel());

        $hasToken = $current->getToken() !== null;
        $tokenInput = $this->secret('Token' . ($hasToken ? ' (Enter — оставить текущий)' : ' (Enter — без токена)'));
        if ($tokenInput === null || $tokenInput === '') {
            $token = $current->getToken();
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

        if ($this->confirm('Проверить соединение с LLM сейчас?', true)) {
            $result = (new OpenAiClient($this->transport, $config))->ping();
            if ($result['ok']) {
                $this->info('Соединение с LLM успешно.');
            } else {
                $this->warn('Не удалось соединиться с LLM: ' . ($result['error'] ?? 'неизвестная ошибка'));
                if (!$this->confirm('Сохранить настройки всё равно?', true)) {
                    $this->line('Отменено, ничего не сохранено.');
                    return self::SUCCESS;
                }
            }
        }

        $this->store->save($this->projectDir, $config);
        $this->info('Настройки LLM сохранены.');
        $this->line('Файл: ' . $this->store->path($this->projectDir));
        $this->warn('Файл может содержать token — добавьте его в .gitignore.');
        $this->line('Переменные окружения DBDUMP_LLM_* (если заданы) перекрывают этот файл.');

        return self::SUCCESS;
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
