<?php

namespace Timbrs\DatabaseDumps\Service\Validation;

/**
 * Реестр кодов находок — единый источник для команды check и для документации (docs).
 *
 * У каждого кода: стадия, где он возникает; уровень; смысл; чинится ли механически
 * (--fix) и кто принимает решение — инструмент, агент по коду или человек. Делать это
 * порознь в командах и в README значило бы два прохода по одним кодам и расхождения.
 */
class FindingCatalog
{
    public const STAGE_STATIC = 'static';
    public const STAGE_LIVE = 'live';
    public const STAGE_PLAN = 'plan';
    public const STAGE_DUMP = 'dump';
    public const STAGE_IMPORT = 'import';

    public const DECIDES_TOOL = 'tool';
    public const DECIDES_AGENT = 'agent';
    public const DECIDES_HUMAN = 'human';

    /** @var array<string, string> */
    private const STAGE_TITLES = [
        self::STAGE_STATIC => 'static — конфиг против слепка схемы, без БД',
        self::STAGE_LIVE => 'live — критерии и корзины на живой БД (под таймаутом)',
        self::STAGE_PLAN => 'plan — план выгрузки: режимы, корзины, порядок',
        self::STAGE_DUMP => 'dump — выгруженные файлы',
        self::STAGE_IMPORT => 'import — контрольная заливка в scratch-БД',
    ];

