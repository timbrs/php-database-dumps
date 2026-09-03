<?php

namespace Timbrs\DatabaseDumps\Service\Analysis\CodeHints;

/**
 * Свойство сущности → колонка таблицы.
 *
 * Doctrine пишет имя колонки в атрибуте (`#[ORM\Column(name: 'job_type')]`), а когда не пишет —
 * действует конвенция snake_case от имени свойства. Обе половины нужны, чтобы связать
 * `->setJobType(JobTypeEnum::READY->value)` с колонкой `job_type`.
 *
 * PHP 7.2-совместимо.
 */
class EntityPropertyMap
{
    use TextHelperTrait;

    /**
     * Карта одного файла сущности: свойство => колонка. Учитывается и явное `name:`,
     * и конвенция; свойства без #[ORM\Column]/@ORM\Column не берутся — это не колонки.
     *
     * @param array<int, string> $lines
     * @return array<string, string>
     */
    public function build(array $lines): array
    {
        $map = [];
        $pendingName = null;
        $sawColumn = false;

        foreach ($lines as $line) {
            if (strpos($line, 'ORM\\Column') !== false || strpos($line, 'ORM\\JoinColumn') !== false) {
                $sawColumn = true;
                if (preg_match('/name\s*[:=]\s*[\'"]([^\'"]+)[\'"]/', $line, $nm) === 1) {
                    $pendingName = $nm[1];
                }
                continue;
            }
            if (!$sawColumn) {
                continue;
            }
            // Объявление свойства закрывает атрибут: private ?string $jobType = null;
            if (preg_match('/(?:private|protected|public)\s+(?:readonly\s+)?[^;$]*\$([A-Za-z_]\w*)/', $line, $pm) === 1) {
                $property = $pm[1];
                $map[$property] = $pendingName !== null ? $pendingName : $this->camelToSnake($property);
                $pendingName = null;
                $sawColumn = false;
            }
        }

        return $map;
    }

    /**
     * Колонка для setter'а/getter'а: setJobType → jobType → job_type (с учётом карты файла).
     *
     * @param array<string, string> $map
     */
    public function columnForAccessor(string $accessor, array $map): ?string
    {
        if ($accessor === '') {
            return null;
        }
        $property = lcfirst($accessor);
        if (isset($map[$property])) {
            return $map[$property];
        }
        $snake = $this->camelToSnake($property);

        return $snake === '' ? null : $snake;
    }
}
