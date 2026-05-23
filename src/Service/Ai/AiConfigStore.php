<?php

namespace Timbrs\DatabaseDumps\Service\Ai;

use Timbrs\DatabaseDumps\Config\AiConfig;
use Timbrs\DatabaseDumps\Contract\FileSystemInterface;

/**
 * Хранилище настроек LLM в файле проекта.
 *
 * Команда configure-llm спрашивает параметры (есть ли LLM, URL, модель, token)
 * и сохраняет их сюда; AiConfig затем подхватывает их при каждом запуске.
 *
 * Файл: <projectDir>/database/dbdump_llm.json — рядом с database/dumps и
 * database/analysis. Может содержать token (секрет) → файл стоит добавить в
 * .gitignore (команда об этом предупреждает), права 0640 ставит FileSystemHelper.
 *
 * Приоритет источников (resolve): переменные окружения DBDUMP_LLM_* (если задан
 * URL) ПЕРЕКРЫВАЮТ сохранённый файл — удобно для CI/прод-оверрайдов; иначе
 * используется сохранённый файл; иначе LLM выключен.
 */
class AiConfigStore
{
    public const RELATIVE_PATH = 'database/dbdump_llm.json';

    /** @var FileSystemInterface */
    private $fileSystem;

    public function __construct(FileSystemInterface $fileSystem)
    {
        $this->fileSystem = $fileSystem;
    }

    public function path(string $projectDir): string
    {
        return rtrim($projectDir, '/\\') . '/' . self::RELATIVE_PATH;
    }

    public function exists(string $projectDir): bool
    {
        return $this->fileSystem->exists($this->path($projectDir));
    }

    /**
     * Загрузить сохранённые настройки или null, если файла нет/он повреждён.
     *
     * @return array<string, mixed>|null
     */
    public function load(string $projectDir): ?array
    {
        $path = $this->path($projectDir);
        if (!$this->fileSystem->exists($path)) {
            return null;
        }
        $decoded = json_decode($this->fileSystem->read($path), true);
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Сохранить настройки LLM в файл проекта.
     */
    public function save(string $projectDir, AiConfig $config): void
    {
        $path = $this->path($projectDir);
        $dir = dirname($path);
        if (!$this->fileSystem->exists($dir)) {
            $this->fileSystem->createDirectory($dir);
        }
        $json = json_encode($config->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->fileSystem->write($path, $json === false ? '{}' : $json);
    }

    /**
     * Построить итоговый AiConfig: env (если задан URL) перекрывает файл; иначе файл; иначе env-дефолты.
     */
    public function resolve(string $projectDir): AiConfig
    {
        $env = AiConfig::fromEnv();
        if ($env->getUrl() !== '') {
            return $env;
        }

        $data = $this->load($projectDir);
        if ($data !== null) {
            return AiConfig::fromArray($data);
        }

        return $env;
    }
}
