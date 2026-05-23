<?php

namespace Timbrs\DatabaseDumps\Bridge\Symfony;

use Psr\Container\ContainerInterface;
use Timbrs\DatabaseDumps\Adapter\DoctrineDbalAdapter;
use Timbrs\DatabaseDumps\Config\DumpConfig;
use Timbrs\DatabaseDumps\Contract\ConnectionRegistryInterface;
use Timbrs\DatabaseDumps\Service\ConnectionRegistry;

/**
 * Фабрика ConnectionRegistry для Symfony.
 *
 * Создаёт реестр подключений, резолвя Doctrine DBAL connections по именам из DumpConfig.
 * Если ожидаемое подключение отсутствует в контейнере — поднимается понятное исключение.
 */
class ConnectionRegistryFactory
{
    /**
     * @param object $defaultConnection Doctrine DBAL Connection
     */
    public static function create(
        $defaultConnection,
        DumpConfig $dumpConfig,
        ContainerInterface $container
    ): ConnectionRegistryInterface {
        $registry = new ConnectionRegistry('default');
        $registry->register('default', new DoctrineDbalAdapter($defaultConnection));

        foreach (array_keys($dumpConfig->getConnectionConfigs()) as $connName) {
            $serviceId = 'doctrine.dbal.' . $connName . '_connection';
            if (!$container->has($serviceId)) {
                throw new \RuntimeException(sprintf(
                    'Connection "%s" из dump_config.yaml не найдено в Doctrine DBAL '
                    . '(ожидался сервис "%s"). Зарегистрируйте подключение в config/packages/doctrine.yaml.',
                    $connName,
                    $serviceId
                ));
            }
            $registry->register($connName, new DoctrineDbalAdapter($container->get($serviceId)));
        }

        return $registry;
    }
}
