<?php

namespace Timbrs\DatabaseDumps\Service\Ai;

use Timbrs\DatabaseDumps\Contract\AiClientInterface;

/**
 * No-op LLM-клиент: используется, когда LLM не сконфигурирован.
 *
 * isAvailable() = false → фичи (LLM-PII, AI-обогащение) мягко деградируют
 * на regex-эвристики. chat()/chatJson() бросают понятную ошибку, если их
 * всё-таки вызвали без проверки isAvailable().
 */
class NullAiClient implements AiClientInterface
{
    public function isAvailable(): bool
    {
        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function chat(array $messages, float $temperature = 0.1): string
    {
        throw new \RuntimeException(
            'LLM не сконфигурирован: задайте DBDUMP_LLM_URL для использования AI-функций.'
        );
    }

    /**
     * {@inheritdoc}
     */
    public function chatJson(array $messages, float $temperature = 0.1): array
    {
        throw new \RuntimeException(
            'LLM не сконфигурирован: задайте DBDUMP_LLM_URL для использования AI-функций.'
        );
    }
}
