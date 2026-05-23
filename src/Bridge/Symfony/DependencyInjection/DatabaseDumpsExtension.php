<?php

namespace Timbrs\DatabaseDumps\Bridge\Symfony\DependencyInjection;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;

/**
 * DI Extension для регистрации сервисов пакета.
 *
 * Принимает конфиг через стандартный механизм Symfony (config/packages/database_dumps.yaml).
 * Дефолты заданы в Configuration; контейнер ВСЕГДА получит валидные значения
 * параметров, даже если пользователь ничего не настраивал.
 */
class DatabaseDumpsExtension extends Extension
{
    public function getConfiguration(array $config, ContainerBuilder $container): Configuration
    {
        return new Configuration();
    }

    public function load(array $configs, ContainerBuilder $container): void
    {
        $configuration = new Configuration();
        $processed = $this->processConfiguration($configuration, $configs);

        $container->setParameter('database_dumps.platform', $processed['platform']);
        $container->setParameter('database_dumps.batch_size', $processed['batch_size']);
        $container->setParameter('database_dumps.sample_size', $processed['sample_size']);
        $container->setParameter('database_dumps.max_cascade_depth', $processed['max_cascade_depth']);

        $configPath = $processed['config_path']
            ?: $container->getParameter('kernel.project_dir') . '/config/dump_config.yaml';
        $container->setParameter('database_dumps.config_path', $configPath);

        $loader = new YamlFileLoader($container, new FileLocator(__DIR__ . '/../Resources/config'));
        $loader->load('services.yaml');
    }
}
