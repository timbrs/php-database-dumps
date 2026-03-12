<?php

namespace Timbrs\DatabaseDumps\Platform;

use Timbrs\DatabaseDumps\Contract\DatabasePlatformInterface;

/**
 * Фабрика для создания платформы по имени
 */
class PlatformFactory
{
    public const POSTGRESQL = 'postgresql';
    public const PGSQL = 'pgsql';
    public const MYSQL = 'mysql';
    public const MARIADB = 'mariadb';
    public const ORACLE = 'oracle';
    public const OCI = 'oci';

    /**
     * @param string $platformName Имя платформы (postgresql, pgsql, mysql, mariadb)
     * @return DatabasePlatformInterface
     * @throws \InvalidArgumentException
     */
    public static function create(string $platformName): DatabasePlatformInterface
    {
        $normalized = strtolower(trim($platformName));

        switch ($normalized) {
            case self::POSTGRESQL:
            case self::PGSQL:
                return new PostgresPlatform();
            case self::MYSQL:
            case self::MARIADB:
                return new MySqlPlatform();
            case self::ORACLE:
            case self::OCI:
                return new OraclePlatform();
            default:
                throw new \InvalidArgumentException("Неподдерживаемая платформа БД: {$platformName}");
        }
    }
}
