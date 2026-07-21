<?php

namespace Timbrs\DatabaseDumps\Config;

/**
 * Конфигурация прямого LLM-клиента (OpenAI-совместимый API).
 *
 * Читается из окружения по образцу EnvironmentConfig::fromEnv
 * (getenv → $_SERVER → $_ENV):
 *   - DBDUMP_LLM_URL     — базовый URL, например https://llm.example.com/v1
 *   - DBDUMP_LLM_MODEL   — имя модели (default openai/gpt-oss-120b)
 *   - DBDUMP_LLM_TOKEN   — Bearer-токен (опционально)
 *   - DBDUMP_LLM_TIMEOUT — таймаут запроса в секундах (default 120)
 *   - DBDUMP_LLM_ENABLED — true/false; по умолчанию auto (включено, если задан URL)
 *   - DBDUMP_LLM_VERIFY_SSL — проверять TLS-сертификат сервера (default true); false/0/no/off
 *     отключает проверку. Отключайте ТОЛЬКО для внутренних эндпоинтов с корпоративным CA,
 *     которого нет в доверенном хранилище PHP-curl. Понижение безопасности — сознательный opt-in.
 */
class AiConfig
{
    public const ENV_URL = 'DBDUMP_LLM_URL';
    public const ENV_MODEL = 'DBDUMP_LLM_MODEL';
    public const ENV_TOKEN = 'DBDUMP_LLM_TOKEN';
    public const ENV_TIMEOUT = 'DBDUMP_LLM_TIMEOUT';
    public const ENV_ENABLED = 'DBDUMP_LLM_ENABLED';
    public const ENV_VERIFY_SSL = 'DBDUMP_LLM_VERIFY_SSL';

    public const DEFAULT_MODEL = 'openai/gpt-oss-120b';
    public const DEFAULT_TIMEOUT = 120;

    /** @var string */
    private $url;

    /** @var string */
    private $model;

    /** @var string|null */
    private $token;

    /** @var int */
    private $timeout;

    /** @var bool */
    private $enabled;

    /** @var bool */
    private $verifySsl;

    public function __construct(
        string $url,
        string $model = self::DEFAULT_MODEL,
        ?string $token = null,
        int $timeout = self::DEFAULT_TIMEOUT,
        ?bool $enabled = null,
        bool $verifySsl = true
    ) {
        $this->url = rtrim(trim($url), '/');
        $this->model = $model !== '' ? $model : self::DEFAULT_MODEL;
        $this->token = ($token === '' ? null : $token);
        $this->timeout = $timeout > 0 ? $timeout : self::DEFAULT_TIMEOUT;
        // auto: включено, если задан URL
        $this->enabled = $enabled !== null ? $enabled : ($this->url !== '');
        $this->verifySsl = $verifySsl;
    }

    public function getUrl(): string
    {
        return $this->url;
    }

    public function getModel(): string
    {
        return $this->model;
    }

    public function getToken(): ?string
    {
        return $this->token;
    }

    public function getTimeout(): int
    {
        return $this->timeout;
    }

    /**
     * Включён ли LLM (фича активна и задан URL).
     */
    public function isEnabled(): bool
    {
        return $this->enabled && $this->url !== '';
    }

    /**
     * Проверять ли TLS-сертификат сервера LLM. false — только осознанный opt-in
     * для внутренних эндпоинтов с корпоративным CA (см. DBDUMP_LLM_VERIFY_SSL).
     */
    public function getVerifySsl(): bool
    {
        return $this->verifySsl;
    }

    /**
     * Создать из переменных окружения.
     */
    public static function fromEnv(): self
    {
        $url = self::readEnv(self::ENV_URL) ?? '';
        $model = self::readEnv(self::ENV_MODEL) ?? self::DEFAULT_MODEL;
        $token = self::readEnv(self::ENV_TOKEN);

        $timeoutRaw = self::readEnv(self::ENV_TIMEOUT);
        $timeout = ($timeoutRaw !== null && ctype_digit($timeoutRaw)) ? (int) $timeoutRaw : self::DEFAULT_TIMEOUT;

        $enabledRaw = self::readEnv(self::ENV_ENABLED);
        $enabled = null;
        if ($enabledRaw !== null) {
            $normalized = strtolower(trim($enabledRaw));
            $enabled = in_array($normalized, ['1', 'true', 'yes', 'on'], true);
        }

        $verifyRaw = self::readEnv(self::ENV_VERIFY_SSL);
        $verifySsl = $verifyRaw !== null ? self::toBool($verifyRaw, true) : true;

        return new self($url, $model, $token, $timeout, $enabled, $verifySsl);
    }

    /**
     * Создать из массива сохранённых настроек (см. DbdumpConfigStore).
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $url = isset($data['url']) ? (string) $data['url'] : '';
        $model = isset($data['model']) && $data['model'] !== '' ? (string) $data['model'] : self::DEFAULT_MODEL;
        $token = (isset($data['token']) && $data['token'] !== '') ? (string) $data['token'] : null;
        $timeout = isset($data['timeout']) ? (int) $data['timeout'] : self::DEFAULT_TIMEOUT;
        $enabled = array_key_exists('enabled', $data) ? (bool) $data['enabled'] : null;
        $verifySsl = array_key_exists('verify_ssl', $data) ? self::toBool($data['verify_ssl'], true) : true;

        return new self($url, $model, $token, $timeout, $enabled, $verifySsl);
    }

    /**
     * Сериализовать настройки для сохранения.
     *
     * @return array{url: string, model: string, token: string|null, timeout: int, enabled: bool, verify_ssl: bool}
     */
    public function toArray(): array
    {
        return [
            'url' => $this->url,
            'model' => $this->model,
            'token' => $this->token,
            'timeout' => $this->timeout,
            'enabled' => $this->enabled,
            'verify_ssl' => $this->verifySsl,
        ];
    }

    /**
     * Мягкий парсинг булева значения из настроек (bool или строка). Строки
     * '0','false','no','off','' → false; '1','true','yes','on' → true; иначе — $default.
     *
     * @param mixed $value
     */
    private static function toBool($value, bool $default): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value)) {
            return $value !== 0;
        }
        if (is_string($value)) {
            $normalized = strtolower(trim($value));
            if (in_array($normalized, ['1', 'true', 'yes', 'on'], true)) {
                return true;
            }
            if (in_array($normalized, ['0', 'false', 'no', 'off', ''], true)) {
                return false;
            }
        }
        return $default;
    }

    /**
     * @return string|null
     */
    private static function readEnv(string $name)
    {
        $value = getenv($name);
        if ($value !== false && $value !== '') {
            return $value;
        }
        if (isset($_SERVER[$name]) && is_string($_SERVER[$name]) && $_SERVER[$name] !== '') {
            return $_SERVER[$name];
        }
        if (isset($_ENV[$name]) && is_string($_ENV[$name]) && $_ENV[$name] !== '') {
            return $_ENV[$name];
        }
        return null;
    }
}
