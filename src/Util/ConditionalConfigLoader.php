<?php

namespace Timbrs\DatabaseDumps\Util;

use Timbrs\DatabaseDumps\Config\DumpConfig;
use Timbrs\DatabaseDumps\Contract\ConfigLoaderInterface;

/**
 * Прокси над ConfigLoader, возвращающий пустой DumpConfig если файла нет.
 *
 * Используется в DI для устойчивости: при первой установке (когда
 * dump_config.yaml ещё не сгенерирован) контейнер не должен падать на
 * cache:clear.
 */
class ConditionalConfigLoader
{
    /** @var ConfigLoaderInterface */
    private $loader;

    public function __construct(ConfigLoaderInterface $loader)
    {
        $this->loader = $loader;
    }

    public function load(string $path): DumpConfig
    {
        if (!file_exists($path)) {
            return new DumpConfig([], []);
        }
        return $this->loader->load($path);
    }
}
