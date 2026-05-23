<?php

namespace Timbrs\DatabaseDumps\Contract;

/**
 * Клиент прямого обращения к LLM (OpenAI-совместимый API).
 *
 * Используется для анализа ДАННЫХ (PII-классификация, категориальность,
 * рекомендации по выборке) — запросы ограничены по размеру. Анализ КОДА
 * хост-приложения выполняется отдельной веткой (OPENCODE), не через этот клиент.
 *
 * Когда LLM не сконфигурирован, в DI подставляется NullAiClient
 * (isAvailable() = false), и фичи мягко деградируют на regex-эвристики.
 */
interface AiClientInterface
{
    /**
     * Доступен ли LLM (сконфигурирован URL и фича включена).
     */
    public function isAvailable(): bool;

    /**
     * Отправить chat-запрос и вернуть текстовый ответ модели.
     *
     * @param array<int, array{role: string, content: string}> $messages
     * @param float $temperature
     *
     * @throws \RuntimeException при недоступности LLM или ошибке после ретраев
     */
    public function chat(array $messages, float $temperature = 0.1): string;

    /**
     * Отправить chat-запрос и распарсить JSON-ответ модели в массив.
     *
     * Снимает markdown-обёртку (```json ... ```), извлекает JSON-объект/массив.
     *
     * @param array<int, array{role: string, content: string}> $messages
     * @param float $temperature
     * @return array<mixed>
     *
     * @throws \RuntimeException при недоступности LLM, ошибке после ретраев или невалидном JSON
     */
    public function chatJson(array $messages, float $temperature = 0.1): array;
}
