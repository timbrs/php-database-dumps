<?php

namespace Timbrs\DatabaseDumps\Platform;

use Timbrs\DatabaseDumps\Contract\DatabasePlatformInterface;
use Timbrs\DatabaseDumps\Contract\LoggerInterface;

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
     * Привести имя платформы к каноническому виду.
     */
    public static function canonicalize(string $platformName): string
    {
        $normalized = strtolower(trim($platformName));

        switch ($normalized) {
            case self::POSTGRESQL:
            case self::PGSQL:
                return self::POSTGRESQL;
            case self::MYSQL:
            case self::MARIADB:
                return self::MYSQL;
            case self::ORACLE:
            case self::OCI:
                return self::ORACLE;
            default:
                return $normalized;
        }
    }

    /**
     * @throws \InvalidArgumentException
     */
    public static function create(string $platformName, LoggerInterface $logger = null): DatabasePlatformInterface
    {
        switch (self::canonicalize($platformName)) {
            case self::POSTGRESQL:
                return new PostgresPlatform($logger);
            case self::MYSQL:
                return new MySqlPlatform($logger);
            case self::ORACLE:
                return new OraclePlatform($logger);
            default:
                throw new \InvalidArgumentException("Неподдерживаемая платформа БД: {$platformName}");
        }
    }
}
