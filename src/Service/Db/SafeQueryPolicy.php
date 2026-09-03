<?php

namespace Timbrs\DatabaseDumps\Service\Db;

use Timbrs\DatabaseDumps\Platform\PlatformFactory;

/**
 * Правила бережного обращения с БД: таймауты сессии, бюджет запросов, порог сканирования.
 *
 * Три профиля сессии — по тому, кто и зачем открыл соединение:
 *  - analyze (по умолчанию): разведка схемы и данных. Короткий statement_timeout, чтобы ни один
 *    DISTINCT или COUNT не ушёл в закат на боевой базе; на прогон отводится бюджет запросов.
 *  - export: выгрузка. Долгий statement_timeout — фаза 2 выборки читает таблицу целиком,
 *    а словарь в full_export за 15 секунд не прочитается; бюджет не считается.
 *  - import: заливка в scratch-базу. Без таймаутов — TRUNCATE и INSERT ждут столько,
 *    сколько надо.
 *
 * Профиль — состояние одного разделяемого экземпляра: его переключают DatabaseDumper и
 * DatabaseImporter, а ConnectionRegistry применяет SET при первом getConnection() и при смене
 * профиля. Регистрация подключений SET не делает: команды регистрируются лениво, и `list`
 * не должен открывать БД.
 *
 * Порог сканирования (max_scan_rows) проверяется на местах вызова — там, где известна оценка
 * размера таблицы, — а не на уровне соединения: соединение не знает, какой запрос дорогой.
 *
 * Значения приводятся к int: строка из YAML или env не должна попасть в SET как есть.
 *
 * Известное ограничение: pgbouncer в режиме transaction pooling не сохраняет сессионный SET
 * между транзакциями — там таймауты задаются на стороне пула.
 */
class SafeQueryPolicy
{
    public const PROFILE_ANALYZE = 'analyze';
    public const PROFILE_EXPORT = 'export';
    public const PROFILE_IMPORT = 'import';

    public const PROFILES = [self::PROFILE_ANALYZE, self::PROFILE_EXPORT, self::PROFILE_IMPORT];

    public const DEFAULT_ANALYZE_STATEMENT_TIMEOUT_MS = 15000;
    public const DEFAULT_EXPORT_STATEMENT_TIMEOUT_MS = 1800000;
    public const DEFAULT_LOCK_TIMEOUT_MS = 2000;
    public const DEFAULT_IDLE_IN_TRANSACTION_TIMEOUT_MS = 60000;
    public const DEFAULT_QUERY_BUDGET = 2000;
    public const DEFAULT_MAX_SCAN_ROWS = 50000;

    /** Ключи настроек (секция db в конфиге бандла / config/database-dumps.php). */
    public const KEY_ANALYZE_STATEMENT_TIMEOUT = 'analyze_statement_timeout';
    public const KEY_EXPORT_STATEMENT_TIMEOUT = 'export_statement_timeout';
    public const KEY_LOCK_TIMEOUT = 'lock_timeout';
    public const KEY_IDLE_IN_TRANSACTION_TIMEOUT = 'idle_in_transaction_session_timeout';
    public const KEY_QUERY_BUDGET = 'query_budget';
    public const KEY_MAX_SCAN_ROWS = 'max_scan_rows';

    /** @var int */
    private $analyzeStatementTimeoutMs;

    /** @var int */
    private $exportStatementTimeoutMs;

    /** @var int */
    private $lockTimeoutMs;

    /** @var int */
    private $idleInTransactionTimeoutMs;

    /** @var int */
    private $queryBudget;

    /** @var int */
    private $maxScanRows;

    /** @var string */
    private $profile = self::PROFILE_ANALYZE;

    /**
     * @param array<string, mixed> $settings секция db настроек; отсутствующие ключи — дефолты
     */
    public function __construct(array $settings = [])
    {
        $this->analyzeStatementTimeoutMs = self::intSetting($settings, self::KEY_ANALYZE_STATEMENT_TIMEOUT, self::DEFAULT_ANALYZE_STATEMENT_TIMEOUT_MS);
        $this->exportStatementTimeoutMs = self::intSetting($settings, self::KEY_EXPORT_STATEMENT_TIMEOUT, self::DEFAULT_EXPORT_STATEMENT_TIMEOUT_MS);
        $this->lockTimeoutMs = self::intSetting($settings, self::KEY_LOCK_TIMEOUT, self::DEFAULT_LOCK_TIMEOUT_MS);
        $this->idleInTransactionTimeoutMs = self::intSetting($settings, self::KEY_IDLE_IN_TRANSACTION_TIMEOUT, self::DEFAULT_IDLE_IN_TRANSACTION_TIMEOUT_MS);
        $this->queryBudget = self::intSetting($settings, self::KEY_QUERY_BUDGET, self::DEFAULT_QUERY_BUDGET);
        $this->maxScanRows = self::intSetting($settings, self::KEY_MAX_SCAN_ROWS, self::DEFAULT_MAX_SCAN_ROWS);
    }

