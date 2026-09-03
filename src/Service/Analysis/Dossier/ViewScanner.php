<?php

namespace Timbrs\DatabaseDumps\Service\Analysis\Dossier;

use Timbrs\DatabaseDumps\Contract\ConnectionRegistryInterface;
use Timbrs\DatabaseDumps\Platform\PlatformFactory;

/**
 * Вьюхи схемы и таблицы под ними.
 *
 * Данные живут не только в таблицах: часть смыслов («активный клиент», «просроченная задача»)
 * описана вьюхой, и её определение — готовый ответ на вопрос, какие разрезы бывают. Читается
 * один запрос на схему (pg_views), значения данных не затрагиваются.
 *
 * PHP 7.2-совместимо.
 */
class ViewScanner
{
    /** @var ConnectionRegistryInterface */
    private $registry;

    /** @var string|null */
    private $connectionName;

    /** @var array<string, array<string, array<int, string>>> схема => таблица => вьюхи */
    private $cache = [];

    /** @var array<string, array<string, string>> схема => вьюха => определение */
    private $definitions = [];

    public function __construct(ConnectionRegistryInterface $registry, ?string $connectionName = null)
    {
        $this->registry = $registry;
        $this->connectionName = $connectionName;
    }

    /**
     * @return array<string, array<int, string>> «schema.table» => имена вьюх, где таблица упомянута
     */
    public function scan(string $schema): array
    {
        if (isset($this->cache[$schema])) {
            return $this->cache[$schema];
        }
        $this->cache[$schema] = [];
        $this->definitions[$schema] = [];

        try {
            $connection = $this->registry->getConnection($this->connectionName);
            if (PlatformFactory::canonicalize($connection->getPlatformName()) !== PlatformFactory::POSTGRESQL) {
                return [];
            }
            $rows = $connection->fetchAllAssociative(
                'SELECT viewname AS name, definition FROM pg_views WHERE schemaname = :schema',
                ['schema' => $schema]
            );
        } catch (\Throwable $e) {
            return [];
        }

        foreach ($rows as $row) {
            $name = isset($row['name']) ? (string) $row['name'] : '';
            $definition = isset($row['definition']) ? (string) $row['definition'] : '';
            if ($name === '') {
                continue;
            }
            $this->definitions[$schema][$name] = $definition;

            if (preg_match_all('/\b(?:FROM|JOIN)\s+([\w".]+)/i', $definition, $matches) === 0) {
                continue;
            }
            foreach ($matches[1] as $reference) {
                $table = str_replace('"', '', $reference);
                if (strpos($table, '.') === false) {
                    $table = $schema . '.' . $table;
                }
                if (!isset($this->cache[$schema][$table])) {
                    $this->cache[$schema][$table] = [];
                }
                if (!in_array($name, $this->cache[$schema][$table], true)) {
                    $this->cache[$schema][$table][] = $name;
                }
            }
        }

        return $this->cache[$schema];
    }

    /**
     * Определения вьюх схемы — из них видно, какие условия считаются «нормой» в проекте.
     *
     * @return array<string, string>
     */
    public function definitions(string $schema): array
    {
        $this->scan($schema);

        return isset($this->definitions[$schema]) ? $this->definitions[$schema] : [];
    }
}
