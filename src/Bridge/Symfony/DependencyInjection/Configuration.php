<?php

namespace Timbrs\DatabaseDumps\Bridge\Symfony\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;
use Timbrs\DatabaseDumps\Config\AiConfig;
use Timbrs\DatabaseDumps\Service\Ai\DbdumpConfigStore;

/**
 * Конфигурация bundle — корневой ключ "database_dumps:" в config/packages/database_dumps.yaml.
 *
 * Здесь ВСЕ настройки пакета, включая те, что раньше жили отдельным PHP-файлом
 * config/database-dumps.php (data_dir, llm, opencode): один файл, дефолты в одном месте,
 * работают оверрайды config/packages/{env}/ и инвалидация кэша контейнера.
 *
 * Токен LLM сюда НЕ кладётся — он в .env.local (DBDUMP_LLM_TOKEN). Переменные окружения
 * DBDUMP_* перекрывают значения из этого файла (см. DbdumpConfigStore).
 */
class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('database_dumps');
        $rootNode = $treeBuilder->getRootNode();

        $rootNode
            ->children()
                ->scalarNode('platform')->defaultValue('postgresql')->end()
                ->integerNode('batch_size')->defaultValue(1000)->min(1)->end()
                ->integerNode('sample_size')->defaultValue(200)->min(10)->end()
                ->integerNode('max_cascade_depth')->defaultValue(10)->min(1)->end()

                // Базовый каталог: от него считаются dump_config.yaml, dump-settings/,
                // dumps/, analysis/ и хуки before_exec/after_exec.
                ->scalarNode('data_dir')
                    ->defaultValue(DbdumpConfigStore::DEFAULT_DATA_DIR)
                    ->cannotBeEmpty()
                ->end()

                // Путь к главному конфигу выгрузки. null — {data_dir}/dump_config.yaml.
                ->scalarNode('config_path')->defaultNull()->end()

                ->arrayNode('opencode')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('bin')
                            ->defaultValue(DbdumpConfigStore::DEFAULT_OPENCODE_BIN)
                            ->cannotBeEmpty()
                        ->end()
                    ->end()
                ->end()

                ->arrayNode('llm')
                    ->addDefaultsIfNotSet()
                    ->children()
                        // null = auto: включено, если задан url
                        ->booleanNode('enabled')->defaultNull()->end()
                        ->scalarNode('url')->defaultValue('')->end()
                        ->scalarNode('model')->defaultValue(AiConfig::DEFAULT_MODEL)->end()
                        ->integerNode('timeout')->defaultValue(AiConfig::DEFAULT_TIMEOUT)->min(1)->end()
                        // Отключать проверку TLS только для внутренних эндпоинтов с корпоративным CA.
                        ->booleanNode('verify_ssl')->defaultTrue()->end()
                    ->end()
                ->end()
            ->end();

        return $treeBuilder;
    }
}
