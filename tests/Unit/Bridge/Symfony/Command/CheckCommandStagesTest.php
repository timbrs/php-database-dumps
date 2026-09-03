<?php

namespace Timbrs\DatabaseDumps\Tests\Unit\Bridge\Symfony\Command;

use PHPUnit\Framework\TestCase;
use Timbrs\DatabaseDumps\Bridge\Symfony\Command\CheckCommand;

/**
 * Разбор `--stage`.
 *
 * Опция объявлена повторяемой, но в документации инструмента и в ранбуках она написана списком
 * через запятую — и в такой форме команда падала с «Неизвестная стадия: static,live,plan».
 * Инструкция, которая не работает, хуже отсутствующей, поэтому принимаются обе формы.
 */
class CheckCommandStagesTest extends TestCase
{
    /**
     * @param array<int, string> $raw
     * @param array<int, string> $expected
     *
     * @dataProvider forms
     */
    public function testStagesAreParsedFromBothForms(array $raw, array $expected): void
    {
        $command = (new \ReflectionClass(CheckCommand::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(CheckCommand::class, 'stages');
        $method->setAccessible(true);

        self::assertSame($expected, $method->invoke($command, $raw));
    }

    /** @return array<string, array{array<int, string>, array<int, string>}> */
    public static function forms(): array
    {
        return [
            'список через запятую' => [['static,live,plan'], ['static', 'live', 'plan']],
            'повторённая опция' => [['static', 'live'], ['static', 'live']],
            'смешанно и с пробелами' => [['static, live', 'plan'], ['static', 'live', 'plan']],
            'повторы схлопываются' => [['static,static', 'static'], ['static']],
            'пустое отбрасывается' => [['static,,'], ['static']],
            'ничего не задано' => [[], []],
        ];
    }
}
