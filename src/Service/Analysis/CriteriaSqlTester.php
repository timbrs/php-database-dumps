<?php

namespace Timbrs\DatabaseDumps\Service\Analysis;

use Timbrs\DatabaseDumps\Contract\ConnectionRegistryInterface;

/**
 * Проверяет пригодность WHERE-фрагмента sample.criterion, ВЫПОЛНЯЯ его в БД так же, как это
 * сделает дампер: SELECT 1 FROM schema.table WHERE (<where>) LIMIT 1. Возвращает null, если
 * запрос прошёл, иначе — короткую реальную ошибку СУБД (алиас t1., несуществующая колонка,
 * bind-параметр, синтаксис). Используется циклом исправления, чтобы дать агенту точный,
 * настоящий фидбэк по его же criteria (надёжнее статической эвристики).
 *
 * Таймаут сессии (statement_timeout профиля analyze) — отдельный случай: критерий синтаксически
 * верен, но без индекса не исполним за отведённое время. Такая ошибка помечается префиксом
 * TIMEOUT_PREFIX, чтобы её не «чинили» как синтаксическую.
 */
class CriteriaSqlTester
{
    public const TIMEOUT_PREFIX = 'timeout: ';

    /** SQLSTATE PostgreSQL query_canceled — statement_timeout. */
    private const PG_QUERY_CANCELED = '57014';

    /** MySQL ER_QUERY_TIMEOUT (max_execution_time) и MariaDB ER_STATEMENT_TIMEOUT (max_statement_time). */
    private const MYSQL_TIMEOUT_CODES = [3024, 1969];

    /** @var ConnectionRegistryInterface */
    private $registry;

    public function __construct(ConnectionRegistryInterface $registry)
    {
        $this->registry = $registry;
    }

    /**
     * @return string|null null — WHERE исполнился; иначе короткая ошибка СУБД
     */
    public function test(string $schema, string $table, string $where, ?string $connectionName = null): ?string
    {
        if (trim($where) === '') {
            return 'пустой WHERE';
        }
        try {
            $connection = $this->registry->getConnection($connectionName);
            $platform = $this->registry->getPlatform($connectionName);
            $fullTable = $platform->getFullTableName($schema, $table);
            $sql = 'SELECT 1 FROM ' . $fullTable . ' WHERE (' . $where . ') ' . $platform->getLimitSql(1);
            $connection->fetchFirstColumn($sql);
            return null;
        } catch (\Throwable $e) {
            $short = $this->shortError($e->getMessage());

            return self::isTimeout($e) ? self::TIMEOUT_PREFIX . $short : $short;
        }
    }

    /**
     * Ошибка из test() означает таймаут, а не дефект критерия.
     */
    public static function isTimeoutError(?string $error): bool
    {
        return $error !== null && strpos($error, self::TIMEOUT_PREFIX) === 0;
    }

    /**
     * Исключение драйвера — таймаут запроса. Смотрит SQLSTATE (Doctrine, PDO), код ошибки
     * MySQL/MariaDB и текст — по всей цепочке previous.
     */
    public static function isTimeout(\Throwable $e): bool
    {
        for ($current = $e; $current !== null; $current = $current->getPrevious()) {
            $state = null;
            if (method_exists($current, 'getSQLState')) {
                $state = $current->getSQLState();
            } elseif ($current instanceof \PDOException && isset($current->errorInfo[0])) {
                $state = $current->errorInfo[0];
            }
            if ($state === self::PG_QUERY_CANCELED) {
                return true;
            }

            $code = $current->getCode();
            if ((string) $code === self::PG_QUERY_CANCELED) {
                return true;
            }
            if (is_numeric($code) && in_array((int) $code, self::MYSQL_TIMEOUT_CODES, true)) {
                return true;
            }

            if (preg_match(
                '/statement timeout|canceling statement|max_execution_time|max_statement_time|maximum statement execution time/i',
                $current->getMessage()
            ) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Первая строка ошибки, схлопнутые пробелы, обрезка — для короткого промпта агенту.
     */
    private function shortError(string $message): string
    {
        $line = strtok($message, "\n");
        $line = $line === false ? $message : $line;
        $collapsed = preg_replace('/\s+/', ' ', trim($line));
        $line = $collapsed === null ? $line : $collapsed;
        if (function_exists('mb_strlen') && mb_strlen($line) > 200) {
            return mb_substr($line, 0, 200) . '…';
        }
        return strlen($line) > 200 ? substr($line, 0, 200) . '…' : $line;
    }
}
