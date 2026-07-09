<?php

namespace Timbrs\DatabaseDumps\Service\Ai;

use Timbrs\DatabaseDumps\Config\AiConfig;
use Timbrs\DatabaseDumps\Contract\AiClientInterface;
use Timbrs\DatabaseDumps\Contract\HttpTransportInterface;
use Timbrs\DatabaseDumps\Contract\LoggerInterface;

/**
 * Клиент OpenAI-совместимого API (порт логики из go-defaker/internal/detector/llm.go).
 *
 * POST на {baseUrl}/chat/completions, заголовки Authorization: Bearer + Content-Type.
 * Особенности (как в go-defaker):
 * - 3 попытки с экспоненциальным backoff (5s, 15s, 45s) — даёт серверу время
 *   подгрузить модель после холодного старта.
 * - extractJson() снимает markdown-обёртку (```json ... ```).
 * - Клампинг длины ответа перед regex (защита от ReDoS).
 * - Валидация base URL (схема http/https, непустой host) — защита от SSRF.
 */
class OpenAiClient implements AiClientInterface
{
    private const MAX_ATTEMPTS = 3;

    /** Верхняя граница длины ответа LLM перед regex-обработкой (1 МиБ). */
    private const MAX_RESPONSE_SIZE = 1048576;

    /** @var HttpTransportInterface */
    private $transport;

    /** @var AiConfig */
    private $config;

    /** @var LoggerInterface|null */
    private $logger;

    /**
     * @throws \InvalidArgumentException если base URL невалиден (и фича включена)
     */
    public function __construct(HttpTransportInterface $transport, AiConfig $config, LoggerInterface $logger = null)
    {
        $this->transport = $transport;
        $this->config = $config;
        $this->logger = $logger;

        if ($config->isEnabled()) {
            $this->validateBaseUrl($config->getUrl());
        }
    }

    public function isAvailable(): bool
    {
        return $this->config->isEnabled();
    }

    /**
     * {@inheritdoc}
     */
    public function chat(array $messages, float $temperature = 0.1): string
    {
        if (!$this->isAvailable()) {
            throw new \RuntimeException('LLM не сконфигурирован (DBDUMP_LLM_URL не задан).');
        }

        $url = $this->config->getUrl() . '/chat/completions';
        $payload = json_encode([
            'model' => $this->config->getModel(),
            'messages' => $messages,
            'temperature' => $temperature,
        ]);
        if ($payload === false) {
            throw new \RuntimeException('Не удалось сериализовать запрос к LLM в JSON.');
        }

        $headers = ['Content-Type: application/json'];
        $token = $this->config->getToken();
        if ($token !== null) {
            $headers[] = 'Authorization: Bearer ' . $token;
        }

        $lastError = 'неизвестная ошибка';
        for ($attempt = 0; $attempt < self::MAX_ATTEMPTS; $attempt++) {
            if ($attempt > 0) {
                // 5s, 15s, 45s
                $wait = 5 * (int) pow(3, $attempt - 1);
                $this->info(sprintf('Пауза %d сек перед повтором LLM-запроса…', $wait));
                $this->doSleep($wait);
            }

            $this->info(sprintf(
                'LLM-запрос (попытка %d/%d, модель %s, таймаут %d сек)…',
                $attempt + 1,
                self::MAX_ATTEMPTS,
                $this->config->getModel(),
                $this->config->getTimeout()
            ));

            try {
                $result = $this->transport->post($url, $headers, $payload, $this->config->getTimeout());
            } catch (\RuntimeException $e) {
                $lastError = $e->getMessage();
                $this->warn(sprintf('LLM попытка %d: %s', $attempt + 1, $lastError));
                continue;
            }

            if ($result['status'] < 200 || $result['status'] >= 300) {
                $lastError = sprintf('HTTP %d: %s', $result['status'], $this->truncate($result['body'], 500));
                $this->warn(sprintf('LLM попытка %d: %s', $attempt + 1, $lastError));
                continue;
            }

            $content = $this->extractContent($result['body']);
            if ($content === null) {
                $lastError = 'пустой/некорректный ответ LLM';
                $this->warn(sprintf('LLM попытка %d: %s', $attempt + 1, $lastError));
                continue;
            }

            return $content;
        }

        throw new \RuntimeException('LLM-запрос не удался после ретраев: ' . $lastError);
    }

