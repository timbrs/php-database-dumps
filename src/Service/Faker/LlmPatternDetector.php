<?php

namespace Timbrs\DatabaseDumps\Service\Faker;

use Timbrs\DatabaseDumps\Contract\AiClientInterface;
use Timbrs\DatabaseDumps\Contract\ConnectionRegistryInterface;
use Timbrs\DatabaseDumps\Contract\LoggerInterface;
use Timbrs\DatabaseDumps\Platform\PlatformFactory;

/**
 * Детектор ПД через LLM (точнее regex на нестандартных данных).
 *
 * Та же сигнатура detect(schema, table, conn): array<col, pattern>, что у
 * PatternDetector, но классификация выполняется LLM. Результаты regex-детектора
 * подаются в промпт как hints (как RegexHints в go-defaker). LLM-тип данных
 * (pii_type) маппится на существующие паттерны, ограниченные
 * PatternDetector::ALLOWED_PATTERNS (иначе FakerConfig отвергнет конфиг).
 *
 * При недоступности LLM или ошибке — тихий fallback на regex-детектор + warning.
 */
class LlmPatternDetector
{
    public const DEFAULT_CONFIDENCE_THRESHOLD = 70;
    public const DEFAULT_SAMPLE_SIZE = 50;

    /** Сколько примеров «первых»/«последних» значений колонки слать в промпт. */
    private const SAMPLES_PER_SIDE = 10;

    /**
     * Лимиты на ответ LLM — защита pipeline от подменённого/раздутого ответа
     * (паритет со строгой валидацией схемы в go-defaker/internal/detector/llm.go).
     */
    private const MAX_LLM_COLUMNS = 1000;
    private const MAX_COLUMN_NAME_LEN = 128;

    /**
     * Маппинг LLM pii_type → паттерн PatternDetector.
     * Неизвестные/неподдерживаемые типы (inn, snils, passport, company, mixed, none…) → отбрасываются.
     *
     * @var array<string, string>
     */
    private const TYPE_MAP = [
        'fio' => PatternDetector::PATTERN_FIO,
        'fio_short' => PatternDetector::PATTERN_FIO_SHORT,
        'name' => PatternDetector::PATTERN_NAME,
        'firstname' => PatternDetector::PATTERN_FIRSTNAME,
        'lastname' => PatternDetector::PATTERN_LASTNAME,
        'surname' => PatternDetector::PATTERN_LASTNAME,
        'patronymic' => PatternDetector::PATTERN_PATRONYMIC,
        'email' => PatternDetector::PATTERN_EMAIL,
        'phone' => PatternDetector::PATTERN_PHONE,
        'gender' => PatternDetector::PATTERN_GENDER,
    ];

    /** @var AiClientInterface */
    private $aiClient;

    /** @var PatternDetector */
    private $regexDetector;

    /** @var ConnectionRegistryInterface */
    private $registry;

    /** @var LoggerInterface|null */
    private $logger;

    /** @var int */
    private $confidenceThreshold;

    /** @var int */
    private $sampleSize;

    /**
     * @param int $confidenceThreshold Порог уверенности (0-100) для принятия классификации LLM
     * @param int $sampleSize Размер случайной выборки строк для примеров значений
     */
    public function __construct(
        AiClientInterface $aiClient,
        PatternDetector $regexDetector,
        ConnectionRegistryInterface $registry,
        LoggerInterface $logger = null,
        $confidenceThreshold = self::DEFAULT_CONFIDENCE_THRESHOLD,
        $sampleSize = self::DEFAULT_SAMPLE_SIZE
    ) {
        $this->aiClient = $aiClient;
        $this->regexDetector = $regexDetector;
        $this->registry = $registry;
        $this->logger = $logger;
        $this->confidenceThreshold = (int) $confidenceThreshold;
        $this->sampleSize = (int) $sampleSize;
    }

    public function isAvailable(): bool
    {
        return $this->aiClient->isAvailable();
    }

