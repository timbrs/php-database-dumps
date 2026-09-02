<?php

namespace Timbrs\DatabaseDumps\Contract;

/**
 * Файл несекретных настроек пакета: где он лежит, как его прочитать и как записать.
 *
 * Формат у бриджей разный и это не косметика: в Symfony настройки живут в
 * config/packages/database_dumps.yaml и проходят через DI-расширение (значит их видят
 * config/packages/{env}/ и кэш контейнера), в Laravel — в публикуемом
 * config/database-dumps.php с env(). Общее у них только то, что делает DbdumpConfigStore:
 * читает, сливает с окружением и пишет обратно из configure-llm/prepare-config.
 *
 * Секреты сюда НЕ пишутся: токен живёт в .env.local (см. EnvFileWriter).
 */
interface SettingsFileInterface
{
    /**
     * Абсолютный путь к файлу — показывается пользователю в выводе команд.
     */
    public function path(string $projectDir): string;

    /**
     * Прочитать настройки. null — файла нет или содержимое не массив.
     *
     * @return array<string, mixed>|null
     */
    public function read(string $projectDir): ?array;

    /**
     * Записать настройки, сохранив ключи, которыми пакет не управляет.
     *
     * @param array<string, mixed> $settings
     */
    public function write(string $projectDir, array $settings): void;
}
