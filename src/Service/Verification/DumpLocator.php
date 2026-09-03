<?php

namespace Timbrs\DatabaseDumps\Service\Verification;

use Timbrs\DatabaseDumps\Config\TableConfig;

/**
 * Где лежит файл дампа таблицы — зеркало DatabaseDumper::buildDumpPath:
 * <dumps>[/<connection>]/<schema>/<table>.sql.
 */
class DumpLocator
{
    public static function path(string $dumpsRoot, TableConfig $config): string
    {
        $prefix = rtrim($dumpsRoot, '/\\');
        $connection = $config->getConnectionName();
        if ($connection !== null) {
            $prefix .= '/' . $connection;
        }

        return $prefix . '/' . $config->getSchema() . '/' . $config->getTable() . '.sql';
    }
}
