<?php

namespace Timbrs\DatabaseDumps\Contract;

/**
 * Абстракция HTTP-транспорта для LLM-клиента.
 *
 * Вынесена в интерфейс, чтобы юнит-тесты не ходили в сеть: реальная
 * реализация — CurlHttpTransport (ext-curl), в тестах подменяется моком.
 */
interface HttpTransportInterface
{
    /**
     * Выполнить POST-запрос.
     *
     * @param string $url Полный URL endpoint'а
     * @param array<int, string> $headers Заголовки в формате "Name: value" (как CURLOPT_HTTPHEADER)
     * @param string $body Тело запроса (обычно JSON)
     * @param int $timeout Таймаут в секундах
     * @param bool $verifySsl Проверять TLS-сертификат сервера (default true). false — осознанный
     *                        opt-in для внутренних эндпоинтов с корпоративным CA (понижает безопасность).
     * @return array{status: int, body: string}
     *
     * @throws \RuntimeException при ошибке транспортного уровня (нет соединения, таймаут и т.п.)
     */
    public function post(string $url, array $headers, string $body, int $timeout, bool $verifySsl = true): array;
}
