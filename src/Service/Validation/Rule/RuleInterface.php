<?php

namespace Timbrs\DatabaseDumps\Service\Validation\Rule;

use Timbrs\DatabaseDumps\Service\Validation\AuditContext;
use Timbrs\DatabaseDumps\Service\Validation\Finding;

/**
 * Группа проверок конфига выгрузки. Правило ничего не чинит и ни к чему не подключается —
 * получает готовый контекст и возвращает находки.
 */
interface RuleInterface
{
    /**
     * @return array<int, Finding>
     */
    public function apply(AuditContext $context): array;

    /**
     * Короткое имя группы для отчёта («структура», «покрытие», …).
     */
    public function name(): string;
}