    /**
     * Заменить LLM-клиент (например, после интерактивной настройки в том же запуске).
     */
    public function setAiClient(AiClientInterface $aiClient): void
    {
        $this->aiClient = $aiClient;
    }

    /**
     * Классифицировать колонки таблицы через LLM.
     *
     * @return array<string, string> column_name => pattern_type
     */
    public function detect(string $schema, string $table, ?string $connectionName = null): array
    {
        // regex-результаты — hints для промпта и fallback при сбое LLM.
        $regexHints = $this->regexDetector->detect($schema, $table, $connectionName);

        if (!$this->aiClient->isAvailable()) {
            $this->warn('LLM недоступен — используется regex-детектор для ' . $schema . '.' . $table);
            return $regexHints;
        }

        try {
            $this->info(sprintf('  выборка примеров из %s.%s (до %d строк)…', $schema, $table, $this->sampleSize));
            $samples = $this->fetchColumnSamples($schema, $table, $connectionName);
            if (empty($samples)) {
                return $regexHints;
            }

            $messages = [
                ['role' => 'system', 'content' => $this->buildSystemPrompt()],
                ['role' => 'user', 'content' => $this->buildUserPrompt($schema, $table, $samples, $regexHints)],
            ];

            $this->info(sprintf('  LLM-классификация %s.%s (%d колонок)…', $schema, $table, count($samples)));
            $response = $this->aiClient->chatJson($messages, 0.1);
            return $this->mapResponse($response);
        } catch (\Throwable $e) {
            $this->warn(sprintf(
                'LLM-детекция для %s.%s не удалась (%s) — fallback на regex.',
                $schema,
                $table,
                $e->getMessage()
            ));
            return $regexHints;
        }
    }

    /**
     * Замапить ответ LLM на разрешённые паттерны с учётом порога уверенности.
     *
     * @param array<mixed> $response
     * @return array<string, string>
     */
    private function mapResponse(array $response): array
    {
        $detected = [];
        $columns = $response['columns'] ?? null;
        if (!is_array($columns)) {
            return $detected;
        }

        // Строгая валидация схемы: обрезаем заведомо раздутый ответ (анти-DoS),
        // как maxLLMColumns в go-defaker.
        if (count($columns) > self::MAX_LLM_COLUMNS) {
            $columns = array_slice($columns, 0, self::MAX_LLM_COLUMNS);
        }

        foreach ($columns as $col) {
            if (!is_array($col)) {
                continue;
            }
            $name = isset($col['column_name']) ? (string) $col['column_name'] : '';
            // Клампим длину имени колонки — не тащим в FakerConfig произвольно длинные строки.
            if (strlen($name) > self::MAX_COLUMN_NAME_LEN) {
                $name = substr($name, 0, self::MAX_COLUMN_NAME_LEN);
            }
            $piiType = isset($col['pii_type']) ? strtolower(trim((string) $col['pii_type'])) : 'none';
            // Клампим уверенность в диапазон [0, 100] до сравнения с порогом.
            $confidence = isset($col['confidence']) ? (int) $col['confidence'] : 0;
            $confidence = $this->clampConfidence($confidence);

            if ($name === '' || $piiType === 'none' || $piiType === '') {
                continue;
            }
            if ($confidence < $this->confidenceThreshold) {
                continue;
            }
            if (!isset(self::TYPE_MAP[$piiType])) {
                // Тип ПД, который наш фейкер не умеет маскировать (inn/snils/passport/…) — пропускаем.
                continue;
            }
            $detected[$name] = self::TYPE_MAP[$piiType];
        }

        return $detected;
    }

    /**
     * Ограничить уверенность диапазоном [0, 100].
     */
    private function clampConfidence(int $confidence): int
    {
        if ($confidence < 0) {
            return 0;
        }
        if ($confidence > 100) {
            return 100;
        }
        return $confidence;
    }

