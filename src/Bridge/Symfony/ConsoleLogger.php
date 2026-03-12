<?php

namespace Timbrs\DatabaseDumps\Bridge\Symfony;

use Timbrs\DatabaseDumps\Contract\LoggerInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Логгер для Symfony Console
 */
class ConsoleLogger implements LoggerInterface
{
    /** @var SymfonyStyle */
    private $io;

    public function __construct(SymfonyStyle $io)
    {
        $this->io = $io;
    }

    public function info(string $message): void
    {
        $this->io->text($message);
    }

    public function error(string $message): void
    {
        $this->io->error($message);
    }

    public function warning(string $message): void
    {
        $this->io->warning($message);
    }

    public function debug(string $message): void
    {
        if ($this->io->isVerbose()) {
            $this->io->text($message);
        }
    }
}
