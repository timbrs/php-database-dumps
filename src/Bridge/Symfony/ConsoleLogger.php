<?php

namespace Timbrs\DatabaseDumps\Bridge\Symfony;

use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Timbrs\DatabaseDumps\Contract\LoggerInterface;

/**
 * Логгер для Symfony Console.
 *
 * Может работать в двух режимах:
 * - С установленным SymfonyStyle (вызвано setIo командой) — пишет в консоль.
 * - Без IO (DI default) — silent (защита: команды должны звать setIo в execute()).
 */
class ConsoleLogger implements LoggerInterface
{
    /** @var SymfonyStyle|null */
    private $io;

    /** @var bool */
    private $verbose = false;

    public function setIo(SymfonyStyle $io): void
    {
        $this->io = $io;
        $this->verbose = $io->isVerbose();
    }

    public function info(string $message): void
    {
        if ($this->io !== null) {
            $this->io->text($message);
        }
    }

    public function error(string $message): void
    {
        if ($this->io !== null) {
            $this->io->error($message);
        }
    }

    public function warning(string $message): void
    {
        if ($this->io !== null) {
            $this->io->warning($message);
        }
    }

    public function debug(string $message): void
    {
        if ($this->io !== null && $this->verbose) {
            $this->io->text($message);
        }
    }
}
