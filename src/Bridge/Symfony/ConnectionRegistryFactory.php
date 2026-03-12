<?php

namespace Timbrs\DatabaseDumps\Bridge\Symfony;

use Timbrs\DatabaseDumps\Adapter\DoctrineDbalAdapter;
use Timbrs\DatabaseDumps\Config\DumpConfig;
use Timbrs\DatabaseDumps\Contract\ConnectionRegistryInterface;
use Timbrs\DatabaseDumps\Service\ConnectionRegistry;
use Psr\Container\ContainerInterface;

/**
 * Фабрика ConnectionRegistry для Symfony
 *
 * Создаёт реестр подключений, резолвя Doctrine DBAL connections по именам из DumpConfig
 */
class ConnectionRegistryFactory
{
    /**
     * @param object $defaultConnection Doctrine DBAL Connection
     * @param DumpConfig $dumpConfig
     * @param ContainerInterface $container
     * @return ConnectionRegistryInterface
     */
    public static function create($defaultConnection, DumpConfig $dumpConfig, ContainerInterface $container): ConnectionRegistryInterface
    {
        $registry = new ConnectionRegistry('default');
        $registry->register('default', new DoctrineDbalAdapter($defaultConnection));

        foreach (array_keys($dumpConfig->getConnectionConfigs()) as $connName) {
            $serviceId = 'doctrine.dbal.' . $connName . '_connection';
            if ($container->has($serviceId)) {
                $registry->register($connName, new DoctrineDbalAdapter($container->get($serviceId)));
            }
        }

        return $registry;
    }
}
