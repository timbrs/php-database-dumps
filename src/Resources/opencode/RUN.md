# Анализ кода хоста через OPENCODE

Этот пакет провижинит готовый агент и входные данные. Сам прогон OPENCODE запускаешь ты вручную
(модуль его не вызывает), затем поглощаешь результат командой `apply-analysis`.

## Самый простой путь — одной командой

```bash
# Laravel
php artisan dbdump:prepare-analysis --run
# Symfony
php bin/console app:dbdump:prepare-analysis --run
```

С флагом `--run` модуль сам прогонит OPENCODE по чанку на каждую схему и применит результат
(нужен `opencode` в PATH). Если opencode не найден — команда напечатает готовые к вставке строки
запуска (см. ниже) и не упадёт.

## Что провижинено

- `.opencode/agents/dbdump-mapper.md` — агент (read-only по коду, запись только в `database/analysis/out/`).
- `.opencode/commands/dbdump-map.md` — слэш-команда для запуска из TUI (опционально).
- `database/analysis/schema_inventory.json` — полный инвентарь (обзор / маленькие БД).
- `database/analysis/schema_inventory.<schema>.json` — пер-схемный инвентарь (для прогона по чанку на схему,
  чтобы не переполнять контекст 128k на больших БД).
- `database/analysis/output_schema.json` — контракт JSON-вывода.
- `database/analysis/out/` — каталог, куда агент пишет результаты.

Провайдер и модель по умолчанию настраиваются в `~/.config/opencode/opencode.json`; агент `dbdump-mapper`
не задаёт модель явно и наследует дефолтную — отдельная настройка обычно не нужна.

## Проверка агента

```bash
opencode agent list      # в списке должен быть dbdump-mapper
```

## Запуск

```bash
cd <host-project>
opencode run --agent dbdump-mapper \
  -f database/analysis/schema_inventory.json \
  "Построй карту связей и использования колонок по инструкции; результат запиши в database/analysis/out/"
```

Многосхемный/большой проект (контекст 128k) — дроби по чанку на схему, используя ПЕР-СХЕМНЫЙ инвентарь
(каждый прогон пишет частичный `out/<schema>.json`):

```bash
opencode run --agent dbdump-mapper -f database/analysis/schema_inventory.public.json \
  "Обработай схему public по инструкции; результат — database/analysis/out/public.json"
```

Полезные флаги:

- `--format json` или `--print-logs` — смотреть прогресс/поток событий.
- `--dangerously-skip-permissions` — авто-аппрув прав (агент уже ограничен read-only + запись в out/).
- `--model <provider>/<model>` — явно указать модель (по умолчанию наследуется из настроек opencode).

## Чтение вывода

Агент пишет файлы `database/analysis/out/*.json` по контракту `output_schema.json` (ключи
`relationships` / `columns` / `criteria`).

## Применение

```bash
# Laravel
php artisan dbdump:apply-analysis

# Symfony
php bin/console app:dbdump:apply-analysis
```

`apply-analysis` читает `database/analysis/out/*.json`, валидирует против контракта, объединяет чанки,
обогащает `dump_config.yaml` (`cascade_from` с пометкой `source: code`, `sample.criteria`) и дополняет
`database/analysis/REPORT.md`. Пользовательские правки в YAML в приоритете — добавляется только отсутствующее.
