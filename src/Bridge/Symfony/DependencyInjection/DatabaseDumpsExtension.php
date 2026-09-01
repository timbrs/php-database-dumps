<?php

namespace Timbrs\DatabaseDumps\Bridge\Symfony\DependencyInjection;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\Config\Resource\FileExistenceResource;
use Symfony\Component\Config\Resource\FileResource;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Timbrs\DatabaseDumps\Config\EnvironmentConfig;
use Timbrs\DatabaseDumps\Service\Ai\DbdumpConfigStore;
use Timbrs\DatabaseDumps\Util\FileSystemHelper;

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

        $configPath = $processed['config_path'] ?: $this->resolveDefaultConfigPath($container);
        $container->setParameter('database_dumps.config_path', $configPath);

        $loader = new YamlFileLoader($container, new FileLocator(__DIR__ . '/../Resources/config'));
        $loader->load('services.yaml');
    }

    /**
     * Дефолтный путь к главному конфигу — {data_dir}/dump_config.yaml, где data_dir берётся
     * из того же источника, что и дампы с анализом (env DBDUMP_DATA_DIR → config/database-dumps.php
     * → docker/database). Иначе конфиг остался бы в одном каталоге, а дампы уехали бы в другой.
     *
     * config/database-dumps.php читается на этапе компиляции контейнера, поэтому регистрируем его
     * ресурсом: правка data_dir пересобирает контейнер, а не оставляет протухший путь в кэше.
     */
    private function resolveDefaultConfigPath(ContainerBuilder $container): string
    {
        $projectDir = (string) $container->getParameter('kernel.project_dir');
        $environment = new EnvironmentConfig((string) $container->getParameter('kernel.environment'));
        $store = new DbdumpConfigStore(new FileSystemHelper(), $environment);

        $storePath = $store->path($projectDir);
        $container->addResource(new FileExistenceResource($storePath));
        if (is_file($storePath)) {
            $container->addResource(new FileResource($storePath));
        }

        return $store->getConfigPath($projectDir);
    }
}