    /**
     * @var array<string, array{stage: string, severity: string, title: string, fixable: bool, decides: string}>
     */
    private const CODES = [
        'S-1' => ['stage' => 'static', 'severity' => 'error', 'title' => 'YAML не разобрался или файл из includes: пропал', 'fixable' => false, 'decides' => 'human'],
        'S-2' => ['stage' => 'static', 'severity' => 'error', 'title' => 'TableConfig отверг настройки таблицы', 'fixable' => false, 'decides' => 'human'],
        'S-3' => ['stage' => 'static', 'severity' => 'error', 'title' => 'таблица и в full_export, и в partial_export', 'fixable' => false, 'decides' => 'human'],
        'S-4' => ['stage' => 'static', 'severity' => 'warning', 'title' => 'пустая секция или пустая карта faker у таблицы', 'fixable' => true, 'decides' => 'tool'],
        'C-1' => ['stage' => 'static', 'severity' => 'warning', 'title' => 'таблица есть в слепке, но не выгружается', 'fixable' => false, 'decides' => 'agent'],
        'C-2' => ['stage' => 'static', 'severity' => 'warning', 'title' => 'таблица есть в конфиге, но её нет в слепке', 'fixable' => false, 'decides' => 'agent'],
        'C-3' => ['stage' => 'static', 'severity' => 'warning', 'title' => 'схема есть на одной стороне и отсутствует на другой', 'fixable' => false, 'decides' => 'agent'],
        'L-1' => ['stage' => 'static', 'severity' => 'error', 'title' => 'несуществующая колонка в order_by', 'fixable' => false, 'decides' => 'agent'],
        'L-2' => ['stage' => 'static', 'severity' => 'error', 'title' => 'несуществующая колонка в where', 'fixable' => false, 'decides' => 'agent'],
        'L-3' => ['stage' => 'static', 'severity' => 'error', 'title' => 'несуществующая колонка в cascade_from', 'fixable' => true, 'decides' => 'tool'],
        'L-4' => ['stage' => 'static', 'severity' => 'error', 'title' => 'несуществующая колонка в sample.criteria (в подзапросе — предупреждение)', 'fixable' => false, 'decides' => 'agent'],
        'L-5' => ['stage' => 'static', 'severity' => 'error', 'title' => 'несуществующая колонка или таблица в stratify_by / stratify / stratify_via', 'fixable' => false, 'decides' => 'agent'],
        'L-6' => ['stage' => 'static', 'severity' => 'warning', 'title' => 'faker на несуществующей колонке', 'fixable' => true, 'decides' => 'tool'],
        'L-7' => ['stage' => 'static', 'severity' => 'error', 'title' => 'несуществующая колонка в deferred_columns', 'fixable' => false, 'decides' => 'agent'],
        'Q-1' => ['stage' => 'static', 'severity' => 'error', 'title' => 'в критерии алиас таблицы (t1.) — дампер такой критерий пропустит', 'fixable' => false, 'decides' => 'agent'],
        'Q-2' => ['stage' => 'static', 'severity' => 'error', 'title' => 'в критерии bind-параметр (:name) — дампер такой критерий пропустит', 'fixable' => false, 'decides' => 'agent'],
        'Q-3' => ['stage' => 'static', 'severity' => 'error', 'title' => 'непригодны ВСЕ критерии таблицы: выборка выродится в плоский срез', 'fixable' => false, 'decides' => 'agent'],
        'Q-4' => ['stage' => 'static', 'severity' => 'warning', 'title' => 'повторяющиеся имена критериев', 'fixable' => true, 'decides' => 'tool'],
        'Q-5' => ['stage' => 'static', 'severity' => 'warning', 'title' => 'сумма квот больше limit — корзины сольются round-robin, квоты ужмутся', 'fixable' => false, 'decides' => 'agent'],
        'Q-6' => ['stage' => 'live', 'severity' => 'warning', 'title' => 'корзина не ловит ни одной строки в БД — вида данных в дампе не будет', 'fixable' => false, 'decides' => 'agent'],
        'Q-7' => ['stage' => 'live', 'severity' => 'error', 'title' => 'критерий падает в БД (текст ошибки СУБД в подсказке)', 'fixable' => false, 'decides' => 'agent'],
        'Q-8' => ['stage' => 'live', 'severity' => 'warning', 'title' => 'критерий не уложился в statement_timeout — на экспорте будет так же медленно', 'fixable' => false, 'decides' => 'agent'],
        'F-1' => ['stage' => 'static', 'severity' => 'error', 'title' => 'faker-паттерн на нетекстовой колонке (на числовой — предупреждение)', 'fixable' => true, 'decides' => 'tool'],
        'F-2' => ['stage' => 'static', 'severity' => 'error', 'title' => 'паттерн вне PatternDetector::ALLOWED_PATTERNS', 'fixable' => true, 'decides' => 'tool'],
        'G-1' => ['stage' => 'static', 'severity' => 'error', 'title' => 'родитель cascade_from не выгружается — ограничение молча отбросится', 'fixable' => false, 'decides' => 'agent'],
        'G-2' => ['stage' => 'static', 'severity' => 'error', 'title' => 'цикл в cascade_from', 'fixable' => false, 'decides' => 'human'],
        'G-3' => ['stage' => 'static', 'severity' => 'warning', 'title' => 'цепочка длиннее settings.max_cascade_depth', 'fixable' => false, 'decides' => 'human'],
        'G-4' => ['stage' => 'static', 'severity' => 'warning', 'title' => 'родитель с sample выгружается позже ребёнка', 'fixable' => false, 'decides' => 'agent'],
        'G-5' => ['stage' => 'static', 'severity' => 'note', 'title' => 'родитель с sample выгружается раньше — связность держится на реестре', 'fixable' => false, 'decides' => 'tool'],
        'G-6' => ['stage' => 'static', 'severity' => 'note', 'title' => 'у таблицы и sample, и cascade_from — каскад входит в базовое условие корзин', 'fixable' => false, 'decides' => 'tool'],
        'D-1' => ['stage' => 'static', 'severity' => 'note', 'title' => 'справочник *_dict обрезан лимитом или квотами', 'fixable' => false, 'decides' => 'agent'],
        'H-1' => ['stage' => 'static', 'severity' => 'note', 'title' => 'версионная таблица выбирает только действующие версии', 'fixable' => false, 'decides' => 'agent'],
        'P-1' => ['stage' => 'live', 'severity' => 'warning', 'title' => 'размер таблицы неизвестен — статистика отсутствует, выполните ANALYZE', 'fixable' => false, 'decides' => 'human'],
        'P-2' => ['stage' => 'live', 'severity' => 'warning', 'title' => 'нет права SELECT на колонки — профиль неполный', 'fixable' => false, 'decides' => 'human'],
        'X-1' => ['stage' => 'static', 'severity' => 'error', 'title' => 'правило аудита не отработало (исключение внутри правила)', 'fixable' => false, 'decides' => 'human'],
        'V-1' => ['stage' => 'dump', 'severity' => 'error', 'title' => 'строки ребёнка ссылаются на родителя, которого нет в выгрузке', 'fixable' => false, 'decides' => 'agent'],
        'V-2' => ['stage' => 'dump', 'severity' => 'warning', 'title' => 'таблица есть в конфиге, файла дампа нет', 'fixable' => false, 'decides' => 'human'],
        'V-3' => ['stage' => 'dump', 'severity' => 'warning', 'title' => 'родитель связи не выгружен — сверять нечем', 'fixable' => false, 'decides' => 'agent'],
        'V-4' => ['stage' => 'dump', 'severity' => 'error', 'title' => 'колонки связи нет в дампе', 'fixable' => false, 'decides' => 'agent'],
        'V-5' => ['stage' => 'dump', 'severity' => 'warning', 'title' => 'покрытие значений: кодов/категорий в дампе меньше, чем в БД', 'fixable' => false, 'decides' => 'agent'],
        'V-7' => ['stage' => 'dump', 'severity' => 'error', 'title' => 'персональные данные в колонке без faker (по имени — предупреждение)', 'fixable' => false, 'decides' => 'agent'],
        'V-8' => ['stage' => 'dump', 'severity' => 'warning', 'title' => 'число строк расходится с limit, квотами или слепком (сверх limit — ошибка)', 'fixable' => false, 'decides' => 'agent'],
        'I-1' => ['stage' => 'import', 'severity' => 'error', 'title' => 'таблица пропущена при импорте: колонки дампа расходятся со схемой БД', 'fixable' => false, 'decides' => 'human'],
        'I-2' => ['stage' => 'import', 'severity' => 'error', 'title' => 'после заливки строк в таблице не столько, сколько в файле', 'fixable' => false, 'decides' => 'human'],
        'I-3' => ['stage' => 'import', 'severity' => 'warning', 'title' => 'sequence отстаёт от максимума колонки (PostgreSQL)', 'fixable' => false, 'decides' => 'human'],
        'I-4' => ['stage' => 'import', 'severity' => 'error', 'title' => 'внешний ключ нарушен — строки без родителя', 'fixable' => false, 'decides' => 'agent'],
    ];

