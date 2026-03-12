<?php

namespace Timbrs\DatabaseDumps\Contract;

/**
 * Интерфейс фейкера для замены персональных данных
 */
interface FakerInterface
{
    /**
     * Применить фейкинг к строкам данных
     *
     * @param string $schema
     * @param string $table
     * @param array<string, string> $fakerConfig column_name => pattern_type
     * @param array<array<string, mixed>> $rows
     * @return array<array<string, mixed>> rows with faked values
     */
    public function apply(string $schema, string $table, array $fakerConfig, array $rows): array;
}
