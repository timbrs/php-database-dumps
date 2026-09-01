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
- [Быстрый запуск (LLM + OPENCODE)](#быстрый-запуск-llm--opencode)
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
  - [Настройки (settings)](#настройки-settings)
  - [Автогенерация конфигурации](#автогенерация-конфигурации)
- [Углублённый анализ (LLM + OPENCODE)](#углублённый-анализ)
  - [Настройка LLM (интерактивно)](#настройка-llm-интерактивно)
  - [LLM-детекция ПД и профилирование](#llm-детекция-пд-и-профилирование)
  - [Анализ кода через OPENCODE](#анализ-кода-через-opencode)
- [Проверка конфига без БД (validate)](#проверка-конфига-без-бд-validate)
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
- **Smart Cycle Breaking** — автоматический разрыв циклических FK-зависимостей (двухфазный INSERT NULL → UPDATE) вместо fallback на алфавитный порядок
- **Self-referential FK** — поддержка таблиц со ссылками на себя (деревья категорий, оргструктуры, комментарии)
- **Валидация схемы при импорте** — предупреждение при расхождении столбцов дампа и БД, опция `--ignore-schema-mismatch`
- **Настройки в конфиге** — секция `settings:` для batch_size, sample_size, max_cascade_depth
- **Dry-run экспорт** — опция `--dry-run` для просмотра плана экспорта без выполнения
- **ИИ-анализ данных (LLM)** — точная классификация ПД, профилирование колонок и подсказки по выборке через OpenAI-совместимый LLM; при первом запуске пакет интерактивно спрашивает URL/модель/token и запоминает их
- **ИИ-анализ кода (OPENCODE)** — обнаружение связей без FK и бизнес-сегментов в коде хост-приложения (Eloquent/Doctrine/сырые JOIN) → авто-обогащение `cascade_from` и `sample.criteria`
- **Выборка по критериям (sample)** — набор именованных «корзин» (red/new/inactive/vip…) с дедупом по PK — «все фломастеры»

## Установка

```bash
composer require --dev timbrs/database-dumps
```

## Быстрый запуск (LLM + OPENCODE)

Рекомендуемый, основной сценарий — анализ **с участием ИИ**. Пакет использует два источника:

- **Прямой LLM** (OpenAI-совместимый API, например `openai/gpt-oss-120b`) — для анализа **данных**: точная классификация ПД (faker), профилирование колонок, подсказки по выборке.
- **OPENCODE** (внешний агент) — для анализа **кода** хост-приложения: связи без FK, бизнес-сегменты (scopes/репозитории) → `sample.criteria`, карта «колонка → код».

> Без настроенного LLM пакет тоже работает — анализ деградирует на regex-эвристики. Но рекомендуется именно LLM-сценарий.

### Шаг 1. Сгенерировать конфиг (при первом запуске пакет сам спросит про LLM)

```bash
# Laravel
php artisan dbdump:prepare-config all
# Symfony
php bin/console app:dbdump:prepare-config all
```

**При первом запуске**, если настройки LLM ещё не заданы, команда спросит:

1. `Настроить LLM сейчас?` — да/нет (по умолчанию да);
2. `API URL` — базовый адрес OpenAI-совместимого API (например `https://gpt.example.com/v1`);
3. `Модель` — имя модели (по умолчанию `openai/gpt-oss-120b`);
4. `Token` — Bearer-токен; **можно оставить пустым** (Enter).

Несекретные ответы (URL/модель) сохраняются в `config/database-dumps.php`, а `Token` — в `.env.local` (`DBDUMP_LLM_TOKEN`, при отсутствии — в `.env`); настройки **применяются сразу в этом же запуске**. Повторно спрашивать не будет. Если откажетесь — выбор тоже запомнится (анализ пойдёт на regex); включить позже — командой `configure-llm` (см. ниже).

> ℹ️ Токен в файл настроек не пишется — он хранится в `.env.local`, поэтому `config/database-dumps.php` безопасно коммитить. В Symfony этот файл подтягивается только вне prod.

Результат: `dump_config.yaml` (+ per-schema файлы) с автоопределёнными `full_export`/`partial_export`, секцией `faker` (LLM-детекция ПД) и каскадами по FK.

### Шаг 2 (опционально). Обогатить конфиг анализом кода через OPENCODE

Нужен установленный `opencode` в `PATH` и настроенный в нём провайдер LLM (`~/.config/opencode/opencode.json`).

```bash
# Одной командой: провижинит агента + инвентарь, прогонит opencode по схемам и применит результат
php artisan dbdump:prepare-analysis --run            # Laravel
php bin/console app:dbdump:prepare-analysis --run    # Symfony
```

Команда сгенерирует в хост-проект всё необходимое для связки с opencode:

- `.opencode/agents/dbdump-mapper.md` — готовый агент (read-only по коду, пишет только в `database/analysis/out/`);
- `database/analysis/schema_inventory.json` + `schema_inventory.<schema>.json` — инвентарь БД для агента (**без значений данных** — PII не выгружается);
- `database/analysis/output_schema.json` — контракт JSON-вывода;
- `database/analysis/RUN.md` — точные команды для ручного прогона.

Результат (связи из кода → `cascade_from: source: code`, бизнес-сегменты → `sample.criteria`) дописывается в `dump_config.yaml`, отчёт — в `database/analysis/REPORT.md`. Пользовательские правки в YAML в приоритете. Если `opencode` не найден — команда не упадёт, а напечатает готовые к вставке строки запуска. Подробнее — в разделе [Анализ кода через OPENCODE](#анализ-кода-через-opencode).

### Шаг 3. Экспортировать и импортировать дампы

```bash
php artisan dbdump:export all      # экспорт (faker применяется автоматически)
php artisan dbdump:import          # импорт (заблокирован на проде)
```

### Переконфигурировать LLM в любой момент

```bash
php artisan dbdump:configure-llm                 # Laravel
php bin/console app:dbdump:configure-llm         # Symfony
```

Интерактивно меняет URL/модель/token, умеет проверить соединение и пересохранить настройки: несекретное — в `config/database-dumps.php`, токен — в `.env.local` (`DBDUMP_LLM_TOKEN`).

## Быстрый старт

<a id="быстрый-старт-symfony"></a>

### Symfony

1. Зарегистрируйте бандл вручную в `config/bundles.php` (Flex-рецепта нет):

```php
Timbrs\DatabaseDumps\Bridge\Symfony\DatabaseDumpsBundle::class => ['dev' => true, 'test' => true],
```

2. Создайте файл `database/dump_config.yaml` (путь по умолчанию; переопределяется ключом `config_path`):

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
| `limit` | Максимум строк (для `sample` — общий потолок на объединённую выборку) |
| `order_by` | Сортировка (должна заканчиваться на `ASC` или `DESC`) |
| `where` | Условие WHERE |
| `cascade_from` | Каскадная фильтрация по FK-родителю (см. ниже) |
| `sample` | Выборка по именованным критериям («все фломастеры», см. ниже) |

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

### Выборка по именованным критериям (sample)

Иногда нужно набрать «все фломастеры» — по 10–100 строк каждого бизнес-сегмента (красные/жёлтые/зелёные по статусу, новые/старые по дате, неактивные, VIP…), часто по разным колонкам и кросс-таблично. Опция `sample` задаёт набор именованных «корзин», каждая со своим WHERE и квотой; итоговая выборка — объединение всех корзин **без дублей** (дедуп по первичному ключу).

```yaml
partial_export:
  public:
    clients:
      order_by: id DESC
      limit: 100            # необяз.: общий потолок на объединённую выборку
      sample:
        criteria:           # именованные корзины; у каждой свой WHERE и квота
          - { name: red,      where: "status = 'red'",                            limit: 10 }
          - { name: new,      where: "created_at >= NOW() - INTERVAL '30 days'",   limit: 50 }
          - { name: inactive, where: "last_login_at < NOW() - INTERVAL '90 days'", limit: 20 }
          - { name: vip,      where: "EXISTS (SELECT 1 FROM public.client_flags f WHERE f.client_id = clients.id AND f.flag = 'vip')", limit: 30 }
        stratify_by: status   # сахар: развернуть в по-корзине-на-DISTINCT-значение
        per_value: 10         # квота для stratify_by
```

Как это работает:
- **Фаза 1** — по каждому критерию выбираются первичные ключи: `SELECT <pk> FROM clients WHERE (<base where>) AND (<crit.where>) [ORDER BY ...] LIMIT <crit.limit>`. `stratify_by` разворачивается в по-корзине на каждое DISTINCT-значение колонки.
- **Дедуп** — id всех корзин объединяются и дедуплицируются; при заданном `limit` объединённая выборка обрезается до него.
- **Фаза 2** — финальный `SELECT * FROM clients WHERE <pk> IN (...)` (для составного PK — дизъюнкция равенств).

Требования и поведение:
- У таблицы должен быть первичный ключ (нужен для дедупа). `criteria[].where` проходит ту же проверку, что и обычный `where` (запрет `;` и SQL-комментариев, баланс кавычек/скобок) — корректные `EXISTS (...)` допускаются. `name` — идентификатор, `limit` — целое ≥ 1.
- **Cascade-консистентность:** если у родителя задан `sample`, дочерние таблицы (`cascade_from`) ссылаются на **фактически выбранные** id родителя, а не повторяют критерии подзапросом.
- `sample` и `cascade_from` на одной таблице несовместимы: при наличии `sample` экспорт идёт по нему (каскад для этой таблицы игнорируется). Поэтому авто-генерация добавляет `sample` только таблицам без `cascade_from`.

Авто-генерация: с флагом `--criteria` (или `--deep`) `prepare-config` профилирует категориальные колонки и сам предлагает `sample.criteria` (по корзине на топ-значение). Бизнес-сегменты из кода (Eloquent scopes, методы репозиториев) добавляются через ветку OPENCODE — см. [Углублённый анализ](#углублённый-анализ).

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

При большом количестве таблиц конфигурация может стать громоздкой. Команда `prepare-config` по умолчанию разбивает конфиг на отдельные файлы по схемам. Главный `dump_config.yaml` лежит в `database/`, а пер-схемные файлы — в подкаталоге `database/dump-settings/`:

```
database/
├── dump_config.yaml               # главный файл с includes
└── dump-settings/
    ├── public.yaml                # конфигурация схемы public
    ├── system.yaml                # конфигурация схемы system
    └── analytics/                 # именованное подключение
        └── analytics.yaml
```

**Главный файл (`database/dump_config.yaml`):**

```yaml
includes:
  public: ./dump-settings/public.yaml
  system: ./dump-settings/system.yaml

connections:
  analytics:
    includes:
      analytics: ./dump-settings/analytics/analytics.yaml
```

> 💡 Пути к include пишутся с префиксом `./` — так PhpStorm/IDE распознают их как ссылки на файлы: `Ctrl+B` / `Cmd+B` (или Ctrl+клик) на пути прыгает прямо в нужный `*.yaml`. На резолв путей загрузчиком префикс не влияет (работают оба варианта — с `./` и без).

**Файл схемы (`database/dump-settings/public.yaml`):**

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
- Основное подключение: `{data_dir}/dumps/{schema}/{table}.sql`
- Именованное подключение: `{data_dir}/dumps/{connection}/{schema}/{table}.sql`

`data_dir` — базовый каталог данных (по умолчанию `database`), от него считаются дампы (`{data_dir}/dumps`), анализ (`{data_dir}/analysis`) и хуки (`{data_dir}/before_exec`, `{data_dir}/after_exec`). Изменить можно в `config/database-dumps.php` (ключ `data_dir`, например `'var/database'`) или через env `DBDUMP_DATA_DIR`. В prod всегда используется дефолт `database`.

**Опция `--connection`:**

```bash
# Только основное подключение (по умолчанию)
php artisan dbdump:export all

# Только указанное подключение
php artisan dbdump:export all --connection=analytics

# Все подключения сразу
php artisan dbdump:export all --connection=all
```

### Настройки (settings)

Секция `settings` позволяет изменять внутренние параметры пакета:

```yaml
settings:
  batch_size: 1000        # строк на INSERT-выражение (по умолчанию: 1000)
  sample_size: 200        # строк для анализа ПД в PatternDetector (по умолчанию: 200)
  max_cascade_depth: 10   # макс. глубина cascade_from подзапросов (по умолчанию: 10)
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
| `all` | Полная регенерация конфигурации. Поверх существующего конфига **отказывает** — нужен `--force` |
| `schema=<name>` | Перегенерация одной схемы, мёрж в существующий конфиг |
| `table=<schema.table>` | Перегенерация одной таблицы, мёрж в существующий конфиг |
| `new` | Обнаружение и дописывание новых таблиц (не затрагивает существующие) |

Режим `all` собирает конфиг заново и существующий файл не читает: настроенные вручную `sample.criteria`,
`cascade_from`, `limit` и `faker` он заменяет машинной догадкой. Поэтому при существующем `dump_config.yaml`
команда отказывается работать и называет режимы, которые мёржат, а не затирают (`new`, `schema=`, `table=`),
а также `repair-configs` и `validate`. Осознанная пересборка с нуля — `prepare-config all --force`.

**Опции:**

| Опция | Описание | По умолчанию |
|-------|----------|-------------|
| `--threshold`, `-t` | Порог строк: таблицы с количеством строк <= порога идут в full_export, больше — в partial_export | 500 |
| `--force`, `-f` | Разрешить полную регенерацию поверх существующего конфига (только для режима `all`) | — |
| `--no-cascade` | Пропустить обнаружение FK и генерацию `cascade_from` | — |
| `--no-faker` | Пропустить обнаружение персональных данных | — |
| `--no-split` | Генерировать единый YAML без разделения по схемам | — |
| `--criteria` | Авто-генерация `sample.criteria` из категориальных колонок | — |
| `--ai` / `--no-ai` | Включить/отключить LLM-детекцию ПД (авто: включается, если LLM настроен — env `DBDUMP_LLM_URL` или `config/database-dumps.php`). `--no-ai` также подавляет авто-запрос настройки LLM при первом запуске | авто |
| `--deep` | Глубокий анализ: профилирование + ИИ + `sample.criteria` + отчёт `database/analysis/REPORT.md` | — |

**Как распределяются таблицы:**
- Строк <= порога — `full_export`
- Строк > порога — `partial_export` (с limit, автоопределённой сортировкой и шаблоном `where: "1=1"` для удобства редактирования)
- Пустые таблицы — пропускаются
- Служебные таблицы (migrations, sessions, cache_*, telescope_*, oauth_*, audit_*) — пропускаются

<a id="углублённый-анализ"></a>

## Углублённый анализ (--deep / --ai / --criteria)

Анализ с участием ИИ — **основной сценарий** пакета. Опирается на два источника:

- **Прямой LLM** (`openai/gpt-oss-120b` по OpenAI-совместимому API) — анализ **данных**: PII-классификация точнее regex, профилирование, подсказки по выборке. Запросы ограничены по размеру.
- **OPENCODE** (внешний агент) — анализ **кода** целиком: связи без FK (Eloquent `belongsTo/hasMany`, Doctrine-ассоциации, сырые JOIN), карта «колонка → код», ключевые поля и бизнес-сегменты. Пакет готовит вход и инструкции, агент возвращает JSON, который пакет поглощает.

> Если LLM не настроен, анализ мягко деградирует на regex-эвристики — пакет остаётся работоспособным. См. также [Быстрый запуск](#быстрый-запуск-llm--opencode).

### Настройка LLM (интерактивно)

**При первом запуске `prepare-config`** (если настройки LLM ещё не заданы и сессия интерактивна) пакет сам предложит настроить LLM — спросит `API URL`, `Модель` и `Token` (token можно оставить пустым), сохранит несекретное в `config/database-dumps.php`, токен — в `.env.local` (`DBDUMP_LLM_TOKEN`), и применит настройки немедленно в этом же запуске. Отказ тоже запоминается, чтобы не спрашивать снова. Авто-запрос подавляется флагом `--no-ai` и в неинтерактивном режиме (`--no-interaction`, CI).

Настроить или изменить LLM в любой момент можно явной командой — она дополнительно умеет проверить соединение:

```bash
php artisan dbdump:configure-llm                 # Laravel
php bin/console app:dbdump:configure-llm         # Symfony
```

После настройки `--ai`/`--deep` и `prepare-analysis` подхватывают параметры автоматически. Приоритет источников: переменные окружения `DBDUMP_LLM_*` (если задан URL) перекрывают файл `config/database-dumps.php`; токен всегда берётся из окружения (`.env.local` → `.env`) и в файл настроек не пишется. В Symfony файл подтягивается только вне prod.

### LLM-детекция ПД и профилирование

```bash
# Symfony
php bin/console app:dbdump:prepare-config all --deep
php bin/console app:dbdump:prepare-config all --ai          # только LLM-PII
php bin/console app:dbdump:prepare-config all --criteria    # только авто sample.criteria

# Laravel
php artisan dbdump:prepare-config all --deep
```

- `--ai` использует LLM для классификации ПД (regex-результаты подаются как hints; принимаются типы с уверенностью выше порога, маппятся на `fio/email/phone/...`). При недоступном LLM — тихий fallback на regex + предупреждение.
- `--criteria` профилирует категориальные колонки и предлагает `sample.criteria` (по корзине на топ-значение).
- `--deep` включает всё перечисленное + пишет отчёт `database/analysis/REPORT.md` (+ машинный `analysis_result.json`): режим экспорта, предложенные критерии с SQL и обоснованием, ПД (regex vs LLM), профиль колонок.

Переменные окружения LLM:

| Переменная | Назначение | По умолчанию |
|------------|-----------|--------------|
| `DBDUMP_LLM_URL` | Базовый URL OpenAI-совместимого API (например `https://llm.example.com/v1`). Пусто → AI-функции выключены | — |
| `DBDUMP_LLM_MODEL` | Имя модели | `openai/gpt-oss-120b` |
| `DBDUMP_LLM_TOKEN` | Bearer-токен (опционально) | — |
| `DBDUMP_LLM_TIMEOUT` | Таймаут запроса, сек | `120` |
| `DBDUMP_LLM_ENABLED` | `true`/`false`; по умолчанию авто (включено при заданном URL) | авто |

HTTP-запросы выполняются через `ext-curl` (без guzzle). Данные PII в промптах ограничены примерами значений колонок.

### Анализ кода через OPENCODE

**Самый простой путь — одной командой** (нужен `opencode` в PATH):

```bash
php artisan dbdump:prepare-analysis --run            # Laravel
php bin/console app:dbdump:prepare-analysis --run    # Symfony
```

С `--run` модуль сам провижинит пакет, прогонит OPENCODE по чанку на каждую схему и применит результат к `dump_config.yaml`. Если opencode не найден — команда напечатает готовые к вставке строки запуска и не упадёт.

**Ручной путь (3 шага)** — если хотите контролировать прогон агента:

```bash
# 1. Подготовить пакет (агент + инвентарь + контракт + RUN.md)
php bin/console app:dbdump:prepare-analysis        # Symfony
php artisan dbdump:prepare-analysis                # Laravel

# 2. Запустить агента по чанку на схему (точные строки печатает команда из шага 1, см. также RUN.md)
opencode run --agent dbdump-mapper \
  -f database/analysis/schema_inventory.public.json \
  "Обработай схему public по инструкции; результат запиши в database/analysis/out/public.json"

# 3. Применить результат к dump_config.yaml
php bin/console app:dbdump:apply-analysis          # Symfony
php artisan dbdump:apply-analysis                  # Laravel
```

Что провижинит `prepare-analysis` в хост-проект:
- `.opencode/agents/dbdump-mapper.md` — готовый агент (read-only по коду; пишет только в `database/analysis/out/`);
- `.opencode/commands/dbdump-map.md` — слэш-команда для TUI (опционально);
- `database/analysis/schema_inventory.json` — полный инвентарь + `schema_inventory.<schema>.json` по каждой схеме (для прогона по чанку без переполнения контекста 128k), **без значений данных** (PII в OPENCODE не выгружается);
- `database/analysis/output_schema.json` — JSON-контракт ответа;
- `database/analysis/RUN.md` — точные команды запуска и применения.

`apply-analysis` читает `database/analysis/out/*.json`, валидирует против контракта, объединяет чанки и обогащает `dump_config.yaml`: `cascade_from` из кода (с пометкой `source: code` в отчёте) и `sample.criteria` из бизнес-сегментов. Пользовательские правки в приоритете — добавляется только отсутствующее; провенанс/уверенность фиксируются в `database/analysis/REPORT.md`.

Провайдер и модель LLM предполагаются уже настроенными в opencode пользователя (`~/.config/opencode/opencode.json`); агент не задаёт модель явно и наследует дефолтную — отдельной настройки не требуется. Для больших схем дробите прогон по чанку на схему (см. RUN.md).

## Проверка конфига без БД (validate)

`validate` проверяет `dump_config.yaml` и пер-схемные `dump-settings/*.yaml`, **не подключаясь
к базе**. Схему берёт из замороженного слепка `{data_dir}/analysis/schema_inventory.json`
(и пер-схемных `schema_inventory.<schema>.json`), который кладёт `prepare-analysis`. Поэтому
команда работает в CI, в закрытом контуре и до подъёма стенда — в отличие от `repair-configs`,
который гоняет каждый criterion по живой БД.

```bash
# Symfony
php bin/console app:dbdump:validate
php bin/console app:dbdump:validate --format=json --out=database/analysis/findings.json
php bin/console app:dbdump:validate -s pdl -s tasks --severity=warning
php bin/console app:dbdump:validate --fix

# Laravel
php artisan dbdump:validate
php artisan dbdump:validate --format=json --out=database/analysis/findings.json
```

Опции: `--config` и `--inventory` (пути, по умолчанию — `{data_dir}/dump_config.yaml` и
`{data_dir}/analysis/schema_inventory.json`), `-s|--schema` (повторяемая, только эти схемы),
`--format=text|json`, `--out=PATH`, `--severity=error|warning|note` (порог вывода),
`--fix` (применить механически однозначные правки).

**Код возврата — `1`, если есть находки уровня `error`**, иначе `0`. Этого достаточно, чтобы
скрипт или агент решил «готов конфиг к снятию дампа или нет», не разбирая текст вывода.

### Что проверяется

| код | уровень | что нашли |
|---|---|---|
| `S-1` | error | YAML не разобрался или файл из `includes:` пропал |
| `S-2` | error | `TableConfig` отверг настройки таблицы |
| `S-3` | error | таблица и в `full_export`, и в `partial_export` |
| `S-4` | warning | пустая секция / пустая карта `faker` у таблицы |
| `C-1` | warning | таблица есть в слепке, но не выгружается |
| `C-2` | warning | таблица есть в конфиге, но её нет в слепке |
| `C-3` | warning | схема есть на одной стороне и отсутствует на другой |
| `L-1`…`L-7` | error / warning | несуществующие колонки в `order_by`, `where`, `cascade_from`, `sample.criteria`, `stratify_by`, `faker`, `deferred_columns` |
| `Q-1`, `Q-2` | error | в критерии алиас таблицы (`t1.`) или bind-параметр (`:name`) — дампер такой критерий пропустит |
| `Q-3` | error | непригодны ВСЕ критерии таблицы: выборка молча выродится в плоский срез |
| `Q-4` | warning | повторяющиеся имена критериев |
| `Q-5` | warning | сумма квот больше `limit` — объединённую выборку молча обрежет |
| `F-1` | error / warning | faker-паттерн на нетекстовой колонке: на дате/`bytea`/`uuid` INSERT упадёт (error), на числовой — подменит идентификатор (warning) |
| `F-2` | error | паттерн вне `PatternDetector::ALLOWED_PATTERNS` — `FakerConfig` не примет конфиг |
| `G-1` | error | родитель `cascade_from` не выгружается — ограничение молча отбросится |
| `G-2` | error | цикл в `cascade_from` |
| `G-3` | warning | цепочка длиннее `settings.max_cascade_depth` |
| `G-4` | warning | родитель с `sample` выгружается ПОЗЖЕ ребёнка |
| `G-5` | note | родитель с `sample` выгружается раньше — связность держится на порядке имён |
| `G-6` | note | у таблицы и `sample`, и `cascade_from`: cascade не применится |
| `D-1` | note | справочник `*_dict` обрезан лимитом или квотами |
| `H-1` | note | версионная таблица (`date_from`/`date_to`) выбирает только действующие версии |

`G-4` заслуживает отдельного слова. Порядок экспорта строит `TopologicalSorter` по FK из
слепка; в базе **без FK-констрейнтов** рёбер нет вовсе, и порядок вырождается в алфавитный.
Тогда родитель, чьё имя идёт позже имени ребёнка, попадает в дамп уже после него, реестр
выбранных id (`SelectedPkRegistry`) пуст, и `CascadeWhereResolver` откатывается к подзапросу,
который не повторяет `sample.criteria` — набор строк родителя в дампе и в подзапросе расходится.

### Что делает `--fix`

Только то, где правка однозначна и **не меняет состав выборки**: снимает faker-маппинг с
нетекстовой колонки (`F-1`) и с неизвестным паттерном (`F-2`), убирает маппинг несуществующей
колонки (`L-6`) и мёртвую запись `cascade_from` (`L-3`), переименовывает дубль критерия (`Q-4`),
удаляет пустую секцию (`S-4`). Всё остальное остаётся находкой с подсказкой — решение за
человеком.

Правки пишутся в тот файл, откуда пришла схема (`dump-settings/<schema>.yaml` или общий
конфиг); тронутый файл переписывается целиком, поэтому его форматирование становится
каноническим (записи `cascade_from` разворачиваются из однострочной формы в блочную).
Файлы, где чинить нечего, не трогаются. После правок содержимое перепроверяется тем же
`TableConfig`, что и на экспорте: если правка сделала конфиг хуже, файл не записывается.

Проверяется только основное подключение; секция `connections:` в аудит не входит.

## Настройка Symfony

### Регистрация бандла

Flex-рецепта у пакета нет, поэтому бандл нужно зарегистрировать вручную. Добавьте в `config/bundles.php`:

```php
return [
    // ...
    Timbrs\DatabaseDumps\Bridge\Symfony\DatabaseDumpsBundle::class => ['dev' => true, 'test' => true],
];
```

Регистрация в `dev`/`test` соответствует установке через `composer require --dev`. Если пакет ставится в prod (без `--dev`), регистрируйте бандл как `['all' => true]`.

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
│   └── database-dumps.php        # настройки пакета (data_dir + LLM)
├── database/                     # = data_dir (по умолчанию)
│   ├── dump_config.yaml          # главный конфиг с includes
│   ├── dump-settings/            # пер-схемные файлы конфига
│   │   ├── public.yaml
│   │   └── system.yaml
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

# Предпросмотр плана экспорта (без реального выполнения)
php bin/console app:dbdump:export all --dry-run

# Импорт с игнорированием расхождений схемы
php bin/console app:dbdump:import --ignore-schema-mismatch

# Сгенерировать конфигурацию по структуре БД
php bin/console app:dbdump:prepare-config all
php bin/console app:dbdump:prepare-config all --threshold=1000 --force
php bin/console app:dbdump:prepare-config schema=billing
php bin/console app:dbdump:prepare-config table=public.users
php bin/console app:dbdump:prepare-config new --no-cascade --no-faker

# Углублённый анализ
php bin/console app:dbdump:prepare-config all --deep
php bin/console app:dbdump:prepare-analysis --run    # всё одной командой (нужен opencode в PATH)
# или вручную: prepare-analysis → opencode run → apply-analysis

# Проверить конфиг по слепку схемы, без подключения к БД
php bin/console app:dbdump:validate
php bin/console app:dbdump:validate --format=json --out=database/analysis/findings.json
php bin/console app:dbdump:validate --fix

# Проверить sample.criteria на живой БД и починить падающие через opencode
php bin/console app:dbdump:repair-configs --dry-run
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

# Предпросмотр плана экспорта (без реального выполнения)
php artisan dbdump:export all --dry-run

# Импорт с игнорированием расхождений схемы
php artisan dbdump:import --ignore-schema-mismatch

# Сгенерировать конфигурацию по структуре БД
php artisan dbdump:prepare-config all
php artisan dbdump:prepare-config all --threshold=1000 --force
php artisan dbdump:prepare-config schema=billing
php artisan dbdump:prepare-config table=public.users
php artisan dbdump:prepare-config new --no-cascade --no-faker

# Углублённый анализ
php artisan dbdump:prepare-config all --deep
php artisan dbdump:prepare-analysis --run            # всё одной командой (нужен opencode в PATH)
# или вручную: prepare-analysis → opencode run → apply-analysis

# Проверить конфиг по слепку схемы, без подключения к БД
php artisan dbdump:validate
php artisan dbdump:validate --format=json --out=database/analysis/findings.json
php artisan dbdump:validate --fix

# Проверить sample.criteria на живой БД и починить падающие через opencode
php artisan dbdump:repair-configs --dry-run
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
Команда → TableConfigResolver → DatabaseDumper → [FK-сортировка + Cycle Breaking] → DataFetcher → [Cascade WHERE] → [Faker] → SqlGenerator → .sql файлы
```

1. **TableConfigResolver** — читает YAML и собирает список таблиц для экспорта
2. **DatabaseDumper** — управляет процессом экспорта
3. **TableDependencyResolver** — топологическая сортировка таблиц по FK (родители экспортируются первыми). При обнаружении циклов — автоматический разрыв через nullable-рёбра
4. **DataFetcher** — получает данные из БД через `ConnectionRegistry`
5. **CascadeWhereResolver** — генерирует WHERE-подзапросы из `cascade_from` для связности данных
6. **RussianFaker** — заменяет персональные данные (ФИО, email, телефон, пол) на сгенерированные
7. **SqlGenerator** — генерирует SQL: TRUNCATE + INSERT (с NULL для разорванных FK) + сброс счётчиков + UPDATE (восстановление FK)
8. Результат сохраняется в `database/dumps/{schema}/{table}.sql`

### Как работает импорт

```
Команда → DatabaseImporter → ProductionGuard → TransactionManager → [FK-сортировка + Cycle Breaking] → [SchemaValidator] → ScriptExecutor → SqlParser → выполнение
```

1. **ProductionGuard** — проверяет, что мы не на продакшене
2. **TransactionManager** — оборачивает всё в транзакцию
3. **TableDependencyResolver** — топологическая сортировка файлов по FK (родители импортируются первыми), с автоматическим разрывом циклов
4. **SchemaValidator** — сравнивает столбцы дампа со столбцами в БД, предупреждает о расхождениях
5. **ScriptExecutor** — выполняет скрипты из `before_exec/`
6. **SqlParser** / **StatementSplitter** — разбирает .sql файлы на отдельные выражения
7. Выражения выполняются в БД
8. **ScriptExecutor** — выполняет скрипты из `after_exec/`

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
│   │   ├── DeferredUpdateGenerator.php # UPDATE для восстановления разорванных FK
│   │   └── ...
│   ├── Graph/                        # Граф FK-зависимостей
│   │   ├── SortResult.php            #   DTO результата сортировки с deferred-рёбрами
│   │   ├── TableDependencyResolver.php #  FK-граф + топологическая сортировка
│   │   └── TopologicalSorter.php     #   Kahn (BFS) + Tarjan (SCC) + Cycle Breaking
│   ├── Importer/                     # Импорт дампов
│   │   ├── SchemaValidator.php       #   Валидация столбцов дампа vs БД
│   │   ├── ValidationResult.php      #   DTO результата валидации
│   │   └── ...
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
  - [Settings](#settings)
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
- **Smart Cycle Breaking** — automatic resolution of circular FK dependencies (two-phase INSERT NULL → UPDATE) instead of falling back to alphabetical order
- **Self-referential FK** — support for self-referencing tables (category trees, org structures, threaded comments)
- **Schema validation on import** — warnings when dump columns don't match the DB, `--ignore-schema-mismatch` option
- **Config settings** — `settings:` section for batch_size, sample_size, max_cascade_depth
- **Dry-run export** — `--dry-run` option to preview the export plan without executing

## Installation

```bash
composer require --dev timbrs/database-dumps
```

<a id="quick-start"></a>

## Quick Start

<a id="quick-start-symfony"></a>

### Symfony

1. Register the bundle manually in `config/bundles.php` (there is no Flex recipe):

```php
Timbrs\DatabaseDumps\Bridge\Symfony\DatabaseDumpsBundle::class => ['dev' => true, 'test' => true],
```

2. Create `database/dump_config.yaml` (default path; override via `config_path`):

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

When dealing with many tables, the configuration can become unwieldy. The `prepare-config` command splits config into per-schema files by default. The main `dump_config.yaml` lives in `database/`, and per-schema files go into the `database/dump-settings/` subdirectory:

```
database/
├── dump_config.yaml               # main file with includes
└── dump-settings/
    ├── public.yaml                # public schema config
    ├── system.yaml                # system schema config
    └── analytics/                 # named connection
        └── analytics.yaml
```

**Main file (`database/dump_config.yaml`):**

```yaml
includes:
  public: ./dump-settings/public.yaml
  system: ./dump-settings/system.yaml

connections:
  analytics:
    includes:
      analytics: ./dump-settings/analytics/analytics.yaml
```

> 💡 Include paths are written with a `./` prefix so PhpStorm/IDEs treat them as file references: `Ctrl+B` / `Cmd+B` (or Ctrl+click) on a path jumps straight to the target `*.yaml`. The prefix does not affect path resolution (both `./`-prefixed and plain relative paths load fine).

**Schema file (`database/dump-settings/public.yaml`):**

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

### Settings

The `settings` section allows you to customize internal package parameters:

```yaml
settings:
  batch_size: 1000        # rows per INSERT statement (default: 1000)
  sample_size: 200        # rows for PII analysis in PatternDetector (default: 200)
  max_cascade_depth: 10   # max depth for cascade_from subqueries (default: 10)
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
| `all` | Full config regeneration. **Refuses** over an existing config — `--force` required |
| `schema=<name>` | Regenerate one schema, merge into existing config |
| `table=<schema.table>` | Regenerate one table, merge into existing config |
| `new` | Detect and append new tables only (doesn't touch existing entries) |

`all` mode rebuilds the config from scratch and never reads the existing file: hand-tuned
`sample.criteria`, `cascade_from`, `limit` and `faker` are replaced by machine guesses. That is why
the command refuses to run over an existing `dump_config.yaml` and points at the modes that merge
instead of overwriting (`new`, `schema=`, `table=`), plus `repair-configs` and `validate`.
A deliberate rebuild from scratch is `prepare-config all --force`.

**Options:**

| Option | Description | Default |
|--------|-------------|---------|
| `--threshold`, `-t` | Row threshold: tables with rows <= threshold go to full_export, more — to partial_export | 500 |
| `--force`, `-f` | Allow full regeneration over an existing config (only for `all` mode) | — |
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

The package ships no Flex recipe, so the bundle must be registered manually. Add it to `config/bundles.php`:

```php
return [
    // ...
    Timbrs\DatabaseDumps\Bridge\Symfony\DatabaseDumpsBundle::class => ['dev' => true, 'test' => true],
];
```

Registering under `dev`/`test` matches installation via `composer require --dev`. If the package is installed for prod (without `--dev`), register the bundle as `['all' => true]`.

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
│   └── database-dumps.php        # package settings (data_dir + LLM)
├── database/                     # = data_dir (default)
│   ├── dump_config.yaml          # main config with includes
│   ├── dump-settings/            # per-schema config files
│   │   ├── public.yaml
│   │   └── system.yaml
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

# Preview export plan (no actual export)
php bin/console app:dbdump:export all --dry-run

# Import ignoring schema mismatches
php bin/console app:dbdump:import --ignore-schema-mismatch

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

# Preview export plan (no actual export)
php artisan dbdump:export all --dry-run

# Import ignoring schema mismatches
php artisan dbdump:import --ignore-schema-mismatch

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
Command → TableConfigResolver → DatabaseDumper → [FK sorting + Cycle Breaking] → DataFetcher → [Cascade WHERE] → [Faker] → SqlGenerator → .sql files
```

1. **TableConfigResolver** — reads YAML and builds a list of tables to export
2. **DatabaseDumper** — manages the export process
3. **TableDependencyResolver** — topological sorting of tables by FK (parents are exported first). Circular dependencies are automatically broken via nullable edges
4. **DataFetcher** — fetches data from the DB via `ConnectionRegistry`
5. **CascadeWhereResolver** — generates WHERE subqueries from `cascade_from` for data consistency
6. **RussianFaker** — replaces personal data (names, email, phone, gender) with generated values
7. **SqlGenerator** — generates SQL: TRUNCATE + INSERT (with NULL for broken FKs) + counter reset + UPDATE (FK restoration)
8. Result is saved to `database/dumps/{schema}/{table}.sql`

### How Import Works

```
Command → DatabaseImporter → ProductionGuard → TransactionManager → [FK sorting + Cycle Breaking] → [SchemaValidator] → ScriptExecutor → SqlParser → execution
```

1. **ProductionGuard** — checks we're not on production
2. **TransactionManager** — wraps everything in a transaction
3. **TableDependencyResolver** — topological sorting of files by FK (parents are imported first), with automatic cycle breaking
4. **SchemaValidator** — compares dump columns against DB columns, warns about mismatches
5. **ScriptExecutor** — runs scripts from `before_exec/`
6. **SqlParser** / **StatementSplitter** — splits .sql files into individual statements
7. Statements are executed against the DB
8. **ScriptExecutor** — runs scripts from `after_exec/`

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
│   │   ├── DeferredUpdateGenerator.php # UPDATE for restoring broken FK edges
│   │   └── ...
│   ├── Graph/                        # FK dependency graph
│   │   ├── SortResult.php            #   DTO for sort result with deferred edges
│   │   ├── TableDependencyResolver.php #  FK graph + topological sorting
│   │   └── TopologicalSorter.php     #   Kahn (BFS) + Tarjan (SCC) + Cycle Breaking
│   ├── Importer/                     # Dump import
│   │   ├── SchemaValidator.php       #   Dump columns vs DB schema validation
│   │   ├── ValidationResult.php      #   Validation result DTO
│   │   └── ...
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
