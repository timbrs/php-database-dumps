<?php

namespace Timbrs\DatabaseDumps\Service\Importer;

use Timbrs\DatabaseDumps\Contract\ConnectionRegistryInterface;
use Timbrs\DatabaseDumps\Contract\DatabaseConnectionInterface;
use Timbrs\DatabaseDumps\Contract\FileSystemInterface;
use Timbrs\DatabaseDumps\Contract\LoggerInterface;
use Timbrs\DatabaseDumps\Platform\PlatformFactory;
use Timbrs\DatabaseDumps\Service\Parser\SqlParser;

/**
 * Выполнение before/after exec SQL скриптов
 */
class ScriptExecutor
{
    /** @var ConnectionRegistryInterface */
    private $registry;
    /** @var FileSystemInterface */
    private $fileSystem;
    /** @var SqlParser */
    private $parser;
    /** @var LoggerInterface */
    private $logger;

    public function __construct(
        ConnectionRegistryInterface $registry,
        FileSystemInterface $fileSystem,
        SqlParser $parser,
        LoggerInterface $logger
    ) {
        $this->registry = $registry;
        $this->fileSystem = $fileSystem;
        $this->parser = $parser;
        $this->logger = $logger;
    }

    /**
     * Выполнить скрипты из директории (на дефолтном подключении)
     */
    public function executeScripts(string $directory): void
    {
        $connection = $this->registry->getConnection();
        $this->executeScriptsOn($connection, $directory);
    }

    /**
     * Выполнить скрипты из директории на указанном подключении
     */
    public function executeScriptsOn(DatabaseConnectionInterface $connection, string $directory): void
    {
        if (!$this->fileSystem->isDirectory($directory)) {
            $this->logger->info("Директория не найдена: {$directory}");
            return;
        }

        $files = $this->fileSystem->findFiles($directory, '*.sql');

        if (empty($files)) {
            $this->logger->info("SQL файлы не найдены в: {$directory}");
            return;
        }

        // Сортировка для предсказуемого порядка выполнения
        sort($files);

        $platformName = $connection->getPlatformName();
        $backslashEscapes = $platformName === PlatformFactory::MYSQL
            || $platformName === PlatformFactory::MARIADB;

        $total = count($files);
        $current = 0;

        foreach ($files as $file) {
            $current++;
            $this->executeScript($connection, $file, $current, $total, $backslashEscapes);
        }
    }

    /**
     * Выполнить один SQL скрипт
     *
     * @param bool $backslashEscapes
     */
    private function executeScript(DatabaseConnectionInterface $connection, string $filePath, int $current, int $total, $backslashEscapes = false): void
    {
        $filename = basename($filePath);

        try {
            $sql = $this->fileSystem->read($filePath);
            $statements = $this->parser->parseFile($sql, $backslashEscapes);

            foreach ($statements as $statement) {
                if (!empty(trim($statement))) {
                    $connection->executeStatement($statement);
                }
            }

            $this->logger->info("[{$current}/{$total}] {$filename} ... OK");
        } catch (\Exception $e) {
            $this->logger->error("[{$current}/{$total}] {$filename} ... ERROR: " . $e->getMessage());
            throw $e;
        }
    }
}
