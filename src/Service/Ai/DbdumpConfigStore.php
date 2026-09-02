<?php

namespace Timbrs\DatabaseDumps\Service\Ai;

use Timbrs\DatabaseDumps\Config\AiConfig;
use Timbrs\DatabaseDumps\Config\EnvironmentConfig;
use Timbrs\DatabaseDumps\Config\SettingsFile\PhpArraySettingsFile;
use Timbrs\DatabaseDumps\Contract\FileSystemInterface;
use Timbrs\DatabaseDumps\Contract\SettingsFileInterface;

/**
 * Единое хранилище несекретных настроек пакета:
 *
 *   [
 *     'data_dir' => 'docker/database', // база для конфига/дампов/анализа/хуков (можно 'var/database')
 *     'opencode' => ['bin' => 'opencode'],
 *     'llm' => ['enabled' => .., 'url' => .., 'model' => .., 'timeout' => .., 'verify_ssl' => ..],
 *   ]
 *
 * ГДЕ они лежат — дело бриджа (SettingsFileInterface): в Symfony это
 * config/packages/database_dumps.yaml, в Laravel — публикуемый config/database-dumps.php.
 * Секрет (token) не хранится ни там, ни там — он в .env.local (DBDUMP_LLM_TOKEN),
 * см. EnvFileWriter.
 *
 * ОТКУДА читать — тоже дело бриджа. Symfony передаёт уже разобранные значения из
 * контейнера ($settings): так работают дефолты Configuration и оверрайды
 * config/packages/{env}/. Laravel и агностик-использование читают файл напрямую.
 *
 * Приоритеты:
 *  - data_dir: env DBDUMP_DATA_DIR → настройки → 'docker/database'; в prod → 'docker/database'.
 *  - llm: env DBDUMP_LLM_* (если задан URL) → настройки; в prod → LLM выключен.
 *    Токен из окружения применяется поверх (URL из настроек + token из env).
 */
class DbdumpConfigStore
{
    /**
     * Базовый каталог данных по умолчанию. Всё, что порождает пакет, лежит под ним:
     * главный конфиг ({data_dir}/dump_config.yaml), пер-схемные файлы
     * ({data_dir}/dump-settings), дампы ({data_dir}/dumps), анализ ({data_dir}/analysis)
     * и хуки ({data_dir}/before_exec, {data_dir}/after_exec).
     */
    public const DEFAULT_DATA_DIR = 'docker/database';
    public const ENV_DATA_DIR = 'DBDUMP_DATA_DIR';

    /** Имя главного файла конфига выгрузки внутри data_dir. */
    public const MAIN_CONFIG_FILE = 'dump_config.yaml';

    /** Имя бинаря opencode (напр. 'opencode-cli'): дефолт, env и ключ настроек opencode.bin. */
    public const DEFAULT_OPENCODE_BIN = 'opencode';
    public const ENV_OPENCODE_BIN = 'DBDUMP_OPENCODE_BIN';

    /** @var SettingsFileInterface */
    private $settingsFile;

    /** @var EnvironmentConfig */
    private $environment;

    /**
     * Разобранные настройки от бриджа (Symfony — из DI). null — читать файл.
     *
     * @var array<string, mixed>|null
     */
    private $settings;

    /**
     * @param array<string, mixed>|null $settings готовые настройки от бриджа; null — читать файл
     */
    public function __construct(
        FileSystemInterface $fileSystem,
        EnvironmentConfig $environment = null,
        SettingsFileInterface $settingsFile = null,
        ?array $settings = null
    ) {
        $this->settingsFile = $settingsFile !== null ? $settingsFile : new PhpArraySettingsFile($fileSystem);
        $this->environment = $environment !== null ? $environment : EnvironmentConfig::fromEnv();
        $this->settings = $settings;
    }

    /**
     * Путь к файлу настроек — для сообщений пользователю.
     */
    public function path(string $projectDir): string
    {
        return $this->settingsFile->path($projectDir);
    }

    public function exists(string $projectDir): bool
    {
        return $this->settingsFile->read($projectDir) !== null;
    }

    /**
     * Действующие настройки: из бриджа, иначе из файла; null — ничего нет.
     *
     * @return array<string, mixed>|null
     */
    public function load(string $projectDir): ?array
    {
        if ($this->settings !== null) {
            return $this->settings;
        }

        return $this->settingsFile->read($projectDir);
    }

    /**
     * Сохранить несекретные настройки (data_dir + opencode + llm без token) в файл настроек.
     * Токен НИКОГДА не пишется в файл — он живёт в .env.local (см. EnvFileWriter).
     *
     * Ключи, которыми пакет не управляет (Laravel config_path/project_dir, бандловые
     * platform/batch_size/…), сохраняются. За основу берётся СОДЕРЖИМОЕ ФАЙЛА, а не
     * load(): в Symfony load() отдаёт разобранные значения из контейнера, где чужих
     * ключей уже нет, и запись затёрла бы их.
     *
     * @param string|null $opencodeBin если задан — переопределяет секцию opencode.bin;
     *                                 null — сохраняется существующая (если была)
     */
    public function save(string $projectDir, AiConfig $config, ?string $dataDir = null, ?string $opencodeBin = null): void
    {
        $existing = $this->settingsFile->read($projectDir);
        $existing = is_array($existing) ? $existing : [];

        $existingLlm = (isset($existing['llm']) && is_array($existing['llm'])) ? $existing['llm'] : [];
        $llm = array_merge($existingLlm, [
            'enabled'    => $config->isEnabled(),
            'url'        => $config->getUrl(),
            'model'      => $config->getModel(),
            'timeout'    => $config->getTimeout(),
            'verify_ssl' => $config->getVerifySsl(),
        ]);
        unset($llm['token']); // секрет в файл не пишем

        // Действующий data_dir, а не только записанный в файле: в Symfony он может прийти
        // из дефолта Configuration, и save() не должен молча вернуть его к константе.
        $current = $this->load($projectDir);
        $resolvedDataDir = self::DEFAULT_DATA_DIR;
        if ($dataDir !== null && $dataDir !== '') {
            $resolvedDataDir = $dataDir;
        } elseif (isset($existing['data_dir']) && is_string($existing['data_dir']) && $existing['data_dir'] !== '') {
            $resolvedDataDir = $existing['data_dir'];
        } elseif (is_array($current) && isset($current['data_dir']) && is_string($current['data_dir']) && $current['data_dir'] !== '') {
            $resolvedDataDir = $current['data_dir'];
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

        $this->settingsFile->write($projectDir, $out);
    }

    /**
     * Имя/путь бинаря opencode: env DBDUMP_OPENCODE_BIN → настройки opencode.bin → 'opencode'.
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
     * Базовый каталог данных (относительный): env → файл → DEFAULT_DATA_DIR;
     * в prod — всегда DEFAULT_DATA_DIR.
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
     * Абсолютный путь к главному конфигу выгрузки: {projectDir}/{data_dir}/dump_config.yaml.
     *
     * Единая точка правды для бриджей — иначе конфиг и дампы разъезжаются по разным каталогам,
     * когда data_dir переопределён, а путь к конфигу зашит константой.
     */
    public function getConfigPath(string $projectDir): string
    {
        return rtrim($projectDir, '/\\') . '/' . $this->getDataDir($projectDir) . '/' . self::MAIN_CONFIG_FILE;
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
                    $fileConfig->isEnabled(),
                    $fileConfig->getVerifySsl()
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