    /**
     * @return array<string, array{stage: string, severity: string, title: string, fixable: bool, decides: string}>
     */
    public static function all(): array
    {
        return self::CODES;
    }

    /**
     * @return array{stage: string, severity: string, title: string, fixable: bool, decides: string}|null
     */
    public static function get(string $code): ?array
    {
        return self::CODES[$code] ?? null;
    }

    public static function has(string $code): bool
    {
        return isset(self::CODES[$code]);
    }

    /**
     * @return array<int, string>
     */
    public static function stages(): array
    {
        return array_keys(self::STAGE_TITLES);
    }

    public static function stageTitle(string $stage): string
    {
        return self::STAGE_TITLES[$stage] ?? $stage;
    }

    /**
     * Стадия, на которой возникает код; неизвестный код относится к static.
     */
    public static function stageOf(string $code): string
    {
        return self::CODES[$code]['stage'] ?? self::STAGE_STATIC;
    }

    /**
     * @return array<string, array{stage: string, severity: string, title: string, fixable: bool, decides: string}>
     */
    public static function byStage(string $stage): array
    {
        $result = [];
        foreach (self::CODES as $code => $entry) {
            if ($entry['stage'] === $stage) {
                $result[$code] = $entry;
            }
        }

        return $result;
    }

    /**
     * FINDINGS.md: таблицы кодов по стадиям.
     */
    public static function renderMarkdown(): string
    {
        $decides = [
            self::DECIDES_TOOL => 'инструмент',
            self::DECIDES_AGENT => 'агент по коду',
            self::DECIDES_HUMAN => 'человек',
        ];

        $lines = ['# Коды находок', ''];
        $lines[] = 'Один реестр для `check` и для этого файла: стадия, уровень, смысл, чинится ли `--fix` и кто решает.';
        $lines[] = 'Код возврата `check` — `1`, когда есть находки не ниже `--fail-on` (по умолчанию error).';
        $lines[] = '';

        foreach (self::STAGE_TITLES as $stage => $title) {
            $codes = self::byStage($stage);
            if ($codes === []) {
                continue;
            }
            $lines[] = '## ' . $title;
            $lines[] = '';
            $lines[] = '| код | уровень | что нашли | `--fix` | решает |';
            $lines[] = '|---|---|---|---|---|';
            foreach ($codes as $code => $entry) {
                $lines[] = sprintf(
                    '| `%s` | %s | %s | %s | %s |',
                    $code,
                    $entry['severity'],
                    $entry['title'],
                    $entry['fixable'] ? 'да' : '—',
                    $decides[$entry['decides']] ?? $entry['decides']
                );
            }
            $lines[] = '';
        }

        return implode("\n", $lines);
    }
}
