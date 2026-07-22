<?php

namespace Timbrs\DatabaseDumps\Service\Analysis;

use Timbrs\DatabaseDumps\Contract\ConnectionRegistryInterface;

/**
 * Проверяет пригодность WHERE-фрагмента sample.criterion, ВЫПОЛНЯЯ его в БД так же, как это
 * сделает дампер: SELECT 1 FROM schema.table WHERE (<where>) LIMIT 1. Возвращает null, если
 * запрос прошёл, иначе — короткую реальную ошибку СУБД (алиас t1., несуществующая колонка,
 * bind-параметр, синтаксис). Используется циклом исправления, чтобы дать агенту точный,
 * настоящий фидбэк по его же criteria (надёжнее статической эвристики).
 */
class CriteriaSqlTester
{
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
            return $this->shortError($e->getMessage());
        }
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
