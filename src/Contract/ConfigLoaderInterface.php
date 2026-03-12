<?php

namespace Timbrs\DatabaseDumps\Contract;

use Timbrs\DatabaseDumps\Config\DumpConfig;

/**
 * Интерфейс для загрузки конфигурации дампов
 */
interface ConfigLoaderInterface
{
    /**
     * Загрузить конфигурацию из файла
     *
     * @param string $path Путь к файлу конфигурации (обычно YAML)
     * @return DumpConfig
     */
    public function load(string $path): DumpConfig;
}
