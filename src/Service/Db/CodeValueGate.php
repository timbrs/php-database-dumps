<?php

namespace Timbrs\DatabaseDumps\Service\Db;

use Timbrs\DatabaseDumps\Service\Faker\PatternDetector;

/**
 * Шлюз «кодов»: единственный случай, когда значения из БД попадают в инвентарь для агента.
 *
 * Инвентарь строится без значений данных, чтобы персональные данные не ушли наружу. Но коды
 * статусов и справочников (1, -4, RED, closed) — не ПД, а именно то, по чему сверяют enum из
 * кода с тем, что реально лежит в базе. Пропускается только то, что по форме является кодом:
 *  - различных значений немного (≤ MAX_DISTINCT) и они повторяются (иначе это ключ или ИНН);
 *  - тип — не дата, не время, не документ/бинарь;
 *  - имя колонки не намекает ни на ПД, ни на идентификатор человека или организации;
 *  - каждое значение — короткий ASCII-идентификатор без пробелов и кириллицы.
 * Любое несоответствие — колонка кодов не получает целиком: частичный список вводил бы
 * в заблуждение.
 */
class CodeValueGate
{
    public const MAX_DISTINCT = 50;
    public const MAX_LENGTH = 32;

    private const VALUE_REGEX = '/^[A-Za-z0-9_.\-]{1,32}$/';

    private const NAME_EXCLUDE = '/(^|_)(inn|ogrn|ogrnip|kpp|snils|passport|document|doc|account|acc|iban|bik|card|okpo|okato|oktmo|login|password|passwd|token|secret|hash|salt|ip|address|addr|birth|born)(_|$)|phone|mobile|email|mail|surname|patronym|fio/iu';

    private const TYPE_EXCLUDE = '/date|time|json|xml|bytea|blob|clob|raw|binary|uuid/i';

    /**
     * @param array<int, string|null> $values кандидаты (например, most_common_vals)
     * @return array<int, string>|null null — колонка не является колонкой кодов
     */
    public static function filter(string $column, string $dataType, ?int $distinct, ?int $nonNullRows, array $values): ?array
    {
        if ($distinct === null || $distinct < 1 || $distinct > self::MAX_DISTINCT) {
            return null;
        }
        if ($nonNullRows !== null && $distinct >= $nonNullRows) {
            return null;
        }
        if (preg_match(self::TYPE_EXCLUDE, $dataType) === 1) {
            return null;
        }
        if (preg_match(self::NAME_EXCLUDE, $column) === 1 || PatternDetector::hintsPii($column)) {
            return null;
        }

        // Список, а не ключи массива: PHP превратил бы '1' в int 1, а коды — строки.
        $out = [];
        $seen = [];
        foreach ($values as $value) {
            if ($value === null) {
                continue;
            }
            $s = (string) $value;
            if (preg_match(self::VALUE_REGEX, $s) !== 1) {
                return null;
            }
            if (isset($seen[$s])) {
                continue;
            }
            $seen[$s] = true;
            $out[] = $s;
        }
        if ($out === []) {
            return null;
        }

        return $out;
    }
}
