<?php

namespace Timbrs\DatabaseDumps\Bridge\Symfony;

use Timbrs\DatabaseDumps\Bridge\Symfony\DependencyInjection\DatabaseDumpsExtension;
use Symfony\Component\HttpKernel\Bundle\Bundle;

/**
 * Symfony Bundle для автоматической регистрации
 */
class DatabaseDumpsBundle extends Bundle
{
    public function getContainerExtension(): DatabaseDumpsExtension
    {
        return new DatabaseDumpsExtension();
    }
}
