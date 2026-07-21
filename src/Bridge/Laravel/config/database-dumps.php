<?php

return [
    'config_path' => base_path('database/dump_config.yaml'),
    'project_dir' => base_path(),

    /*
    |--------------------------------------------------------------------------
    | Базовый каталог данных
    |--------------------------------------------------------------------------
    |
    | От него считаются дампы ({data_dir}/dumps), анализ ({data_dir}/analysis)
    | и хуки ({data_dir}/before_exec, {data_dir}/after_exec). По умолчанию
    | 'database'; можно задать, например, 'var/database'.
    */
    'data_dir' => env('DBDUMP_DATA_DIR', 'database'),

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
    | OPENCODE (внешний агент-картограф кода)
    |--------------------------------------------------------------------------
    |
    | Команда prepare-analysis --run запускает opencode по чанку на схему. Имя
    | бинаря может отличаться (напр. 'opencode-cli' — симлинк/обёртка над
    | sst/opencode). Задайте своё через DBDUMP_OPENCODE_BIN.
    */
    'opencode' => [
        'bin' => env('DBDUMP_OPENCODE_BIN', 'opencode'),
    ],
];