    /**
     * Собрать примеры значений по колонкам (первые/последние N непустых).
     *
     * @return array<string, array{first: array<int, string>, last: array<int, string>}>
     */
    private function fetchColumnSamples(string $schema, string $table, ?string $connectionName): array
    {
        $connection = $this->registry->getConnection($connectionName);
        $platform = $this->registry->getPlatform($connectionName);

        $fullTable = $platform->getFullTableName($schema, $table);
        $randomFunc = $platform->getRandomFunctionSql();
        $platformName = PlatformFactory::canonicalize($connection->getPlatformName());

        if ($platformName === PlatformFactory::ORACLE) {
            $sql = "SELECT * FROM {$fullTable} ORDER BY {$randomFunc} FETCH FIRST {$this->sampleSize} ROWS ONLY";
        } else {
            $sql = "SELECT * FROM {$fullTable} ORDER BY {$randomFunc} LIMIT {$this->sampleSize}";
        }

        $rows = $connection->fetchAllAssociative($sql);
        if (empty($rows)) {
            return [];
        }

        $columns = array_keys($rows[0]);
        $samples = [];
        foreach ($columns as $column) {
            $values = [];
            foreach ($rows as $row) {
                if (!array_key_exists($column, $row)) {
                    continue;
                }
                $v = $row[$column];
                if ($v === null || $v === '') {
                    continue;
                }
                $values[] = (string) $v;
            }
            $values = array_values(array_unique($values));
            $samples[(string) $column] = [
                'first' => array_slice($values, 0, self::SAMPLES_PER_SIDE),
                'last' => array_slice($values, -self::SAMPLES_PER_SIDE),
            ];
        }

        return $samples;
    }

    /**
     * @param array<string, array{first: array<int, string>, last: array<int, string>}> $samples
     * @param array<string, string> $regexHints
     */
    private function buildUserPrompt(string $schema, string $table, array $samples, array $regexHints): string
    {
        $out = "Таблица: {$schema}.{$table}\n\n";
        foreach ($samples as $column => $sides) {
            $out .= "Колонка \"{$column}\"\n";
            if (!empty($sides['first'])) {
                $out .= 'Примеры (первые): ' . implode(', ', $sides['first']) . "\n";
            }
            if (!empty($sides['last'])) {
                $out .= 'Примеры (последние): ' . implode(', ', $sides['last']) . "\n";
            }
            if (isset($regexHints[$column])) {
                $out .= "Предварительный анализ (regex): тип \"{$regexHints[$column]}\"\n";
            }
            $out .= "\n";
        }

        $out .= 'Классифицируй каждую колонку. Верни ТОЛЬКО JSON: '
            . '{"columns": [{"column_name": "...", "pii_type": "...|none", "format": "...", '
            . '"confidence": 0-100, "reasoning": "...", "fake_value": "..."}]}';

        return $out;
    }

    private function buildSystemPrompt(): string
    {
        $template = $this->loadResource('pii_system_prompt.txt');
        $classification = $this->loadResource('pii_classification.txt');

        if ($template === null) {
            // Минимальный встроенный промпт, если ресурс недоступен.
            return 'Ты — эксперт по классификации персональных данных. Верни JSON '
                . '{"columns": [{"column_name": "...", "pii_type": "...|none", "confidence": 0-100}]}.';
        }

        return str_replace('{CLASSIFICATION}', $classification ?? '', $template);
    }

    /**
     * Загрузить файл-ресурс из src/Resources/prompts. Вынесено для переопределения в тестах.
     *
     * @return string|null
     */
    protected function loadResource(string $name)
    {
        $path = dirname(__DIR__, 2) . '/Resources/prompts/' . $name;
        if (!is_file($path)) {
            return null;
        }
        $content = @file_get_contents($path);
        return $content === false ? null : $content;
    }

    private function warn(string $message): void
    {
        if ($this->logger !== null) {
            $this->logger->warning($message);
        }
    }

    private function info(string $message): void
    {
        if ($this->logger !== null) {
            $this->logger->info($message);
        }
    }
}
