<?php

namespace Timbrs\DatabaseDumps\Service\Analysis;

use Timbrs\DatabaseDumps\Contract\LoggerInterface;
use Timbrs\DatabaseDumps\Service\Ai\DbdumpConfigStore;

/**
 * Запуск внешнего агента OPENCODE (dbdump-mapper) из команды prepare-analysis --run.
 *
 * По умолчанию пакет НЕ вызывает opencode (пользователь делает это вручную по RUN.md).
 * Флаг --run — opt-in: модуль сам прогоняет `opencode run` по чанку на схему, чтобы
 * свести ветку анализа кода к одной команде.
 *
 * Синтаксис `opencode run` (по факту `opencode run --help`): `run [message..] --agent <name>`.
 * Разрешения (permissions) задаются НЕ флагом CLI, а в frontmatter агента (`permission:` в
 * dbdump-mapper.md: read/edit/write/grep/glob/list = allow) — поэтому флага авто-аппрува в
 * команде нет (у sst/opencode нет ни --auto, ни --dangerously-skip-permissions).
 *
 * Имя бинаря настраивается (у пользователя это может быть `opencode-cli`, симлинк/обёртка
 * над sst/opencode). Входной файл инвентаря НЕ передаётся флагом -f (он у opencode
 * вариадический — «file(s) to attach» [array] — и съедает следующий за ним текст промпта,
 * отсюда ошибка «File not found: <промпт>»); путь вписывается прямо в сообщение, а читает
 * файл сам агент своим read-tool.
 *
 * Модель передаётся флагом -m ЦЕЛИКОМ из поля "model" в opencode.json (напр.
 * "uralsib/openai/gpt-oss-120b"): без явного -m headless-запуск не определяет провайдера/модель
 * и падает «Model not found». Первый сегмент строки — провайдер, остальное — modelID (может
 * содержать "/"), поэтому урезать её нельзя. env DBDUMP_OPENCODE_MODEL — ручной override.
 *
 * Exec-вызовы вынесены в protected-методы для подмены в юнит-тестах (без реального
 * запуска внешнего процесса).
 */
class OpencodeRunner
{
    public const AGENT_NAME = 'dbdump-mapper';

    /** Имя бинаря по умолчанию (переопределяется через конфиг/configure-llm). */
    public const DEFAULT_BIN = DbdumpConfigStore::DEFAULT_OPENCODE_BIN;

    /** Ручной override модели для -m (если пользователь хочет обойти чтение opencode.json). */
    public const ENV_MODEL = 'DBDUMP_OPENCODE_MODEL';

    /** @var LoggerInterface */
    private $logger;

    /** @var string имя/путь бинаря opencode (напр. 'opencode' или 'opencode-cli') */
    private $opencodeBin;

    /** @var string модель для -m (provider/modelID из opencode.json); '' — флаг не добавляется */
    private $opencodeModel;

    /**
     * Имя бинаря берётся из единого хранилища настроек (config/database-dumps.php через
     * DbdumpConfigStore) — то же, что пишет configure-llm; env DBDUMP_OPENCODE_BIN перекрывает.
     *
     * Модель для -m берётся ЦЕЛИКОМ из поля "model" в opencode.json (проектный → глобальный) —
     * там пользователь уже задал провайдера и модель (напр. "uralsib/openai/gpt-oss-120b"; первый
     * сегмент = провайдер, остальное = modelID, который сам может содержать "/"). Явный -m нужен,
     * т.к. headless `opencode run` не всегда определяет дефолт-модель из состояния сессии
     * (→ ошибка «Model not found»). env DBDUMP_OPENCODE_MODEL — ручной override.
     */
    public function __construct(LoggerInterface $logger, DbdumpConfigStore $store, string $projectDir)
    {
        $this->logger = $logger;
        $dir = rtrim($projectDir, '/\\');
        $bin = trim($store->getOpencodeBin($dir));
        $this->opencodeBin = $bin !== '' ? $bin : self::DEFAULT_BIN;
        $this->opencodeModel = $this->resolveModel($dir);
    }

    /**
     * Установлен ли opencode (есть в PATH).
     */
    public function isAvailable(): bool
    {
        return $this->locate() !== null;
    }

    /**
     * Прогнать агента по одному инвентарю (одна схема).
     *
     * @return int код возврата процесса (0 — успех)
     */
    public function runAgent(string $projectDir, string $inventoryFile, string $prompt): int
    {
        $bin = $this->locate();
        if ($bin === null) {
            throw new \RuntimeException('opencode не найден в PATH.');
        }

        $command = $this->buildCommand($bin, $inventoryFile, $prompt);
        $this->logger->info('Запуск OPENCODE: ' . $command);

        return $this->execProcess($command, $projectDir);
    }

