<?php

namespace Timbrs\DatabaseDumps\Service\Analysis\CodeHints;

/**
 * Короткое имя класса → FQCN по namespace файла и его use-импортам (включая `as`).
 *
 * Нужен там, где enum привязывается к колонке: в хосте несколько одноимённых `TypeEnum`
 * в разных доменах, и ключ по короткому имени сливает их значения в одну кучу — колонка
 * получает чужие case'ы, а R9 «в enum есть, в БД нет» начинает врать.
 *
 * PHP 7.2-совместимо.
 */
class UseStatementResolver
{
    /**
     * Карта импортов файла: короткое имя (или алиас) => FQCN, плюс namespace самого файла
     * под ключом '' (пустая строка).
     *
     * @return array<string, string>
     */
    public static function imports(string $content): array
    {
        $map = [];
        if (preg_match('/^\s*namespace\s+([A-Za-z_\x{0080}-\x{FFFF}][A-Za-z0-9_\\\\\x{0080}-\x{FFFF}]*)\s*;/mu', $content, $nm) === 1) {
            $map[''] = trim($nm[1], '\\');
        }

        if (preg_match_all('/^\s*use\s+(?!function\b|const\b)([A-Za-z_\x{0080}-\x{FFFF}][A-Za-z0-9_\\\\\x{0080}-\x{FFFF}]*)(?:\s+as\s+([A-Za-z_][A-Za-z0-9_]*))?\s*;/mu', $content, $matches, PREG_SET_ORDER) === 0) {
            return $map;
        }
        foreach ($matches as $match) {
            $fqcn = trim($match[1], '\\');
            $alias = isset($match[2]) && $match[2] !== '' ? $match[2] : self::shortName($fqcn);
            $map[$alias] = $fqcn;
        }

        return $map;
    }

    /**
     * FQCN для имени, как оно записано в коде: с ведущим `\` — как есть; известный алиас —
     * из импортов; иначе — namespace файла плюс имя (то же правило, что у PHP).
     *
     * @param array<string, string> $imports
     */
    public static function resolve(string $name, array $imports): string
    {
        $name = trim($name);
        if ($name === '') {
            return '';
        }
        if ($name[0] === '\\') {
            return trim($name, '\\');
        }

        $parts = explode('\\', $name);
        $head = $parts[0];
        if (isset($imports[$head])) {
            $rest = count($parts) > 1 ? '\\' . implode('\\', array_slice($parts, 1)) : '';

            return $imports[$head] . $rest;
        }

        $namespace = isset($imports['']) ? $imports[''] : '';

        return $namespace === '' ? $name : $namespace . '\\' . $name;
    }

    public static function shortName(string $fqcn): string
    {
        $pos = strrpos($fqcn, '\\');

        return $pos === false ? $fqcn : substr($fqcn, $pos + 1);
    }
}
