<?php

namespace Timbrs\DatabaseDumps\Service\Ai;

use Timbrs\DatabaseDumps\Config\AiConfig;
use Timbrs\DatabaseDumps\Config\EnvironmentConfig;
use Timbrs\DatabaseDumps\Contract\FileSystemInterface;

/**
 * Единое хранилище настроек timbrs/database-dumps в config/database-dumps.php.
 *
 * Файл возвращает массив:
 *   [
 *     'data_dir' => 'database',   // база для дампов/анализа/хуков (можно 'var/database')
 *     'llm' => ['enabled' => .., 'url' => .., 'model' => .., 'timeout' => ..],
 *   ]
 * Секрет (token) в файле НЕ хранится — он в .env.local (DBDUMP_LLM_TOKEN),
 * см. EnvFileWriter. В Laravel это родной публикуемый конфиг (с env()).
 *
 * Приоритеты:
 *  - data_dir: env DBDUMP_DATA_DIR → файл data_dir → 'database'; в prod → 'database'.
 *  - llm: env DBDUMP_LLM_* (если задан URL) → файл; в prod → LLM выключен.
 *    Токен из окружения применяется поверх файла (URL из файла + token из env).
 */
class DbdumpConfigStore
{
    public const RELATIVE_PATH = 'config/database-dumps.php';
    public const DEFAULT_DATA_DIR = 'database';
    public const ENV_DATA_DIR = 'DBDUMP_DATA_DIR';

    /** Имя бинаря opencode (напр. 'opencode-cli'): дефолт, env и ключ файла opencode.bin. */
    public const DEFAULT_OPENCODE_BIN = 'opencode';
    public const ENV_OPENCODE_BIN = 'DBDUMP_OPENCODE_BIN';

    /** @var FileSystemInterface */
    private $fileSystem;

    /** @var EnvironmentConfig */
    private $environment;