    /**
     * Готовая к вставке команда запуска (для вывода пользователю в ручном режиме).
     */
    public function manualCommandHint(string $inventoryFile): string
    {
        $model = $this->opencodeModel !== '' ? ' -m ' . $this->opencodeModel : '';

        return sprintf(
            '%s run --agent %s%s "Прочитай файл %s, построй карту связей и использования '
            . 'колонок по инструкции агента и запиши результат в database/analysis/out/"',
            $this->opencodeBin,
            self::AGENT_NAME,
            $model,
            $inventoryFile
        );
    }

    /**
     * Собрать строку команды (escape аргументов). Protected — для проверки в тестах.
     *
     * Путь к файлу инвентаря вписывается в текст сообщения (не через -f — тот вариадический
     * и съел бы промпт); файл читает сам агент. Разрешения — в frontmatter агента, не флагом.
     * Модель задаётся -m целиком из opencode.json (иначе headless не определит провайдера/модель).
     */
    protected function buildCommand(string $bin, string $inventoryFile, string $prompt): string
    {
        $message = sprintf('Прочитай файл %s. %s', $inventoryFile, $prompt);

        $cmd = escapeshellarg($bin) . ' run --agent ' . escapeshellarg(self::AGENT_NAME);
        if ($this->opencodeModel !== '') {
            $cmd .= ' -m ' . escapeshellarg($this->opencodeModel);
        }
        $cmd .= ' ' . escapeshellarg($message);

        return $cmd;
    }

    /**
     * Модель для -m: env-override → поле "model" из opencode.json (проектный → глобальный) → ''.
     */
    private function resolveModel(string $projectDir): string
    {
        $env = getenv(self::ENV_MODEL);
        if (is_string($env) && trim($env) !== '') {
            return trim($env);
        }
        foreach ($this->opencodeConfigPaths($projectDir) as $path) {
            $model = $this->modelFromConfig($path);
            if ($model !== '') {
                return $model;
            }
        }
        return '';
    }

    /**
     * Пути поиска opencode.json: проектные (переопределяют) → глобальный XDG.
     *
     * @return array<int, string>
     */
    private function opencodeConfigPaths(string $projectDir): array
    {
        $paths = [
            $projectDir . '/opencode.json',
            $projectDir . '/opencode.jsonc',
            $projectDir . '/.opencode/opencode.json',
        ];
        $home = $this->homeDir();
        if ($home !== '') {
            $xdg = getenv('XDG_CONFIG_HOME');
            $base = (is_string($xdg) && $xdg !== '') ? rtrim($xdg, '/\\') : $home . '/.config';
            $paths[] = $base . '/opencode/opencode.json';
        }
        return $paths;
    }

    /**
     * Домашний каталог пользователя (HOME → USERPROFILE). '' — не определён.
     */
    private function homeDir(): string
    {
        foreach (['HOME', 'USERPROFILE'] as $var) {
            $value = getenv($var);
            if (is_string($value) && $value !== '') {
                return rtrim($value, '/\\');
            }
        }
        return '';
    }

    /**
     * Прочитать поле "model" из opencode.json по пути. '' — файла нет / нет поля / не JSON.
     */
    private function modelFromConfig(string $path): string
    {
        $raw = $this->readConfigFile($path);
        if (!is_string($raw) || $raw === '') {
            return '';
        }
        $data = json_decode($raw, true);
        if (is_array($data) && isset($data['model']) && is_string($data['model']) && trim($data['model']) !== '') {
            return trim($data['model']);
        }
        return '';
    }

    /**
     * Прочитать файл конфигурации. Protected — для подмены в юнит-тестах.
     *
     * @return string|false
     */
    protected function readConfigFile(string $path)
    {
        return is_file($path) ? @file_get_contents($path) : false;
    }

    /**
     * Найти бинарь opencode в PATH. Protected — для подмены в тестах.
     *
     * @return string|null
     */
    protected function locate()
    {
        $bin = $this->opencodeBin;
        $finder = $this->isWindows()
            ? 'where ' . escapeshellarg($bin)
            : 'command -v ' . escapeshellarg($bin) . ' 2>/dev/null';
        $output = [];
        $code = 0;
        @exec($finder, $output, $code);
        if ($code === 0 && !empty($output)) {
            $first = trim((string) $output[0]);
            return $first !== '' ? $first : null;
        }
        return null;
    }

    /**
     * Запустить процесс с наследованием stdout/stderr. Protected — для подмены в тестах.
     */
    protected function execProcess(string $command, string $cwd): int
    {
        $descriptors = [0 => STDIN, 1 => STDOUT, 2 => STDERR];
        $process = @proc_open($command, $descriptors, $pipes, $cwd);
        if (!is_resource($process)) {
            throw new \RuntimeException('Не удалось запустить процесс opencode.');
        }
        return (int) proc_close($process);
    }

    protected function isWindows(): bool
    {
        return PHP_OS_FAMILY === 'Windows';
    }
}
