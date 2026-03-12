-- Тестовый дамп таблицы
-- Дата экспорта: 2026-01-31 12:00:00
-- Количество записей: 3
-- Режим: full

TRUNCATE TABLE "test"."test_table" CASCADE;

-- Batch 1 (3 rows)
INSERT INTO "test"."test_table" (id, name, email) VALUES
(1, 'User 1', 'user1@example.com'),
(2, 'User 2', 'user2@example.com'),
(3, 'User 3', 'user3@example.com');

-- Сброс sequences
SELECT setval('test.test_table_id_seq', (SELECT COALESCE(MAX(id), 1) FROM "test"."test_table"));