    /**
     * Лёгкая preflight-проверка доступности LLM: один запрос, без ретраев/backoff,
     * короткий таймаут. Используется командой настройки для проверки соединения.
     *
     * @return array{ok: bool, error: string|null, reply?: string|null}
     */
    public function ping(): array
    {
        if (!$this->isAvailable()) {
            return ['ok' => false, 'error' => 'LLM не сконфигурирован'];
        }

        $url = $this->config->getUrl() . '/chat/completions';
        $payload = json_encode([
            'model' => $this->config->getModel(),
            'messages' => [['role' => 'user', 'content' => 'ping']],
            'temperature' => 0,
        ]);
        if ($payload === false) {
            return ['ok' => false, 'error' => 'ошибка сериализации запроса'];
        }

        $headers = ['Content-Type: application/json'];
        $token = $this->config->getToken();
        if ($token !== null) {
            $headers[] = 'Authorization: Bearer ' . $token;
        }

        $timeout = min($this->config->getTimeout(), 30);
        try {
            $result = $this->transport->post($url, $headers, $payload, $timeout);
        } catch (\RuntimeException $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }

        if ($result['status'] < 200 || $result['status'] >= 300) {
            return ['ok' => false, 'error' => 'HTTP ' . $result['status'] . ': ' . $this->truncate($result['body'], 300)];
        }

        // Успешный HTTP-статус, но убедимся, что тело — валидный OpenAI-ответ с непустым content.
        $content = $this->extractContent($result['body']);
        if ($content === null) {
            return ['ok' => false, 'error' => 'HTTP ' . $result['status'] . ', но ответ без текста: ' . $this->truncate($result['body'], 300)];
        }

        return ['ok' => true, 'error' => null, 'reply' => $content];
    }

    /**
     * {@inheritdoc}
     */
    public function chatJson(array $messages, float $temperature = 0.1): array
    {
        $content = $this->chat($messages, $temperature);
        $jsonStr = $this->extractJson($content);
        if ($jsonStr === null) {
            throw new \RuntimeException('Не удалось извлечь JSON из ответа LLM.');
        }

        $decoded = json_decode($jsonStr, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('Ответ LLM не является валидным JSON-объектом/массивом.');
        }

        return $decoded;
    }

    /**
     * Извлечь content из тела ответа /chat/completions.
     */
    private function extractContent(string $body): ?string
    {
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            return null;
        }
        $content = $decoded['choices'][0]['message']['content'] ?? null;
        if (!is_string($content) || $content === '') {
            return null;
        }
        return $content;
    }

    /**
     * Извлечь JSON из ответа LLM (снять markdown-обёртку ```json ... ```).
     *
     * Обрезает слишком длинный ответ ДО regex (защита от ReDoS).
     */
    public function extractJson(string $content): ?string
    {
        if (strlen($content) > self::MAX_RESPONSE_SIZE) {
            $content = substr($content, 0, self::MAX_RESPONSE_SIZE);
        }
        $content = trim($content);

        // Вариант 1-2: ```json\n...\n``` или ```\n...\n```
        if (preg_match('/```(?:json)?\s*(.+?)```/s', $content, $matches)) {
            return trim($matches[1]);
        }

        // Вариант 3: чистый JSON-объект или массив
        if ($content !== '' && ($content[0] === '{' || $content[0] === '[')) {
            return $content;
        }

        return null;
    }

    /**
     * Валидация base URL — защита от SSRF (file://, мусорная строка).
     *
     * @throws \InvalidArgumentException
     */
    private function validateBaseUrl(string $url): void
    {
        if ($url === '') {
            throw new \InvalidArgumentException('DBDUMP_LLM_URL пуст.');
        }
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            throw new \InvalidArgumentException('Невалидный DBDUMP_LLM_URL: ' . $url);
        }
        $scheme = strtolower($parts['scheme']);
        if ($scheme !== 'http' && $scheme !== 'https') {
            throw new \InvalidArgumentException(
                'DBDUMP_LLM_URL должен использовать схему http/https, получено: ' . $scheme
            );
        }
        if ($parts['host'] === '') {
            throw new \InvalidArgumentException('DBDUMP_LLM_URL не содержит host: ' . $url);
        }
    }

    /**
     * Задержка между ретраями. Вынесено в метод для переопределения в тестах.
     */
    protected function doSleep(int $seconds): void
    {
        if ($seconds > 0) {
            sleep($seconds);
        }
    }

    private function truncate(string $s, int $n): string
    {
        return strlen($s) <= $n ? $s : substr($s, 0, $n);
    }

    private function warn(string $message): void
    {
        if ($this->logger !== null) {
            $this->logger->warning($message);
        }
    }

    private function info(string $message): void
    {
        if ($this->logger !== null) {
            $this->logger->info($message);
        }
    }
}
