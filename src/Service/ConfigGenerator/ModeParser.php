<?php

namespace Timbrs\DatabaseDumps\Service\ConfigGenerator;

/**
 * Парсинг аргумента mode для команды prepare-config.
 *
 * Используется обеими bridge-командами (Symfony и Laravel) для устранения
 * дублирования и единого поведения.
 */
class ModeParser
{
    /**
     * @return array{mode: string, scope: string|null}|null
     */
    public function parse(string $arg): ?array
    {
        if ($arg === 'all') {
            return ['mode' => ConfigGenerator::MODE_ALL, 'scope' => null];
        }

        if ($arg === 'new') {
            return ['mode' => ConfigGenerator::MODE_NEW, 'scope' => null];
        }

        if (strpos($arg, 'schema=') === 0) {
            $scope = substr($arg, 7);
            if ($scope === '' || $scope === false) {
                return null;
            }
            return ['mode' => ConfigGenerator::MODE_SCHEMA, 'scope' => $scope];
        }

        if (strpos($arg, 'table=') === 0) {
            $scope = substr($arg, 6);
            if ($scope === '' || $scope === false || strpos($scope, '.') === false) {
                return null;
            }
            return ['mode' => ConfigGenerator::MODE_TABLE, 'scope' => $scope];
        }

        return null;
    }

    /**
     * Подсказка по доступным режимам (для error-сообщений).
     *
     * @return array<int, string>
     */
    public function getUsageLines(): array
    {
        return [
            'Доступные режимы:',
            '  all              Полная регенерация конфигурации',
            '  schema=<name>    Перегенерация одной схемы, мёрж в существующий конфиг',
            '  table=<s.t>      Перегенерация одной таблицы, мёрж в существующий конфиг',
            '  new              Обнаружение и дописывание новых таблиц',
        ];
    }
}
