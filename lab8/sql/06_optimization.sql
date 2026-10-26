-- =====================================================================
-- Лабораторная работа №8. Часть 5. Анализ и оптимизация SQL-запросов
-- Запуск в psql:  \i /sql/06_optimization.sql   (после 05_test_data.sql)
--
-- Для каждого частого запроса API:
--   1) EXPLAIN ANALYZE без индекса  — план и время;
--   2) создаём индекс;
--   3) EXPLAIN ANALYZE с индексом   — сравниваем.
-- Время зависит от компьютера: важно не само число, а во сколько раз оно изменилось.
-- Скрипт можно запускать повторно: в начале созданные им индексы удаляются.
-- =====================================================================

DROP INDEX IF EXISTS idx_appointments_animal_id,
                     idx_animals_owner_id,
                     idx_appointments_status_time,
                     idx_appointments_scheduled_at;
ANALYZE appointments;
ANALYZE animals;

\echo 'Индексы до оптимизации (только PRIMARY KEY и UNIQUE):'
SELECT tablename, indexname FROM pg_indexes
WHERE tablename IN ('appointments', 'animals') ORDER BY tablename, indexname;


-- ---------------------------------------------------------------------
-- Запрос 1. История приёмов животного (GET /api/animals/{id}/appointments)
-- Условие по внешнему ключу animal_id. PostgreSQL НЕ создаёт индекс для внешнего ключа сам.
-- ---------------------------------------------------------------------
\echo '=== Запрос 1. Приёмы животного: до ==='
EXPLAIN ANALYZE
SELECT id, title, scheduled_at, status FROM appointments
WHERE animal_id = 1234 ORDER BY scheduled_at DESC;

CREATE INDEX idx_appointments_animal_id ON appointments (animal_id);

\echo '=== Запрос 1. Приёмы животного: после ==='
EXPLAIN ANALYZE
SELECT id, title, scheduled_at, status FROM appointments
WHERE animal_id = 1234 ORDER BY scheduled_at DESC;


-- ---------------------------------------------------------------------
-- Запрос 2. Животные владельца (карточка владельца, FR-02)
-- Тоже внешний ключ: animals.owner_id.
-- ---------------------------------------------------------------------
\echo '=== Запрос 2. Животные владельца: до ==='
EXPLAIN ANALYZE
SELECT id, name, species FROM animals WHERE owner_id = 777 ORDER BY id;

CREATE INDEX idx_animals_owner_id ON animals (owner_id);

\echo '=== Запрос 2. Животные владельца: после ==='
EXPLAIN ANALYZE
SELECT id, name, species FROM animals WHERE owner_id = 777 ORDER BY id;


-- ---------------------------------------------------------------------
-- Для сравнения: расписание ОДНОГО врача на день (выполняется до запроса 3,
-- пока отдельного индекса по scheduled_at ещё нет). Отдельный индекс не нужен:
-- UNIQUE (doctor_id, scheduled_at) сам создал индекс, и условие идёт по его первому столбцу.
-- ---------------------------------------------------------------------
\echo '=== Расписание врача на день: работает индекс ограничения UNIQUE ==='
EXPLAIN ANALYZE
SELECT id, animal_id, title, scheduled_at FROM appointments
WHERE doctor_id = 10 AND scheduled_at >= '2026-03-02' AND scheduled_at < '2026-03-03'
ORDER BY scheduled_at;


-- ---------------------------------------------------------------------
-- Запрос 3. Ближайшие запланированные приёмы, первая страница
-- (GET /api/appointments?status=scheduled, сортировка по времени, 20 штук).
-- Составной индекс: сначала столбец из WHERE с равенством (status),
-- затем столбец сортировки и диапазона (scheduled_at). Строки в индексе
-- уже лежат в нужном порядке, поэтому шаг Sort не нужен.
-- ---------------------------------------------------------------------
\echo '=== Запрос 3. Ближайшие запланированные приёмы: до ==='
EXPLAIN ANALYZE
SELECT id, animal_id, doctor_id, title, scheduled_at FROM appointments
WHERE status = 'scheduled' AND scheduled_at >= '2026-06-01'
ORDER BY scheduled_at LIMIT 20;

CREATE INDEX idx_appointments_status_time ON appointments (status, scheduled_at);

