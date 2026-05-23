<?php

namespace Timbrs\DatabaseDumps\Service\Analysis;

use Timbrs\DatabaseDumps\Contract\LoggerInterface;

/**
 * Запуск внешнего агента OPENCODE (dbdump-mapper) из команды prepare-analysis --run.
 *
 * По умолчанию пакет НЕ вызывает opencode (пользователь делает это вручную по RUN.md).
 * Флаг --run — opt-in: модуль сам прогоняет `opencode run` по чанку на схему, чтобы
 * свести ветку анализа кода к одной команде. Запуск идёт с
 * --dangerously-skip-permissions (иначе opencode прервётся на интерактивном prompt'е
 * в неинтерактивном окружении); агент при этом ограничен инструкциями писать только
 * в database/analysis/out/.
 *
 * Exec-вызовы вынесены в protected-методы для подмены в юнит-тестах (без реального
 * запуска внешнего процесса).
 */
class OpencodeRunner
{
    public const AGENT_NAME = 'dbdump-mapper';

    /** @var LoggerInterface */
    private $logger;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
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
            'opencode run --agent %s -f %s "Построй карту связей и использования колонок по инструкции; '
            . 'результат запиши в database/analysis/out/"',
            self::AGENT_NAME,
            $inventoryFile
        );
    }

    /**
     * Собрать строку команды (escape аргументов). Protected — для проверки в тестах.
     */
    protected function buildCommand(string $bin, string $inventoryFile, string $prompt): string
    {
        return escapeshellarg($bin)
            . ' run --agent ' . escapeshellarg(self::AGENT_NAME)
            . ' --dangerously-skip-permissions'
            . ' -f ' . escapeshellarg($inventoryFile)
            . ' ' . escapeshellarg($prompt);
    }

    /**
     * Найти бинарь opencode в PATH. Protected — для подмены в тестах.
     *
     * @return string|null
     */
    protected function locate()
    {
        $finder = $this->isWindows() ? 'where opencode' : 'command -v opencode 2>/dev/null';
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
