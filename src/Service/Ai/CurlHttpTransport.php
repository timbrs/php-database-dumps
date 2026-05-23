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
    public function post(string $url, array $headers, string $body, int $timeout): array
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
        // Безопасность: проверяем TLS-сертификат (по умолчанию curl и так это делает).
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

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
