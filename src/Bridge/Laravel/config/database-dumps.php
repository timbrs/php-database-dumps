<?php

return [
    'config_path' => base_path('database/dump_config.yaml'),
    'project_dir' => base_path(),

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
    | (сохранит URL/модель/token в database/dbdump_llm.json). Значения ниже
    | (через env) перекрывают сохранённый файл.
    */
    'llm' => [
        'url' => env('DBDUMP_LLM_URL', ''),
        'model' => env('DBDUMP_LLM_MODEL', 'openai/gpt-oss-120b'),
        'token' => env('DBDUMP_LLM_TOKEN'),
        'timeout' => (int) env('DBDUMP_LLM_TIMEOUT', 120),
        // null = auto (включено, если задан url)
        'enabled' => env('DBDUMP_LLM_ENABLED'),
    ],
];
