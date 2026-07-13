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
 * Exec-вызовы вынесены в protected-методы для подмены в юнит-тестах (без реального
 * запуска внешнего процесса).
 */
class OpencodeRunner
{
    public const AGENT_NAME = 'dbdump-mapper';

    /** Имя бинаря по умолчанию (переопределяется через конфиг/configure-llm). */
    public const DEFAULT_BIN = DbdumpConfigStore::DEFAULT_OPENCODE_BIN;

    /** @var LoggerInterface */
    private $logger;

    /** @var string имя/путь бинаря opencode (напр. 'opencode' или 'opencode-cli') */
    private $opencodeBin;

    /**
     * Имя бинаря берётся из единого хранилища настроек (config/database-dumps.php через
     * DbdumpConfigStore) — то же, что пишет configure-llm; env DBDUMP_OPENCODE_BIN перекрывает.
     */
    public function __construct(LoggerInterface $logger, DbdumpConfigStore $store, string $projectDir)
    {
        $this->logger = $logger;
        $bin = trim($store->getOpencodeBin(rtrim($projectDir, '/\\')));
        $this->opencodeBin = $bin !== '' ? $bin : self::DEFAULT_BIN;
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
        return sprintf(
            '%s run --agent %s "Прочитай файл %s, построй карту связей и использования '
            . 'колонок по инструкции агента и запиши результат в database/analysis/out/"',
            $this->opencodeBin,
            self::AGENT_NAME,
            $inventoryFile
        );
    }

    /**
     * Собрать строку команды (escape аргументов). Protected — для проверки в тестах.
     *
     * Путь к файлу инвентаря вписывается в текст сообщения (не через -f — тот вариадический
     * и съел бы промпт); файл читает сам агент. Разрешения — в frontmatter агента, не флагом.
     */
    protected function buildCommand(string $bin, string $inventoryFile, string $prompt): string
    {
        $message = sprintf('Прочитай файл %s. %s', $inventoryFile, $prompt);

        return escapeshellarg($bin)
            . ' run --agent ' . escapeshellarg(self::AGENT_NAME)
            . ' ' . escapeshellarg($message);
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
