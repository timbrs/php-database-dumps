<?php

use Timbrs\DatabaseDumps\Service\ConfigGenerator\ServiceTableFilter;

return [
    // Главный конфиг выгрузки лежит внутри data_dir — рядом с dump-settings/, dumps/ и analysis/.
    'config_path' => base_path(env('DBDUMP_DATA_DIR', 'docker/database') . '/dump_config.yaml'),
    'project_dir' => base_path(),

    /*
    |--------------------------------------------------------------------------
    | Базовый каталог данных
    |--------------------------------------------------------------------------
    |
    | От него считаются главный конфиг ({data_dir}/dump_config.yaml), пер-схемные
    | файлы ({data_dir}/dump-settings), дампы ({data_dir}/dumps), анализ
    | ({data_dir}/analysis) и хуки ({data_dir}/before_exec, {data_dir}/after_exec).
    | По умолчанию 'docker/database'; можно задать, например, 'database'.
    */
    'data_dir' => env('DBDUMP_DATA_DIR', 'docker/database'),

    /*
    |--------------------------------------------------------------------------
    | LLM (прямой клиент для анализа данных)
    |--------------------------------------------------------------------------
    |
    | Используется командами prepare-config --ai / prepare-analysis для
    | классификации ПД и подсказок по выборке. Если url пуст — AI-функции
    | мягко деградируют на regex-эвристики (NullAiClient).
    |
    | OpenAI-совместимый endpoint, например https://llm.example.com/v1
    |
    | Проще всего настроить интерактивно: `php artisan dbdump:configure-llm`
    | (сохранит URL/модель в этот файл). Токен НЕ хранится здесь — держите его
    | в .env.local: DBDUMP_LLM_TOKEN=... (env перекрывает значения ниже).
    */
    'llm' => [
        'url' => env('DBDUMP_LLM_URL', ''),
        'model' => env('DBDUMP_LLM_MODEL', 'openai/gpt-oss-120b'),
        'timeout' => (int) env('DBDUMP_LLM_TIMEOUT', 120),
        // null = auto (включено, если задан url)
        'enabled' => env('DBDUMP_LLM_ENABLED'),
        // Проверять TLS-сертификат сервера LLM. Отключайте (false) ТОЛЬКО для внутренних
        // эндпоинтов с корпоративным CA, которого нет в доверенном хранилище PHP-curl
        // (ошибка «SSL certificate problem: unable to get local issuer certificate»).
        'verify_ssl' => env('DBDUMP_LLM_VERIFY_SSL', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Бережный доступ к БД
    |--------------------------------------------------------------------------
    |
    | Таймауты сессии по профилю (analyze — разведка, export — выгрузка, import —
    | заливка без ограничений), бюджет запросов разведки и порог, выше которого
    | таблицу не сканируют целиком (COUNT(*), DISTINCT, ORDER BY RAND()).
    | Времена — в миллисекундах; 0 — без ограничения.
    */
    'db' => [
        'analyze_statement_timeout' => (int) env('DBDUMP_DB_ANALYZE_STATEMENT_TIMEOUT', 15000),
        'export_statement_timeout' => (int) env('DBDUMP_DB_EXPORT_STATEMENT_TIMEOUT', 1800000),
        'lock_timeout' => (int) env('DBDUMP_DB_LOCK_TIMEOUT', 2000),
        'idle_in_transaction_session_timeout' => (int) env('DBDUMP_DB_IDLE_IN_TRANSACTION_SESSION_TIMEOUT', 60000),
        'query_budget' => (int) env('DBDUMP_DB_QUERY_BUDGET', 2000),
        'max_scan_rows' => (int) env('DBDUMP_DB_MAX_SCAN_ROWS', 50000),
    ],

    /*
    |--------------------------------------------------------------------------
    | Служебные таблицы фреймворков
    |--------------------------------------------------------------------------
    |
    | Не попадают в конфиг выгрузки. Список задаётся целиком: доменное имя может
    | совпасть со служебным (так из инвентаря выпала бизнес-таблица jobs).
    | exact — полное совпадение, prefix — начало имени, segments — слово в имени
    | через подчёркивание.
    */
    'service_tables' => [
        'exact' => ServiceTableFilter::DEFAULT_EXACT,
        'prefix' => ServiceTableFilter::DEFAULT_PREFIXES,
        'segments' => ServiceTableFilter::DEFAULT_SEGMENTS,
    ],

];
