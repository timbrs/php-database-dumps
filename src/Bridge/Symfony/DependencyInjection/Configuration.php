<?php

namespace Timbrs\DatabaseDumps\Bridge\Symfony\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

/**
 * Конфигурация bundle (ключ "database_dumps:" в config/packages).
 *
 * Поля:
 *   platform: 'postgresql' (default)
 *   batch_size: 1000
 *   sample_size: 200
 *   max_cascade_depth: 10
 *   config_path: '%kernel.project_dir%/config/dump_config.yaml'
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
                ->scalarNode('config_path')->defaultNull()->end()
            ->end();

        return $treeBuilder;
    }
}
