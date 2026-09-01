<?php

namespace Timbrs\DatabaseDumps\Service\ConfigGenerator;

use Timbrs\DatabaseDumps\Contract\FileSystemInterface;

/**
 * Защита готового конфига выгрузки от полной регенерации.
 *
 * Режим `all` собирает dump_config.yaml заново по живой схеме и существующий файл
 * не читает вовсе: настроенные руками sample.criteria, cascade_from, limit и faker
 * заменяются машинной догадкой, а восстановить их можно только из git. Остальные
 * режимы (`new`, `schema=`, `table=`) мёржат сгенерированное в существующий конфиг —
 * на них и указываем вместо отказа.
 *
 * Осознанная пересборка с нуля остаётся доступной через `--force`.
 */
class RegenerationGuard
{
    /** @var FileSystemInterface */
    private $fileSystem;

    public function __construct(FileSystemInterface $fileSystem)
    {
        $this->fileSystem = $fileSystem;
    }

    /**
     * Отказать ли в запуске: полная регенерация поверх существующего конфига.
     */
    public function blocks(string $mode, string $configPath, bool $force): bool
    {
        if ($force || $mode !== ConfigGenerator::MODE_ALL) {
            return false;
        }

        return $this->fileSystem->exists($configPath);
    }

    /**
     * Текст отказа: что произошло и чем адаптировать конфиг вместо перезаписи.
     *
     * @param string $commandPrefix Префикс команд бриджа: `app:dbdump:` или `dbdump:`
     *
     * @return array<int, string>
     */
    public function getRefusalLines(string $configPath, string $commandPrefix): array
    {
        return [
            'Конфиг ' . $configPath . ' уже существует, а режим all собирает его заново:',
            'настроенные вручную sample.criteria, cascade_from, limit и faker будут потеряны.',
            '',
            'Адаптировать существующий конфиг под изменившуюся схему:',
            '  ' . $commandPrefix . 'prepare-config new             дописать только новые таблицы',
            '  ' . $commandPrefix . 'prepare-config schema=<name>   перегенерировать схему, мёрж в конфиг',
            '  ' . $commandPrefix . 'prepare-config table=<s.t>     перегенерировать таблицу, мёрж в конфиг',
            '  ' . $commandPrefix . 'repair-configs                 прогнать sample.criteria в БД и починить падающие',
            '  ' . $commandPrefix . 'validate                       аудит конфига по слепку схемы, без подключения к БД',
            '',
            'Если конфиг действительно нужно собрать с нуля: '
                . $commandPrefix . 'prepare-config all --force',
            'Перед этим убедитесь, что текущий конфиг и dump-settings/ зафиксированы в git.',
        ];
    }
}
