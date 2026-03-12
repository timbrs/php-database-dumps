# Database Dumps Package

[![Tests](https://img.shields.io/badge/tests-passing-brightgreen)]()
[![PHP Version](https://img.shields.io/badge/php-%5E7.2%20%7C%20%5E8.0-blue)]()
[![License](https://img.shields.io/badge/license-MIT-blue.svg)]()

**[Русский](#русский)** | **[English](#english)**

---

<a id="русский"></a>

# Русский язык

PHP-пакет для экспорта и импорта дампов баз данных в SQL. Поддерживает PostgreSQL, MySQL и Oracle (12c+). Работает с Symfony, Laravel и любым PHP-проектом.

## Оглавление

- [Описание](#описание)
- [Возможности](#возможности)
- [Установка](#установка)
- [Быстрый старт](#быстрый-старт)
  - [Symfony](#быстрый-старт-symfony)
  - [Laravel](#быстрый-старт-laravel)
- [Конфигурация](#конфигурация)
  - [Полный экспорт (full_export)](#полный-экспорт-full_export)
  - [Частичный экспорт (partial_export)](#частичный-экспорт-partial_export)
  - [Каскадные зависимости (cascade_from)](#каскадные-зависимости-cascade_from)
  - [Замена персональных данных (faker)](#замена-персональных-данных-faker)
  - [Разделение конфига по схемам (includes)](#разделение-конфига-по-схемам-includes)
  - [Несколько подключений](#несколько-подключений)
  - [Автогенерация конфигурации](#автогенерация-конфигурации)
- [Настройка Symfony](#настройка-symfony)
  - [Регистрация бандла](#регистрация-бандла)
  - [Структура каталогов (Symfony)](#структура-каталогов-symfony)
  - [Команды Symfony](#команды-symfony)
- [Настройка Laravel](#настройка-laravel)
  - [Регистрация провайдера](#регистрация-провайдера)
  - [Публикация конфигурации](#публикация-конфигурации)
  - [Команды Laravel](#команды-laravel)
- [Скрипты before/after](#скрипты-beforeafter)
- [Поддержка IDE (JSON Schema)](#поддержка-ide-json-schema)
- [Архитектура](#архитектура)
  - [Как работает экспорт](#как-работает-экспорт)
  - [Как работает импорт](#как-работает-импорт)
  - [Различия платформ](#различия-платформ)
  - [Структура исходного кода](#структура-исходного-кода)
- [Безопасность](#безопасность)
- [Тестирование](#тестирование)
- [Локальная разработка](#локальная-разработка)
- [Требования](#требования)
- [Лицензия](#лицензия)

---

## Описание

**Database Dumps** — это PHP-пакет, который помогает разработчикам создавать и разворачивать дампы базы данных для локальной и тестовой среды.

**Какую проблему решает?** На проекте с большой боевой базой разработчику нужно иметь актуальные тестовые данные — но копировать всю базу долго, а персональные данные клиентов нельзя использовать в dev-окружении. Этот пакет позволяет настроить правила экспорта один раз: какие таблицы забирать целиком, какие — частично, и забыть о ручной подготовке дампов.

**Как работает?** Вы описываете в YAML-файле, какие таблицы и как экспортировать. Пакет сам:
- генерирует SQL-дампы из боевой (или staging) базы с учётом FK-зависимостей между таблицами
- заменяет персональные данные (ФИО, email, телефоны) на сгенерированные, чтобы дампы были безопасны
- разворачивает дампы в нужную базу одной командой, с защитой от случайного запуска на продакшене

**Где применим?** В любом PHP-проекте на Symfony или Laravel (или без фреймворка), где нужно:
- быстро разворачивать тестовую базу для разработчиков
- передавать дампы между командами без утечки персональных данных
- держать seed-данные в репозитории и обновлять их из реальной базы
- работать с несколькими базами данных одновременно

## Возможности

- **Не привязан к фреймворку** — работает с Symfony, Laravel и любым PHP-проектом
- **PostgreSQL, MySQL и Oracle** — автоматически генерирует правильный SQL для каждой СУБД
- **Несколько подключений** — экспорт/импорт сразу из нескольких баз данных
- **Пакетные INSERT** — автоматическая группировка по 1000 строк на выражение
- **Откат при ошибках** — импорт выполняется в транзакции
- **Защита от продакшена** — импорт заблокирован при `APP_ENV=prod`
- **Скрипты до/после** — свои SQL-скрипты до и после импорта
- **Гибкая настройка** — YAML-файл с правилами полного и частичного экспорта
- **Сброс счётчиков** — автоматический сброс sequence / auto-increment после импорта
- **Автогенерация конфига** — команда `prepare-config` создаёт YAML по структуре БД
- **FK-сортировка** — автоматическая топологическая сортировка таблиц при экспорте и импорте (родители первыми)
- **Каскадные зависимости** — `cascade_from` генерирует WHERE-подзапросы для связности данных через FK
- **Замена ПД (faker)** — автоматическое обнаружение и замена персональных данных (ФИО, email, телефон, пол) при экспорте
- **Разделение конфига** — автоматическое разбиение конфигурации на отдельные файлы по схемам

## Установка

```bash
composer require --dev timbrs/database-dumps
```

## Быстрый старт

<a id="быстрый-старт-symfony"></a>

### Symfony

1. Бандл регистрируется автоматически через Symfony Flex.

2. Создайте файл `config/dump_config.yaml`:

```yaml
full_export:
  public:
    - users
    - roles

partial_export:
  public:
    clients:
      limit: 1000
      order_by: created_at DESC
```

3. Экспортируйте дампы:

```bash
php bin/console app:dbdump:export all
```

4. Импортируйте дампы:

```bash
php bin/console app:dbdump:import
```

<a id="быстрый-старт-laravel"></a>

### Laravel

1. Сервис-провайдер подключается автоматически. Файл `database/dump_config.yaml` создаётся при первом запуске.

2. Отредактируйте `database/dump_config.yaml` (формат тот же, что и для Symfony).

3. Экспортируйте дампы:

```bash
php artisan dbdump:export all
```

4. Импортируйте дампы:

```bash
php artisan dbdump:import
```

## Конфигурация

Экспорт настраивается через YAML-файл. В нём две секции: `full_export` (все строки) и `partial_export` (с ограничениями).

#### Полный экспорт (full_export)

Экспортирует **все строки** из указанных таблиц:

```yaml
full_export:
  public:          # имя схемы
    - users        # таблицы — все строки
    - roles
  system:
    - settings
```

#### Частичный экспорт (partial_export)

Экспортирует **часть строк** с фильтрацией:

```yaml
partial_export:
  public:
    clients:
      limit: 1000                    # максимум строк
      order_by: created_at DESC      # сортировка
      where: "is_active = true"      # условие WHERE
    orders:
      limit: 5000
      order_by: id DESC
```

**Доступные опции:**

| Опция | Описание |
|-------|----------|
| `limit` | Максимум строк |
| `order_by` | Сортировка (должна заканчиваться на `ASC` или `DESC`) |
| `where` | Условие WHERE |
| `cascade_from` | Каскадная фильтрация по FK-родителю (см. ниже) |

### Каскадные зависимости (cascade_from)

При частичном экспорте связанных таблиц данные могут стать несогласованными: дочерняя таблица может ссылаться на строки, которые не попали в дамп родителя. Опция `cascade_from` решает эту проблему — она автоматически генерирует WHERE-подзапрос, ограничивающий выборку только теми строками, чей FK-родитель присутствует в дампе.

```yaml
partial_export:
  public:
    users:
      limit: 500
      order_by: id DESC
    orders:
      limit: 1000
      order_by: created_at DESC
      cascade_from:
        - parent: public.users
          fk_column: user_id
          parent_column: id
    order_items:
      limit: 5000
      order_by: id DESC
      cascade_from:
        - parent: public.orders
          fk_column: order_id
          parent_column: id
```

В этом примере:
- `orders` экспортирует только те заказы, чей `user_id` есть среди экспортированных `users`
- `order_items` экспортирует только позиции заказов, попавших в дамп `orders`
- Подзапросы вложенные: `order_items` → `orders` → `users` (глубина до 10 уровней)

Команда `prepare-config` автоматически определяет FK-зависимости и генерирует `cascade_from`. Чтобы отключить: `--no-cascade`.

### Замена персональных данных (faker)

Пакет может автоматически обнаруживать и заменять персональные данные при экспорте. Это позволяет безопасно использовать дампы в dev/staging окружениях.

**Поддерживаемые паттерны:**

| Паттерн | Описание | Пример оригинала | Пример замены |
|---------|----------|------------------|---------------|
| `fio` | ФИО полностью | Иванов Иван Иванович | Петров Александр Сергеевич |
| `fio_short` | ФИО сокращённо | Иванов И.И. | Козлов А.В. |
| `name` | Фамилия Имя | Иванов Иван | Петров Александр |
| `firstname` | Имя (кросс-корреляция с составной колонкой) | Иван | Александр |
| `lastname` | Фамилия (кросс-корреляция с составной колонкой) | Иванов | Петров |
| `patronymic` | Отчество (кросс-корреляция с составной колонкой) | Иванович | Сергеевич |
| `email` | Email | ivan@company.ru | aleksandr.petrov42@example.com |
| `phone` | Телефон | +79161234567 | +79234567890 |
| `gender` | Пол (12 форматов: male/female, м/ж, муж/жен и др.) | Мужской | Женский |

**Секция `faker` в конфигурации:**

```yaml
faker:
  public:
    users:
      full_name: fio
      display_name: name
      first_name: firstname
      last_name: lastname
      middle_name: patronymic
      email: email
      phone: phone
      sex: gender
    employees:
      fio: fio
      short_fio: fio_short
      contact_email: email
```

Паттерны `firstname`, `lastname` и `patronymic` детектируются через кросс-корреляцию: если в таблице уже найдена составная колонка (fio, fio_short, name), а рядом есть колонка с отдельными именами/фамилиями/отчествами — она будет обнаружена автоматически.

Паттерн `gender` определяется по совпадению имени колонки (`gender`, `sex`, `пол`) **и** содержимого (допустимые значения: `male`/`female`, `m`/`f`, `м`/`ж`, `мужской`/`женский`, `муж`/`жен`, `мужчина`/`женщина`). Регистр и формат оригинала сохраняются при замене.

Команда `prepare-config` автоматически анализирует содержимое таблиц и генерирует секцию `faker`, если в колонках обнаруживаются паттерны ПД (порог совпадения: 80% из 200 случайных строк). Чтобы отключить: `--no-faker`.

Замена детерминирована — seed основан на хеше значения ФИО (колонка с паттерном `fio`), если такая колонка есть в конфигурации таблицы. Если колонки `fio` нет — seed берётся от хеша комбинации всех faker-значений строки. Это гарантирует, что одна и та же персона всегда получает одинаковую замену независимо от таблицы и запуска.

### Разделение конфига по схемам (includes)

При большом количестве таблиц конфигурация может стать громоздкой. Команда `prepare-config` по умолчанию разбивает конфиг на отдельные файлы по схемам:

```
config/
├── dump_config.yaml          # главный файл с includes
├── public.yaml               # конфигурация схемы public
├── system.yaml               # конфигурация схемы system
└── analytics/                # именованное подключение
    └── analytics.yaml
```

**Главный файл (`dump_config.yaml`):**

```yaml
includes:
  public: public.yaml
  system: system.yaml

connections:
  analytics:
    includes:
      analytics: analytics/analytics.yaml
```

**Файл схемы (`public.yaml`):**

```yaml
full_export:
  - users
  - roles
partial_export:
  clients:
    limit: 1000
    order_by: created_at DESC
faker:
  users:
    full_name: fio
    email: email
```

Чтобы генерировать единый файл без разделения: `--no-split`.

### Несколько подключений

Если нужно работать с несколькими базами данных, добавьте секцию `connections`:

```yaml
# Основное подключение
full_export:
  public:
    - users
    - roles

partial_export:
  public:
    posts:
      limit: 100

# Дополнительные подключения
connections:
  analytics:                 # имя подключения (как в настройках фреймворка)
    full_export:
      analytics:
        - events
        - metrics
    partial_export:
      analytics:
        logs:
          limit: 50
          order_by: id DESC
```

**Куда сохраняются дампы:**
- Основное подключение: `database/dumps/{schema}/{table}.sql`
- Именованное подключение: `database/dumps/{connection}/{schema}/{table}.sql`

**Опция `--connection`:**

```bash
# Только основное подключение (по умолчанию)
php artisan dbdump:export all

# Только указанное подключение
php artisan dbdump:export all --connection=analytics

# Все подключения сразу
php artisan dbdump:export all --connection=all
```

### Автогенерация конфигурации

Команда `prepare-config` смотрит на структуру БД и создаёт или обновляет `dump_config.yaml`. Обязательный аргумент `mode` определяет область действия:

```bash
# Symfony
php bin/console app:dbdump:prepare-config all                    # Полная регенерация
php bin/console app:dbdump:prepare-config schema=billing         # Перегенерировать одну схему
php bin/console app:dbdump:prepare-config table=public.users     # Перегенерировать одну таблицу
php bin/console app:dbdump:prepare-config new                    # Добавить только новые таблицы

# Laravel
php artisan dbdump:prepare-config all
php artisan dbdump:prepare-config schema=billing
php artisan dbdump:prepare-config table=public.users
php artisan dbdump:prepare-config new
```

**Режимы:**

| Режим | Описание |
|-------|----------|
| `all` | Полная регенерация конфигурации (перезаписывает файл) |
| `schema=<name>` | Перегенерация одной схемы, мёрж в существующий конфиг |
| `table=<schema.table>` | Перегенерация одной таблицы, мёрж в существующий конфиг |
| `new` | Обнаружение и дописывание новых таблиц (не затрагивает существующие) |

**Опции:**

| Опция | Описание | По умолчанию |
|-------|----------|-------------|
| `--threshold`, `-t` | Порог строк: таблицы с количеством строк <= порога идут в full_export, больше — в partial_export | 500 |
| `--force`, `-f` | Перезаписать файл без подтверждения (только для режима `all`) | — |
| `--no-cascade` | Пропустить обнаружение FK и генерацию `cascade_from` | — |
| `--no-faker` | Пропустить обнаружение персональных данных | — |
| `--no-split` | Генерировать единый YAML без разделения по схемам | — |

**Как распределяются таблицы:**
- Строк <= порога — `full_export`
- Строк > порога — `partial_export` (с limit, автоопределённой сортировкой и шаблоном `where: "1=1"` для удобства редактирования)
- Пустые таблицы — пропускаются
- Служебные таблицы (migrations, sessions, cache_*, telescope_*, oauth_*, audit_*) — пропускаются

## Настройка Symfony

### Регистрация бандла

Бандл регистрируется автоматически через Symfony Flex. Если нет — добавьте в `config/bundles.php`:

```php
return [
    // ...
    Timbrs\DatabaseDumps\Bridge\Symfony\DatabaseDumpsBundle::class => ['all' => true],
];
```

Укажите платформу в `services.yaml`:

```yaml
parameters:
    database_dumps.platform: 'postgresql'  # или 'mysql', 'oracle'
```

<a id="структура-каталогов-symfony"></a>

### Структура каталогов (Symfony)

```
your-symfony-project/
├── config/
│   └── dump_config.yaml          # настройки экспорта
├── database/
│   ├── before_exec/              # скрипты до импорта
│   │   └── 01_prepare.sql
│   ├── dumps/                    # SQL-дампы
│   │   ├── public/
│   │   │   ├── users.sql
│   │   │   └── roles.sql
│   │   └── analytics/            # именованное подключение
│   │       └── analytics/
│   │           └── events.sql
│   └── after_exec/               # скрипты после импорта
│       └── 01_finalize.sql
```

### Команды Symfony

```bash
# Экспорт всех таблиц
php bin/console app:dbdump:export all

# Экспорт одной таблицы
php bin/console app:dbdump:export public.users

# Экспорт только из одной схемы
php bin/console app:dbdump:export all --schema=public

# Экспорт из конкретного подключения
php bin/console app:dbdump:export all --connection=analytics
php bin/console app:dbdump:export all --connection=all

# Импорт всех дампов
php bin/console app:dbdump:import

# Импорт с опциями
php bin/console app:dbdump:import --skip-before --skip-after
php bin/console app:dbdump:import --schema=public
php bin/console app:dbdump:import --connection=all

# Экспорт без каскадной фильтрации и без замены ПД
php bin/console app:dbdump:export all --no-cascade --no-faker

# Сгенерировать конфигурацию по структуре БД
php bin/console app:dbdump:prepare-config all
php bin/console app:dbdump:prepare-config all --threshold=1000 --force
php bin/console app:dbdump:prepare-config schema=billing
php bin/console app:dbdump:prepare-config table=public.users
php bin/console app:dbdump:prepare-config new --no-cascade --no-faker
```

## Настройка Laravel

### Регистрация провайдера

Сервис-провайдер подключается автоматически. Если нет — зарегистрируйте в `config/app.php`:

```php
'providers' => [
    // ...
    Timbrs\DatabaseDumps\Bridge\Laravel\DatabaseDumpsServiceProvider::class,
],
```

### Публикация конфигурации

Чтобы изменить пути, опубликуйте PHP-конфигурацию:

```bash
php artisan vendor:publish --tag=database-dumps-config
```

Появится файл `config/database-dumps.php`:

```php
return [
    'config_path' => base_path('database/dump_config.yaml'),
    'project_dir' => base_path(),
];
```

### Команды Laravel

```bash
# Экспорт всех таблиц
php artisan dbdump:export all

# Экспорт одной таблицы
php artisan dbdump:export public.users

# Экспорт только из одной схемы
php artisan dbdump:export all --schema=public

# Экспорт из конкретного подключения
php artisan dbdump:export all --connection=analytics
php artisan dbdump:export all --connection=all

# Импорт всех дампов
php artisan dbdump:import

# Импорт с опциями
php artisan dbdump:import --skip-before --skip-after
php artisan dbdump:import --schema=public
php artisan dbdump:import --connection=all

# Экспорт без каскадной фильтрации и без замены ПД
php artisan dbdump:export all --no-cascade --no-faker

# Сгенерировать конфигурацию по структуре БД
php artisan dbdump:prepare-config all
php artisan dbdump:prepare-config all --threshold=1000 --force
php artisan dbdump:prepare-config schema=billing
php artisan dbdump:prepare-config table=public.users
php artisan dbdump:prepare-config new --no-cascade --no-faker
```

## Скрипты before/after

Можно выполнять свои SQL-скрипты до и после импорта.

| Каталог | Когда выполняется |
|---------|-------------------|
| `database/before_exec/` | **до** импорта дампов |
| `database/after_exec/` | **после** импорта дампов |

Скрипты выполняются в **алфавитном порядке**. Используйте числовые префиксы для управления очерёдностью:

```
database/before_exec/
├── 01_disable_triggers.sql
├── 02_prepare_temp.sql
database/after_exec/
├── 01_enable_triggers.sql
├── 02_refresh_views.sql
```

Чтобы пропустить скрипты, используйте `--skip-before` и `--skip-after`:

```bash
php artisan dbdump:import --skip-before
php artisan dbdump:import --skip-after
php artisan dbdump:import --skip-before --skip-after
```

## Поддержка IDE (JSON Schema)

В пакете есть JSON Schema для `dump_config.yaml` — файл `resources/dump_config.schema.json`. Он даёт автодополнение и валидацию в PHPStorm и других IDE.

### Вариант 1: YAML-комментарий (рекомендуется)

Добавьте в начало `dump_config.yaml`:

```yaml
# yaml-language-server: $schema=../vendor/timbrs/database-dumps/resources/dump_config.schema.json
```

> Путь указывается относительно файла: для Symfony — относительно `config/`, для Laravel — относительно `database/`.

### Вариант 2: Настройка PHPStorm вручную

1. Откройте **Settings > Languages & Frameworks > Schemas and DTDs > JSON Schema Mappings**
2. Добавьте маппинг:
   - **Schema file**: `vendor/timbrs/database-dumps/resources/dump_config.schema.json`
   - **File path pattern**: `dump_config.yaml`

## Архитектура

### Как работает экспорт

```
Команда → TableConfigResolver → DatabaseDumper → [FK-сортировка] → DataFetcher → [Cascade WHERE] → [Faker] → SqlGenerator → .sql файлы
```

1. **TableConfigResolver** — читает YAML и собирает список таблиц для экспорта
2. **DatabaseDumper** — управляет процессом экспорта
3. **TableDependencyResolver** — топологическая сортировка таблиц по FK (родители экспортируются первыми)
4. **DataFetcher** — получает данные из БД через `ConnectionRegistry`
5. **CascadeWhereResolver** — генерирует WHERE-подзапросы из `cascade_from` для связности данных
6. **RussianFaker** — заменяет персональные данные (ФИО, email, телефон, пол) на сгенерированные
7. **SqlGenerator** — генерирует SQL: TRUNCATE + INSERT + сброс счётчиков
8. Результат сохраняется в `database/dumps/{schema}/{table}.sql`

### Как работает импорт

```
Команда → DatabaseImporter → ProductionGuard → TransactionManager → [FK-сортировка] → ScriptExecutor → SqlParser → выполнение
```

1. **ProductionGuard** — проверяет, что мы не на продакшене
2. **TransactionManager** — оборачивает всё в транзакцию
3. **TableDependencyResolver** — топологическая сортировка файлов по FK (родители импортируются первыми)
4. **ScriptExecutor** — выполняет скрипты из `before_exec/`
5. **SqlParser** / **StatementSplitter** — разбирает .sql файлы на отдельные выражения
6. Выражения выполняются в БД
7. **ScriptExecutor** — выполняет скрипты из `after_exec/`

### Различия платформ

Пакет сам генерирует правильный SQL в зависимости от СУБД:

| | PostgreSQL | MySQL | Oracle (12c+) |
|---|---|---|---|
| Имена таблиц | `"table"` (двойные кавычки) | `` `table` `` (обратные кавычки) | `"TABLE"` (двойные кавычки, UPPERCASE) |
| TRUNCATE | `TRUNCATE ... CASCADE` | `SET FOREIGN_KEY_CHECKS=0` | `DELETE FROM` (FK-safe) |
| Счётчики | `setval()` / `pg_get_serial_sequence()` | `ALTER TABLE ... AUTO_INCREMENT` | Комментарий-заглушка (используйте `after_exec/`) |
| LIMIT | `LIMIT N` | `LIMIT N` | `FETCH FIRST N ROWS ONLY` |
| INSERT | Батч 1000 строк | Батч 1000 строк | По одной строке (нет multi-row INSERT) |
| Случайное число | `RANDOM()` | `RAND()` | `DBMS_RANDOM.VALUE` |

Платформа определяется автоматически по подключению к БД.

> **Oracle:** используется `DELETE FROM` вместо `TRUNCATE TABLE`, т.к. Oracle TRUNCATE не поддерживает CASCADE и блокируется FK constraints. Сброс sequences требует PL/SQL — используйте скрипты в `database/after_exec/`.

<a id="структура-исходного-кода"></a>

### Структура исходного кода

```
src/
├── Adapter/                          # Адаптеры подключений к БД
│   ├── DoctrineDbalAdapter.php       #   Doctrine DBAL
│   ├── LaravelDatabaseAdapter.php    #   Laravel DB
│   └── PdoAdapter.php               #   Универсальный PDO (Oracle и др.)
├── Bridge/                           # Интеграции с фреймворками
│   ├── Laravel/
│   │   ├── Command/                  #   Artisan-команды
│   │   ├── DatabaseDumpsServiceProvider.php
│   │   └── LaravelLogger.php
│   └── Symfony/
│       ├── Command/                  #   Console-команды
│       ├── DependencyInjection/
│       ├── ConnectionRegistryFactory.php
│       ├── ConsoleLogger.php
│       └── DatabaseDumpsBundle.php
├── Config/                           # Классы конфигурации
│   ├── DumpConfig.php                #   Общие настройки дампов
│   ├── EnvironmentConfig.php         #   Определение окружения
│   ├── FakerConfig.php               #   Настройки замены ПД
│   └── TableConfig.php              #   Настройки экспорта таблицы
├── Contract/                         # Интерфейсы
├── Exception/                        # Исключения
├── Platform/                         # Поддержка SQL-диалектов
│   ├── MySqlPlatform.php
│   ├── OraclePlatform.php
│   ├── PostgresPlatform.php
│   └── PlatformFactory.php
├── Service/
│   ├── ConfigGenerator/              # Автогенерация конфигурации
│   │   ├── ConfigGenerator.php       #   Генератор dump_config.yaml
│   │   ├── ConfigSplitter.php        #   Разделение на per-schema файлы
│   │   └── ForeignKeyInspector.php   #   Инспекция FK из information_schema
│   ├── ConnectionRegistry.php        # Реестр подключений
│   ├── Dumper/                       # Экспорт дампов
│   │   ├── CascadeWhereResolver.php  #   Рекурсивная резолюция cascade WHERE
│   │   ├── DatabaseDumper.php        #   Основной экспортёр
│   │   └── DataFetcher.php           #   Загрузка данных из таблицы
│   ├── Faker/                        # Замена персональных данных
│   │   ├── PatternDetector.php       #   Автодетекция паттернов ПД
│   │   └── RussianFaker.php          #   Генератор русских ФИО/email/телефонов
│   ├── Generator/                    # Генерация SQL
│   ├── Graph/                        # Граф FK-зависимостей
│   │   ├── TableDependencyResolver.php #  FK-граф + топологическая сортировка
│   │   └── TopologicalSorter.php     #   Алгоритм Kahn (BFS) + Tarjan (SCC)
│   ├── Importer/                     # Импорт дампов
│   ├── Parser/                       # Разбор SQL
│   └── Security/                     # Защита от продакшена
└── Util/
    ├── FileSystemHelper.php
    └── YamlConfigLoader.php
```

## Безопасность

Пакет не позволяет случайно импортировать дампы на продакшен. Импорт заблокирован, когда переменная окружения `APP_ENV` равна `prod` или `predprod`.

## Тестирование

```bash
# Все тесты
composer test

# Тесты с покрытием кода
composer test-coverage

# Статический анализ (PHPStan level 8)
composer phpstan

# Исправление стиля кода
composer cs-fix
```

## Локальная разработка

Чтобы подключить пакет из локальной папки (без Packagist), добавьте в `composer.json` вашего проекта:

```json
{
    "repositories": [
        {
            "type": "path",
            "url": "../database-dumps"
        }
    ],
    "require": {
        "timbrs/database-dumps": "*"
    }
}
```

Затем выполните `composer update timbrs/database-dumps` — Composer создаст симлинк на локальный пакет.

## Требования

**Обязательные:**

- PHP ^7.2 | ^8.0
- `symfony/yaml` ^4.4 | ^5.4 | ^6.0 | ^7.0
- `symfony/finder` ^4.4 | ^5.4 | ^6.0 | ^7.0

**Опциональные (зависят от фреймворка):**

| Зависимость | Для чего нужна |
|---|---|
| `doctrine/dbal` ^2.13 \| ^3.0 \| ^4.0 | Адаптер Doctrine DBAL (Symfony) |
| `symfony/console` ^4.4 \| ^5.4 \| ^6.0 \| ^7.0 | Консольные команды Symfony |
| `symfony/http-kernel` ^5.4 \| ^6.0 \| ^7.0 | Регистрация бандла Symfony |
| `illuminate/support` ^7.0 \| ^8.0 \| ^9.0 \| ^10.0 \| ^11.0 | Сервис-провайдер Laravel |
| `illuminate/console` ^7.0 \| ^8.0 \| ^9.0 \| ^10.0 \| ^11.0 | Artisan-команды Laravel |
| `illuminate/database` ^7.0 \| ^8.0 \| ^9.0 \| ^10.0 \| ^11.0 | Адаптер БД Laravel |

## Лицензия

MIT License. Подробнее в файле [LICENSE](LICENSE).

---

<a id="english"></a>

# English

PHP package for exporting and importing database dumps as SQL. Supports PostgreSQL, MySQL, and Oracle (12c+). Works with Symfony, Laravel, and any PHP project.

## Table of Contents

- [Description](#description)
- [Features](#features)
- [Installation](#installation)
- [Quick Start](#quick-start)
  - [Symfony](#quick-start-symfony)
  - [Laravel](#quick-start-laravel)
- [Configuration](#configuration)
  - [Full Export](#full-export)
  - [Partial Export](#partial-export)
  - [Cascade Dependencies (cascade_from)](#cascade-dependencies-cascade_from)
  - [Personal Data Masking (faker)](#personal-data-masking-faker)
  - [Config Splitting by Schema (includes)](#config-splitting-by-schema-includes)
  - [Multiple Connections](#multiple-connections)
  - [Auto-generate Configuration](#auto-generate-configuration)
- [Symfony Setup](#symfony-setup)
  - [Bundle Registration](#bundle-registration)
  - [Directory Structure (Symfony)](#directory-structure-symfony)
  - [Symfony Commands](#symfony-commands)
- [Laravel Setup](#laravel-setup)
  - [Provider Registration](#provider-registration)
  - [Publishing Configuration](#publishing-configuration)
  - [Laravel Commands](#laravel-commands)
- [Before/After Scripts](#beforeafter-scripts)
- [IDE Support (JSON Schema)](#ide-support-json-schema)
- [Architecture](#architecture)
  - [How Export Works](#how-export-works)
  - [How Import Works](#how-import-works)
  - [Platform Differences](#platform-differences)
  - [Source Directory Structure](#source-directory-structure)
- [Security](#security)
- [Testing](#testing)
- [Local Development](#local-development)
- [Requirements](#requirements)
- [License](#license)

---

## Description

**Database Dumps** is a PHP package that helps developers create and deploy database dumps for local and test environments.

**What problem does it solve?** On a project with a large production database, developers need up-to-date test data — but copying the entire database is slow, and using real customer data in dev environments is not acceptable. This package lets you configure export rules once — which tables to grab in full, which partially — and forget about manual dump preparation.

**How does it work?** You describe export rules in a YAML file. The package then:
- generates SQL dumps from production (or staging) database, respecting FK dependencies between tables
- replaces personal data (Russian names, emails, phone numbers) with generated values, making dumps safe to use
- deploys dumps into the target database with a single command, with built-in protection against accidental runs on production

**Where is it useful?** In any PHP project using Symfony or Laravel (or standalone), where you need to:
- quickly set up a test database for developers
- share dumps across teams without leaking personal data
- keep seed data in the repository and update it from a real database
- work with multiple database connections simultaneously

## Features

- **No framework lock-in** — works with Symfony, Laravel, and any PHP project
- **PostgreSQL, MySQL & Oracle** — automatically generates the right SQL for each database
- **Multiple connections** — export/import from several databases at once
- **Batched INSERTs** — automatically groups rows (1000 per statement)
- **Rollback on errors** — import runs inside a transaction
- **Production guard** — import is blocked when `APP_ENV=prod`
- **Before/After scripts** — run custom SQL before and after import
- **Flexible config** — YAML file with full and partial export rules
- **Sequence reset** — automatic sequence / auto-increment reset after import
- **Auto-generate config** — `prepare-config` command creates YAML from DB structure
- **FK-aware ordering** — automatic topological sorting of tables during export and import (parents first)
- **Cascade dependencies** — `cascade_from` generates WHERE subqueries to keep data consistent across FK relations
- **Personal data masking (faker)** — automatic detection and replacement of PII (Russian names, email, phone, gender) during export
- **Config splitting** — automatic splitting of configuration into per-schema files

## Installation

```bash
composer require --dev timbrs/database-dumps
```

<a id="quick-start"></a>

## Quick Start

<a id="quick-start-symfony"></a>

### Symfony

1. The bundle registers automatically via Symfony Flex.

2. Create `config/dump_config.yaml`:

```yaml
full_export:
  public:
    - users
    - roles

partial_export:
  public:
    clients:
      limit: 1000
      order_by: created_at DESC
```

3. Export dumps:

```bash
php bin/console app:dbdump:export all
```

4. Import dumps:

```bash
php bin/console app:dbdump:import
```

<a id="quick-start-laravel"></a>

### Laravel

1. The service provider is discovered automatically. The file `database/dump_config.yaml` is created on first run.

2. Edit `database/dump_config.yaml` (same format as Symfony).

3. Export dumps:

```bash
php artisan dbdump:export all
```

4. Import dumps:

```bash
php artisan dbdump:import
```

## Configuration

Export is configured via a YAML file with two sections: `full_export` (all rows) and `partial_export` (with limits).

#### Full Export

Exports **all rows** from listed tables:

```yaml
full_export:
  public:          # schema name
    - users        # tables — all rows
    - roles
  system:
    - settings
```

#### Partial Export

Exports a **limited number of rows** with filtering:

```yaml
partial_export:
  public:
    clients:
      limit: 1000                    # max rows
      order_by: created_at DESC      # sorting
      where: "is_active = true"      # WHERE condition
    orders:
      limit: 5000
      order_by: id DESC
```

**Available options:**

| Option | Description |
|--------|-------------|
| `limit` | Max rows |
| `order_by` | Sorting (must end with `ASC` or `DESC`) |
| `where` | WHERE condition |
| `cascade_from` | Cascade filtering by FK parent (see below) |

### Cascade Dependencies (cascade_from)

When partially exporting related tables, data can become inconsistent: a child table may reference rows that didn't make it into the parent's dump. The `cascade_from` option solves this by automatically generating a WHERE subquery that limits the selection to only those rows whose FK parent is present in the dump.

```yaml
partial_export:
  public:
    users:
      limit: 500
      order_by: id DESC
    orders:
      limit: 1000
      order_by: created_at DESC
      cascade_from:
        - parent: public.users
          fk_column: user_id
          parent_column: id
    order_items:
      limit: 5000
      order_by: id DESC
      cascade_from:
        - parent: public.orders
          fk_column: order_id
          parent_column: id
```

In this example:
- `orders` exports only orders whose `user_id` exists among exported `users`
- `order_items` exports only items belonging to exported `orders`
- Subqueries are nested: `order_items` -> `orders` -> `users` (up to 10 levels deep)

The `prepare-config` command automatically detects FK dependencies and generates `cascade_from`. To disable: `--no-cascade`.

### Personal Data Masking (faker)

The package can automatically detect and replace personal data during export. This allows safe use of dumps in dev/staging environments.

**Supported patterns:**

| Pattern | Description | Original example | Replacement example |
|---------|-------------|------------------|---------------------|
| `fio` | Full Russian name | Иванов Иван Иванович | Петров Александр Сергеевич |
| `fio_short` | Short Russian name | Иванов И.И. | Козлов А.В. |
| `name` | First and last name | Иванов Иван | Петров Александр |
| `firstname` | First name (cross-correlated with composite column) | Иван | Александр |
| `lastname` | Last name (cross-correlated with composite column) | Иванов | Петров |
| `patronymic` | Patronymic (cross-correlated with composite column) | Иванович | Сергеевич |
| `email` | Email address | ivan@company.ru | aleksandr.petrov42@example.com |
| `phone` | Phone number | +79161234567 | +79234567890 |
| `gender` | Gender (12 formats: male/female, m/f, м/ж, etc.) | Мужской | Женский |

**The `faker` section in configuration:**

```yaml
faker:
  public:
    users:
      full_name: fio
      display_name: name
      first_name: firstname
      last_name: lastname
      middle_name: patronymic
      email: email
      phone: phone
      sex: gender
    employees:
      fio: fio
      short_fio: fio_short
      contact_email: email
```

The `firstname`, `lastname`, and `patronymic` patterns are detected via cross-correlation: if a composite column (fio, fio_short, name) is already found in the table and there's an adjacent column with individual first names/last names/patronymics — it will be detected automatically.

The `gender` pattern is detected by matching both the column name (`gender`, `sex`, `пол`) **and** its contents (valid values: `male`/`female`, `m`/`f`, `м`/`ж`, `мужской`/`женский`, `муж`/`жен`, `мужчина`/`женщина`). The original value's case and format are preserved during replacement.

The `prepare-config` command automatically analyzes table contents and generates the `faker` section when PII patterns are detected in columns (threshold: 80% match from 200 random rows). To disable: `--no-faker`.

Replacement is deterministic — the seed is based on the hash of the FIO value (column with `fio` pattern), if such a column exists in the table's faker config. If there is no `fio` column, the seed is computed from the hash of all faker column values in the row. This ensures the same person always gets the same replacement regardless of the table or run.

### Config Splitting by Schema (includes)

When dealing with many tables, the configuration can become unwieldy. The `prepare-config` command splits config into per-schema files by default:

```
config/
├── dump_config.yaml          # main file with includes
├── public.yaml               # public schema config
├── system.yaml               # system schema config
└── analytics/                # named connection
    └── analytics.yaml
```

**Main file (`dump_config.yaml`):**

```yaml
includes:
  public: public.yaml
  system: system.yaml

connections:
  analytics:
    includes:
      analytics: analytics/analytics.yaml
```

**Schema file (`public.yaml`):**

```yaml
full_export:
  - users
  - roles
partial_export:
  clients:
    limit: 1000
    order_by: created_at DESC
faker:
  users:
    full_name: fio
    email: email
```

To generate a single file without splitting: `--no-split`.

### Multiple Connections

To work with several databases, add a `connections` section:

```yaml
# Main connection
full_export:
  public:
    - users
    - roles

partial_export:
  public:
    posts:
      limit: 100

# Additional connections
connections:
  analytics:                 # connection name (as in framework config)
    full_export:
      analytics:
        - events
        - metrics
    partial_export:
      analytics:
        logs:
          limit: 50
          order_by: id DESC
```

**Where dumps are saved:**
- Main connection: `database/dumps/{schema}/{table}.sql`
- Named connection: `database/dumps/{connection}/{schema}/{table}.sql`

**The `--connection` option:**

```bash
# Main connection only (default)
php artisan dbdump:export all

# Specific connection only
php artisan dbdump:export all --connection=analytics

# All connections at once
php artisan dbdump:export all --connection=all
```

### Auto-generate Configuration

The `prepare-config` command looks at your DB structure and creates or updates `dump_config.yaml`. The required `mode` argument defines the scope:

```bash
# Symfony
php bin/console app:dbdump:prepare-config all                    # Full regeneration
php bin/console app:dbdump:prepare-config schema=billing         # Regenerate one schema
php bin/console app:dbdump:prepare-config table=public.users     # Regenerate one table
php bin/console app:dbdump:prepare-config new                    # Add only new tables

# Laravel
php artisan dbdump:prepare-config all
php artisan dbdump:prepare-config schema=billing
php artisan dbdump:prepare-config table=public.users
php artisan dbdump:prepare-config new
```

**Modes:**

| Mode | Description |
|------|-------------|
| `all` | Full config regeneration (overwrites file) |
| `schema=<name>` | Regenerate one schema, merge into existing config |
| `table=<schema.table>` | Regenerate one table, merge into existing config |
| `new` | Detect and append new tables only (doesn't touch existing entries) |

**Options:**

| Option | Description | Default |
|--------|-------------|---------|
| `--threshold`, `-t` | Row threshold: tables with rows <= threshold go to full_export, more — to partial_export | 500 |
| `--force`, `-f` | Overwrite file without asking (only for `all` mode) | — |
| `--no-cascade` | Skip FK detection and `cascade_from` generation | — |
| `--no-faker` | Skip personal data detection | — |
| `--no-split` | Generate a single YAML without splitting by schema | — |

**How tables are sorted:**
- Rows <= threshold — `full_export`
- Rows > threshold — `partial_export` (with limit, auto-detected sorting and `where: "1=1"` template for easy customization)
- Empty tables — skipped
- Service tables (migrations, sessions, cache_*, telescope_*, oauth_*, audit_*) — skipped

## Symfony Setup

### Bundle Registration

The bundle registers automatically via Symfony Flex. If not, add to `config/bundles.php`:

```php
return [
    // ...
    Timbrs\DatabaseDumps\Bridge\Symfony\DatabaseDumpsBundle::class => ['all' => true],
];
```

Set the platform in `services.yaml`:

```yaml
parameters:
    database_dumps.platform: 'postgresql'  # or 'mysql', 'oracle'
```

<a id="directory-structure-symfony"></a>

### Directory Structure (Symfony)

```
your-symfony-project/
├── config/
│   └── dump_config.yaml          # export settings
├── database/
│   ├── before_exec/              # pre-import scripts
│   │   └── 01_prepare.sql
│   ├── dumps/                    # SQL dumps
│   │   ├── public/
│   │   │   ├── users.sql
│   │   │   └── roles.sql
│   │   └── analytics/            # named connection
│   │       └── analytics/
│   │           └── events.sql
│   └── after_exec/               # post-import scripts
│       └── 01_finalize.sql
```

### Symfony Commands

```bash
# Export all tables
php bin/console app:dbdump:export all

# Export one table
php bin/console app:dbdump:export public.users

# Export from one schema only
php bin/console app:dbdump:export all --schema=public

# Export from a specific connection
php bin/console app:dbdump:export all --connection=analytics
php bin/console app:dbdump:export all --connection=all

# Import all dumps
php bin/console app:dbdump:import

# Import with options
php bin/console app:dbdump:import --skip-before --skip-after
php bin/console app:dbdump:import --schema=public
php bin/console app:dbdump:import --connection=all

# Export without cascade filtering and without PII replacement
php bin/console app:dbdump:export all --no-cascade --no-faker

# Generate config from DB structure
php bin/console app:dbdump:prepare-config all
php bin/console app:dbdump:prepare-config all --threshold=1000 --force
php bin/console app:dbdump:prepare-config schema=billing
php bin/console app:dbdump:prepare-config table=public.users
php bin/console app:dbdump:prepare-config new --no-cascade --no-faker
```

## Laravel Setup

### Provider Registration

The service provider is discovered automatically. If not, register it in `config/app.php`:

```php
'providers' => [
    // ...
    Timbrs\DatabaseDumps\Bridge\Laravel\DatabaseDumpsServiceProvider::class,
],
```

### Publishing Configuration

To customize paths, publish the PHP config:

```bash
php artisan vendor:publish --tag=database-dumps-config
```

This creates `config/database-dumps.php`:

```php
return [
    'config_path' => base_path('database/dump_config.yaml'),
    'project_dir' => base_path(),
];
```

### Laravel Commands

```bash
# Export all tables
php artisan dbdump:export all

# Export one table
php artisan dbdump:export public.users

# Export from one schema only
php artisan dbdump:export all --schema=public

# Export from a specific connection
php artisan dbdump:export all --connection=analytics
php artisan dbdump:export all --connection=all

# Import all dumps
php artisan dbdump:import

# Import with options
php artisan dbdump:import --skip-before --skip-after
php artisan dbdump:import --schema=public
php artisan dbdump:import --connection=all

# Export without cascade filtering and without PII replacement
php artisan dbdump:export all --no-cascade --no-faker

# Generate config from DB structure
php artisan dbdump:prepare-config all
php artisan dbdump:prepare-config all --threshold=1000 --force
php artisan dbdump:prepare-config schema=billing
php artisan dbdump:prepare-config table=public.users
php artisan dbdump:prepare-config new --no-cascade --no-faker
```

## Before/After Scripts

You can run custom SQL scripts before and after import.

| Directory | When it runs |
|-----------|-------------|
| `database/before_exec/` | **before** importing dumps |
| `database/after_exec/` | **after** importing dumps |

Scripts run in **alphabetical order**. Use numeric prefixes to control the order:

```
database/before_exec/
├── 01_disable_triggers.sql
├── 02_prepare_temp.sql
database/after_exec/
├── 01_enable_triggers.sql
├── 02_refresh_views.sql
```

To skip scripts, use `--skip-before` and `--skip-after`:

```bash
php artisan dbdump:import --skip-before
php artisan dbdump:import --skip-after
php artisan dbdump:import --skip-before --skip-after
```

## IDE Support (JSON Schema)

The package includes a JSON Schema for `dump_config.yaml` at `resources/dump_config.schema.json`. It provides autocompletion and validation in PHPStorm and other IDEs.

### Option 1: YAML comment (recommended)

Add to the top of your `dump_config.yaml`:

```yaml
# yaml-language-server: $schema=../vendor/timbrs/database-dumps/resources/dump_config.schema.json
```

> The path is relative to the file: for Symfony — relative to `config/`, for Laravel — relative to `database/`.

### Option 2: PHPStorm manual setup

1. Open **Settings > Languages & Frameworks > Schemas and DTDs > JSON Schema Mappings**
2. Add a mapping:
   - **Schema file**: `vendor/timbrs/database-dumps/resources/dump_config.schema.json`
   - **File path pattern**: `dump_config.yaml`

## Architecture

### How Export Works

```
Command → TableConfigResolver → DatabaseDumper → [FK sorting] → DataFetcher → [Cascade WHERE] → [Faker] → SqlGenerator → .sql files
```

1. **TableConfigResolver** — reads YAML and builds a list of tables to export
2. **DatabaseDumper** — manages the export process
3. **TableDependencyResolver** — topological sorting of tables by FK (parents are exported first)
4. **DataFetcher** — fetches data from the DB via `ConnectionRegistry`
5. **CascadeWhereResolver** — generates WHERE subqueries from `cascade_from` for data consistency
6. **RussianFaker** — replaces personal data (names, email, phone, gender) with generated values
7. **SqlGenerator** — generates SQL: TRUNCATE + INSERT + counter reset
8. Result is saved to `database/dumps/{schema}/{table}.sql`

### How Import Works

```
Command → DatabaseImporter → ProductionGuard → TransactionManager → [FK sorting] → ScriptExecutor → SqlParser → execution
```

1. **ProductionGuard** — checks we're not on production
2. **TransactionManager** — wraps everything in a transaction
3. **TableDependencyResolver** — topological sorting of files by FK (parents are imported first)
4. **ScriptExecutor** — runs scripts from `before_exec/`
5. **SqlParser** / **StatementSplitter** — splits .sql files into individual statements
6. Statements are executed against the DB
7. **ScriptExecutor** — runs scripts from `after_exec/`

### Platform Differences

The package generates the right SQL depending on the database:

| | PostgreSQL | MySQL | Oracle (12c+) |
|---|---|---|---|
| Table names | `"table"` (double quotes) | `` `table` `` (backticks) | `"TABLE"` (double quotes, UPPERCASE) |
| TRUNCATE | `TRUNCATE ... CASCADE` | `SET FOREIGN_KEY_CHECKS=0` | `DELETE FROM` (FK-safe) |
| Counters | `setval()` / `pg_get_serial_sequence()` | `ALTER TABLE ... AUTO_INCREMENT` | Stub comment (use `after_exec/`) |
| LIMIT | `LIMIT N` | `LIMIT N` | `FETCH FIRST N ROWS ONLY` |
| INSERT | Batch 1000 rows | Batch 1000 rows | One row per INSERT (no multi-row INSERT) |
| Random function | `RANDOM()` | `RAND()` | `DBMS_RANDOM.VALUE` |

The platform is detected automatically from the DB connection.

> **Oracle:** `DELETE FROM` is used instead of `TRUNCATE TABLE` because Oracle TRUNCATE doesn't support CASCADE and is blocked by FK constraints. Sequence reset requires PL/SQL — use scripts in `database/after_exec/`.

<a id="source-directory-structure"></a>

### Source Directory Structure

```
src/
├── Adapter/                          # DB connection adapters
│   ├── DoctrineDbalAdapter.php       #   Doctrine DBAL
│   ├── LaravelDatabaseAdapter.php    #   Laravel DB
│   └── PdoAdapter.php               #   Universal PDO (Oracle, etc.)
├── Bridge/                           # Framework integrations
│   ├── Laravel/
│   │   ├── Command/                  #   Artisan commands
│   │   ├── DatabaseDumpsServiceProvider.php
│   │   └── LaravelLogger.php
│   └── Symfony/
│       ├── Command/                  #   Console commands
│       ├── DependencyInjection/
│       ├── ConnectionRegistryFactory.php
│       ├── ConsoleLogger.php
│       └── DatabaseDumpsBundle.php
├── Config/                           # Configuration classes
│   ├── DumpConfig.php                #   Overall dump settings
│   ├── EnvironmentConfig.php         #   Environment detection
│   ├── FakerConfig.php               #   PII masking settings
│   └── TableConfig.php              #   Per-table export settings
├── Contract/                         # Interfaces
├── Exception/                        # Exceptions
├── Platform/                         # SQL dialect support
│   ├── MySqlPlatform.php
│   ├── OraclePlatform.php
│   ├── PostgresPlatform.php
│   └── PlatformFactory.php
├── Service/
│   ├── ConfigGenerator/              # Config auto-generation
│   │   ├── ConfigGenerator.php       #   dump_config.yaml generator
│   │   ├── ConfigSplitter.php        #   Splitting into per-schema files
│   │   └── ForeignKeyInspector.php   #   FK inspection from information_schema
│   ├── ConnectionRegistry.php        # Connection registry
│   ├── Dumper/                       # Dump export
│   │   ├── CascadeWhereResolver.php  #   Recursive cascade WHERE resolution
│   │   ├── DatabaseDumper.php        #   Main exporter
│   │   └── DataFetcher.php           #   Table data loading
│   ├── Faker/                        # Personal data masking
│   │   ├── PatternDetector.php       #   Automatic PII pattern detection
│   │   └── RussianFaker.php          #   Russian names/email/phone generator
│   ├── Generator/                    # SQL generation
│   ├── Graph/                        # FK dependency graph
│   │   ├── TableDependencyResolver.php #  FK graph + topological sorting
│   │   └── TopologicalSorter.php     #   Kahn's algorithm (BFS) + Tarjan (SCC)
│   ├── Importer/                     # Dump import
│   ├── Parser/                       # SQL parsing
│   └── Security/                     # Production guard
└── Util/
    ├── FileSystemHelper.php
    └── YamlConfigLoader.php
```

## Security

The package prevents accidental imports on production. Import is blocked when the `APP_ENV` environment variable is `prod` or `predprod`.

## Testing

```bash
# All tests
composer test

# Tests with code coverage
composer test-coverage

# Static analysis (PHPStan level 8)
composer phpstan

# Code style fix
composer cs-fix
```

## Local Development

To use the package from a local folder (without Packagist), add to your project's `composer.json`:

```json
{
    "repositories": [
        {
            "type": "path",
            "url": "../database-dumps"
        }
    ],
    "require": {
        "timbrs/database-dumps": "*"
    }
}
```

Then run `composer update timbrs/database-dumps` — Composer will create a symlink to the local package.

## Requirements

**Required:**

- PHP ^7.2 | ^8.0
- `symfony/yaml` ^4.4 | ^5.4 | ^6.0 | ^7.0
- `symfony/finder` ^4.4 | ^5.4 | ^6.0 | ^7.0

**Optional (depends on framework):**

| Dependency | What it's for |
|---|---|
| `doctrine/dbal` ^2.13 \| ^3.0 \| ^4.0 | Doctrine DBAL adapter (Symfony) |
| `symfony/console` ^4.4 \| ^5.4 \| ^6.0 \| ^7.0 | Symfony console commands |
| `symfony/http-kernel` ^5.4 \| ^6.0 \| ^7.0 | Symfony bundle registration |
| `illuminate/support` ^7.0 \| ^8.0 \| ^9.0 \| ^10.0 \| ^11.0 | Laravel service provider |
| `illuminate/console` ^7.0 \| ^8.0 \| ^9.0 \| ^10.0 \| ^11.0 | Laravel artisan commands |
| `illuminate/database` ^7.0 \| ^8.0 \| ^9.0 \| ^10.0 \| ^11.0 | Laravel DB adapter |

## License

MIT License. See [LICENSE](LICENSE) for details.

---

Developed by Timur Bayan (Timbrs).
