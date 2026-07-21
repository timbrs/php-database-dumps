<?php

namespace Timbrs\DatabaseDumps\Service\Ai;

use Timbrs\DatabaseDumps\Contract\HttpTransportInterface;

/**
 * Реализация HTTP-транспорта через ext-curl.
 *
 * Без новых composer-зависимостей (не тянем guzzle в хост-приложение).
 */
class CurlHttpTransport implements HttpTransportInterface
{
    /**
     * {@inheritdoc}
     */
    public function post(string $url, array $headers, string $body, int $timeout, bool $verifySsl = true): array
    {
        if (!function_exists('curl_init')) {
            throw new \RuntimeException('Расширение ext-curl недоступно — LLM-запросы невозможны.');
        }

        $ch = curl_init($url);
        if ($ch === false) {
            throw new \RuntimeException('Не удалось инициализировать curl для URL: ' . $url);
        }

        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, min($timeout, 30));
        // Безопасность: по умолчанию проверяем TLS-сертификат. Проверку можно отключить
        // ($verifySsl=false) осознанным opt-in'ом — для внутренних эндпоинтов с корпоративным
        // CA, которого нет в доверенном хранилище PHP-curl (см. AiConfig::getVerifySsl).
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $verifySsl);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $verifySsl ? 2 : 0);

        $response = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno !== 0 || $response === false) {
            throw new \RuntimeException(sprintf('Ошибка curl (%d): %s', $errno, $error));
        }

        return ['status' => $status, 'body' => (string) $response];
    }
}
