<?php

namespace Timbrs\DatabaseDumps\Service\Generator;

use Timbrs\DatabaseDumps\Contract\ConnectionRegistryInterface;

/**
 * Генерация сброса sequences / auto-increment
 */
class SequenceGenerator
{
    /** @var ConnectionRegistryInterface */
    private $registry;

    public function __construct(ConnectionRegistryInterface $registry)
    {
        $this->registry = $registry;
    }

    /**
     * Сгенерировать statements для сброса sequences / auto-increment
     */
    public function generate(string $schema, string $table, ?string $connectionName = null): string
    {
        $connection = $this->registry->getConnection($connectionName);
        $platform = $this->registry->getPlatform($connectionName);

        return $platform->getSequenceResetSql($schema, $table, $connection);
    }
}
