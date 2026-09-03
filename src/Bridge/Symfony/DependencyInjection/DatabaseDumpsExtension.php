<?php

namespace Timbrs\DatabaseDumps\Bridge\Symfony\DependencyInjection;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Timbrs\DatabaseDumps\Config\EnvironmentConfig;
use Timbrs\DatabaseDumps\Service\Ai\DbdumpConfigStore;

/**
 * DI Extension: config/packages/database_dumps.yaml → параметры контейнера.
 *
 * Дефолты заданы в Configuration, поэтому контейнер ВСЕГДА получает валидные значения,
 * даже если пользователь ничего не настраивал и файла нет.
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
        // Секция db целиком — её разбирает SafeQueryPolicy, здесь ключи не перечисляются.
        $container->setParameter('database_dumps.db', $processed['db']);

        $dataDir = $this->resolveDataDir($processed, $container);
        $container->setParameter('database_dumps.data_dir', $dataDir);

        // Настройки для DbdumpConfigStore — в той же форме, в какой их отдавал файл.
        // Так у Symfony один источник (DI), а env-оверрайды по-прежнему делает store.
        $container->setParameter('database_dumps.settings', [
            'data_dir' => $dataDir,
            'llm' => $processed['llm'],
        ]);

        // Главный конфиг выгрузки лежит внутри data_dir — рядом с dump-settings/, dumps/
        // и analysis/. Явный config_path перекрывает это правило.
        $configPath = $processed['config_path']
            ?: rtrim((string) $container->getParameter('kernel.project_dir'), '/\\')
                . '/' . $dataDir . '/' . DbdumpConfigStore::MAIN_CONFIG_FILE;
        $container->setParameter('database_dumps.config_path', $configPath);

        $loader = new YamlFileLoader($container, new FileLocator(__DIR__ . '/../Resources/config'));
        $loader->load('services.yaml');
    }

    /**
     * В production data_dir всегда дефолтный — то же правило, что и в
     * DbdumpConfigStore::getDataDir(). Иначе параметр контейнера и рантайм разошлись бы,
     * и config_path указывал бы не туда, куда пишутся дампы.
     *
     * @param array<string, mixed> $processed
     */
    private function resolveDataDir(array $processed, ContainerBuilder $container): string
    {
        $environment = new EnvironmentConfig((string) $container->getParameter('kernel.environment'));
        if ($environment->isProduction()) {
            return DbdumpConfigStore::DEFAULT_DATA_DIR;
        }

        return rtrim(trim((string) $processed['data_dir']), '/\\');
    }
}