\echo '=== Запрос 3. Ближайшие запланированные приёмы: после ==='
EXPLAIN ANALYZE
SELECT id, animal_id, doctor_id, title, scheduled_at FROM appointments
WHERE status = 'scheduled' AND scheduled_at >= '2026-06-01'
ORDER BY scheduled_at LIMIT 20;


-- ---------------------------------------------------------------------
-- Запрос 4. Расписание клиники на день для администратора (FR-03, FR-06)
-- Условие только по scheduled_at. Составные индексы с этим столбцом уже есть:
-- (doctor_id, scheduled_at) от UNIQUE и (status, scheduled_at) из запроса 3,
-- но scheduled_at в них стоит НЕ первым. Отсортированы они сначала по первому столбцу,
-- поэтому базе приходится просматривать такой индекс целиком. Нужен индекс по scheduled_at.
-- ---------------------------------------------------------------------
\echo '=== Запрос 4. Расписание клиники на день: до ==='
EXPLAIN ANALYZE
SELECT id, doctor_id, animal_id, title, scheduled_at FROM appointments
WHERE scheduled_at >= '2026-03-02' AND scheduled_at < '2026-03-03'
ORDER BY scheduled_at, doctor_id;

CREATE INDEX idx_appointments_scheduled_at ON appointments (scheduled_at);

\echo '=== Запрос 4. Расписание клиники на день: после ==='
EXPLAIN ANALYZE
SELECT id, doctor_id, animal_id, title, scheduled_at FROM appointments
WHERE scheduled_at >= '2026-03-02' AND scheduled_at < '2026-03-03'
ORDER BY scheduled_at, doctor_id;


-- ---------------------------------------------------------------------
-- Когда индекс НЕ помогает
-- ---------------------------------------------------------------------
\echo '=== Индекс не помогает 1: условию подходит 60% строк ==='
-- Отчёт: проведённые приёмы по врачам. Индекс (status, scheduled_at) есть, но условию
-- подходят 300 000 строк из 500 000: прочитать таблицу подряд быстрее, чем
-- переходить по индексу к каждой строке. Планировщик выбирает Seq Scan.
EXPLAIN ANALYZE
SELECT doctor_id, COUNT(*) FROM appointments WHERE status = 'done' GROUP BY doctor_id;

\echo '=== Индекс не помогает 2: поиск владельца по части телефона ==='
-- UNIQUE-индекс по phone есть, но B-tree ищет по началу строки, а не по подстроке '%...%'.
-- Решение — индекс pg_trgm (см. 07_advanced.sql).
EXPLAIN ANALYZE
SELECT id, name, phone FROM owners WHERE phone LIKE '%0012345%';


-- ---------------------------------------------------------------------
-- Цена индексов: место на диске и замедление записи
-- ---------------------------------------------------------------------
\echo '=== Размер индексов ==='
SELECT relname AS table_name, indexrelname AS index_name,
       pg_size_pretty(pg_relation_size(indexrelid)) AS size
FROM pg_stat_user_indexes
WHERE relname IN ('appointments', 'animals')
ORDER BY relname, pg_relation_size(indexrelid) DESC;

-- Вставка 50 000 приёмов с новыми индексами и без них. Обе вставки
-- отменяются ROLLBACK; DROP INDEX внутри транзакции тоже отменяется.
\echo '=== Вставка 50 000 приёмов БЕЗ трёх новых индексов ==='
BEGIN;
DROP INDEX idx_appointments_animal_id, idx_appointments_status_time, idx_appointments_scheduled_at;
\timing on
INSERT INTO appointments (animal_id, doctor_id, title, scheduled_at)
SELECT 1, NULL, 'Проверка скорости', TIMESTAMP '2027-01-01' + i * INTERVAL '1 minute'
FROM generate_series(1, 50000) AS i;
\timing off
ROLLBACK;

\echo '=== Вставка 50 000 приёмов С тремя новыми индексами ==='
BEGIN;
\timing on
INSERT INTO appointments (animal_id, doctor_id, title, scheduled_at)
SELECT 1, NULL, 'Проверка скорости', TIMESTAMP '2027-01-01' + i * INTERVAL '1 minute'
FROM generate_series(1, 50000) AS i;
\timing off
ROLLBACK;