    public function __construct(FileSystemInterface $fileSystem, EnvironmentConfig $environment = null)
    {
        $this->fileSystem = $fileSystem;
        $this->environment = $environment !== null ? $environment : EnvironmentConfig::fromEnv();
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
     * Загрузить массив настроек из PHP-файла или null (нет файла / результат не массив).
     *
     * @return array<string, mixed>|null
     */
    public function load(string $projectDir): ?array
    {
        $path = $this->path($projectDir);
        if (!$this->fileSystem->exists($path) || !is_file($path)) {
            return null;
        }
        /** @psalm-suppress UnresolvableInclude */
        $data = include $path;
        return is_array($data) ? $data : null;
    }

    /**
     * Сохранить несекретные настройки (data_dir + llm без token) в config/database-dumps.php.
     * Токен НИКОГДА не пишется в файл — он живёт в .env.local (см. EnvFileWriter).
     *
     * Неизвестные ключи (напр. Laravel config_path/project_dir, opencode) сохраняются.
     *
     * @param string|null $opencodeBin если задан — переопределяет секцию opencode.bin;
     *                                 null — сохраняется существующая (если была)
     */
    public function save(string $projectDir, AiConfig $config, ?string $dataDir = null, ?string $opencodeBin = null): void
    {
        $path = $this->path($projectDir);
        $dir = dirname($path);
        if (!$this->fileSystem->exists($dir)) {
            $this->fileSystem->createDirectory($dir);
        }

        $existing = $this->load($projectDir);
        $existing = is_array($existing) ? $existing : [];

        $existingLlm = (isset($existing['llm']) && is_array($existing['llm'])) ? $existing['llm'] : [];
        $llm = array_merge($existingLlm, [
            'enabled' => $config->isEnabled(),
            'url'     => $config->getUrl(),
            'model'   => $config->getModel(),
            'timeout' => $config->getTimeout(),
        ]);
        unset($llm['token']); // секрет в файл не пишем

        $resolvedDataDir = self::DEFAULT_DATA_DIR;
        if ($dataDir !== null && $dataDir !== '') {
            $resolvedDataDir = $dataDir;
        } elseif (isset($existing['data_dir']) && is_string($existing['data_dir']) && $existing['data_dir'] !== '') {
            $resolvedDataDir = $existing['data_dir'];
        }

        // Стабильный порядок вывода: data_dir, прочие сохранённые ключи, llm.
        $out = ['data_dir' => $resolvedDataDir];
        foreach ($existing as $k => $v) {
            if ($k === 'data_dir' || $k === 'llm') {
                continue;
            }
            $out[$k] = $v;
        }
        // Новое значение opencode.bin переопределяет существующее; null — сохраняем прежнее (через foreach выше).
        if ($opencodeBin !== null && $opencodeBin !== '') {
            $out['opencode'] = ['bin' => $opencodeBin];
        }
        $out['llm'] = $llm;

        $this->fileSystem->writeAtomic($path, $this->render($out));
    }

    /**
     * Имя/путь бинаря opencode: env DBDUMP_OPENCODE_BIN → файл opencode.bin → 'opencode'.
     */
    public function getOpencodeBin(string $projectDir): string
    {
        $env = $this->readEnv(self::ENV_OPENCODE_BIN);
        if ($env !== null) {
            return $env;
        }

        $data = $this->load($projectDir);
        if ($data !== null
            && isset($data['opencode']['bin'])
            && is_string($data['opencode']['bin'])
            && $data['opencode']['bin'] !== ''
        ) {
            return $data['opencode']['bin'];
        }

        return self::DEFAULT_OPENCODE_BIN;
    }

    /**
     * Базовый каталог данных (относительный): env → файл → 'database'; в prod — всегда 'database'.
     */
    public function getDataDir(string $projectDir): string
    {
        if ($this->environment->isProduction()) {
            return self::DEFAULT_DATA_DIR;
        }

        $env = $this->readEnv(self::ENV_DATA_DIR);
        if ($env !== null) {
            return $this->normalizeDataDir($env);
        }

        $data = $this->load($projectDir);
        if ($data !== null && isset($data['data_dir']) && is_string($data['data_dir']) && $data['data_dir'] !== '') {
            return $this->normalizeDataDir($data['data_dir']);
        }

        return self::DEFAULT_DATA_DIR;
    }

    /**
     * Построить итоговый AiConfig: prod → LLM off; env (если задан URL) → файл; иначе env-дефолты.
     * Токен из окружения накладывается поверх URL/модели из файла.
     */
    public function resolve(string $projectDir): AiConfig
    {
        if ($this->environment->isProduction()) {
            return AiConfig::fromArray(['url' => '', 'enabled' => false]);
        }

        $env = AiConfig::fromEnv();
        if ($env->getUrl() !== '') {
            return $env;
        }

        $data = $this->load($projectDir);
        if ($data !== null) {
            // Новый формат — секция llm; старый плоский формат поддерживаем как fallback.
            $llm = (isset($data['llm']) && is_array($data['llm'])) ? $data['llm'] : $data;
            $fileConfig = AiConfig::fromArray($llm);

            $token = $env->getToken();
            if ($token !== null && $fileConfig->getToken() === null) {
                return new AiConfig(
                    $fileConfig->getUrl(),
                    $fileConfig->getModel(),
                    $token,
                    $fileConfig->getTimeout(),
                    $fileConfig->isEnabled()
                );
            }
            return $fileConfig;
        }

        return $env;
    }

    private function normalizeDataDir(string $value): string
    {
        $trimmed = rtrim(trim($value), '/\\');
        return $trimmed === '' ? self::DEFAULT_DATA_DIR : $trimmed;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function render(array $data): string
    {
        $header = "<?php\n\n"
            . "// timbrs/database-dumps — настройки. Подтягивается только вне prod.\n"
            . "// Секреты держите в .env.local: DBDUMP_LLM_TOKEN=... (env перекрывает значения ниже).\n"
            . "// Файл создаётся/обновляется командами configure-llm и prepare-config.\n\n"
            . "return ";
        return $header . $this->renderValue($data, 0) . ";\n";
    }

    /**
     * @param mixed $value
     */
    private function renderValue($value, int $indent): string
    {
        if (is_array($value)) {
            $pad = str_repeat('    ', $indent + 1);
            $close = str_repeat('    ', $indent);
            $isList = array_keys($value) === range(0, count($value) - 1);
            $lines = [];
            foreach ($value as $k => $v) {
                $prefix = $isList ? '' : $this->renderScalar($k) . ' => ';
                $lines[] = $pad . $prefix . $this->renderValue($v, $indent + 1);
            }
            if (empty($lines)) {
                return '[]';
            }
            return "[\n" . implode(",\n", $lines) . ",\n" . $close . ']';
        }
        return $this->renderScalar($value);
    }

    /**
     * @param mixed $value
     */
    private function renderScalar($value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if ($value === null) {
            return 'null';
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        return var_export((string) $value, true);
    }

    /**
     * @return string|null
     */
    private function readEnv(string $name)
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
