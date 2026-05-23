<?php

namespace Timbrs\DatabaseDumps\Service\Ai;

use Timbrs\DatabaseDumps\Config\AiConfig;
use Timbrs\DatabaseDumps\Contract\AiClientInterface;
use Timbrs\DatabaseDumps\Contract\HttpTransportInterface;
use Timbrs\DatabaseDumps\Contract\LoggerInterface;

/**
 * Фабрика LLM-клиента.
 *
 * Возвращает OpenAiClient, если LLM сконфигурирован (DBDUMP_LLM_URL задан),
 * иначе NullAiClient — фичи мягко деградируют на regex-эвристики.
 */
class AiClientFactory
{
    public static function create(
        HttpTransportInterface $transport,
        AiConfig $config,
        LoggerInterface $logger = null
    ): AiClientInterface {
        if ($config->isEnabled()) {
            return new OpenAiClient($transport, $config, $logger);
        }
        return new NullAiClient();
    }
}