    public static function defaults(): self
    {
        return new self();
    }

    public function getProfile(): string
    {
        return $this->profile;
    }

    public function setProfile(string $profile): void
    {
        if (!in_array($profile, self::PROFILES, true)) {
            throw new \InvalidArgumentException(sprintf(
                'Неизвестный профиль сессии "%s"; допустимы: %s',
                $profile,
                implode(', ', self::PROFILES)
            ));
        }
        $this->profile = $profile;
    }

    public function getAnalyzeStatementTimeoutMs(): int
    {
        return $this->analyzeStatementTimeoutMs;
    }

    public function getExportStatementTimeoutMs(): int
    {
        return $this->exportStatementTimeoutMs;
    }

    public function getLockTimeoutMs(): int
    {
        return $this->lockTimeoutMs;
    }

    public function getIdleInTransactionTimeoutMs(): int
    {
        return $this->idleInTransactionTimeoutMs;
    }

    public function getQueryBudget(): int
    {
        return $this->queryBudget;
    }

    public function getMaxScanRows(): int
    {
        return $this->maxScanRows;
    }

    /**
     * Разрешён ли полный проход по таблице такого размера (DISTINCT, COUNT(*), сортировка
     * всей таблицы). Неизвестный размер (null) — нет: не знаем, во что это обойдётся.
     */
    public function allowsScan(?int $estimatedRows): bool
    {
        return $estimatedRows !== null && $estimatedRows <= $this->maxScanRows;
    }

    /**
     * Укладывается ли запрос с таким порядковым номером в бюджет текущего профиля.
     * Считает только analyze; бюджет 0 — без ограничения.
     */
    public function allowsQuery(int $queryNumber): bool
    {
        if ($this->profile !== self::PROFILE_ANALYZE || $this->queryBudget <= 0) {
            return true;
        }

        return $queryNumber <= $this->queryBudget;
    }

    /**
     * SET-инструкции сессии для платформы и профиля. Каждый элемент — список альтернатив
     * (MySQL и MariaDB называют переменную таймаута по-разному): исполняется первая удавшаяся.
     * Oracle сессионных таймаутов такого рода не имеет — пустой список.
     *
     * @return array<int, array<int, string>>
     */
    public function sessionStatements(string $platformName, ?string $profile = null): array
    {
        $profile = $profile ?? $this->profile;
        $platform = PlatformFactory::canonicalize($platformName);

        $statementMs = $this->statementTimeoutFor($profile);
        $lockMs = $profile === self::PROFILE_IMPORT ? 0 : $this->lockTimeoutMs;
        $idleMs = $profile === self::PROFILE_IMPORT ? 0 : $this->idleInTransactionTimeoutMs;

        if ($platform === PlatformFactory::POSTGRESQL) {
            return [
                ['SET statement_timeout = ' . $statementMs],
                ['SET lock_timeout = ' . $lockMs],
                ['SET idle_in_transaction_session_timeout = ' . $idleMs],
            ];
        }

        if ($platform === PlatformFactory::MYSQL) {
            $statements = [
                [
                    // MySQL ≥ 5.7.8 — миллисекунды; MariaDB — max_statement_time в секундах.
                    'SET SESSION max_execution_time = ' . $statementMs,
                    'SET SESSION max_statement_time = ' . $this->msToSeconds($statementMs),
                ],
            ];
            if ($lockMs > 0) {
                $statements[] = ['SET SESSION innodb_lock_wait_timeout = ' . max(1, $this->msToSeconds($lockMs))];
            }
            return $statements;
        }

        return [];
    }

    private function statementTimeoutFor(string $profile): int
    {
        switch ($profile) {
            case self::PROFILE_EXPORT:
                return $this->exportStatementTimeoutMs;
            case self::PROFILE_IMPORT:
                return 0;
            default:
                return $this->analyzeStatementTimeoutMs;
        }
    }

    private function msToSeconds(int $ms): int
    {
        return (int) ceil($ms / 1000);
    }

    /**
     * @param array<string, mixed> $settings
     */
    private static function intSetting(array $settings, string $key, int $default): int
    {
        if (!array_key_exists($key, $settings) || $settings[$key] === null || $settings[$key] === '') {
            return $default;
        }
        $value = $settings[$key];
        if (!is_numeric($value)) {
            throw new \InvalidArgumentException(sprintf(
                'Настройка db.%s должна быть числом, получено "%s"',
                $key,
                is_scalar($value) ? (string) $value : gettype($value)
            ));
        }
        $int = (int) $value;
        if ($int < 0) {
            throw new \InvalidArgumentException(sprintf('Настройка db.%s не может быть отрицательной', $key));
        }

        return $int;
    }
}
