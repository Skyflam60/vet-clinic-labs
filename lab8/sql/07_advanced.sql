-- =====================================================================
-- Лабораторная работа №8. Повышенная сложность
-- Запуск в psql:  \i /sql/07_advanced.sql   (после 06_optimization.sql)
--   1) поиск по части телефона с индексом pg_trgm;
--   2) частичный индекс только по запланированным приёмам;
--   3) представление (VIEW) для отчёта руководителя;
--   4) транзакция «врач увольняется — передать приёмы другому врачу»;
--   5) пользователь базы для приложения с минимальными правами.
-- =====================================================================

-- ---------------------------------------------------------------------
-- 1. Поиск владельца по части телефона (FR-11).
-- B-tree ищет по началу строки, для '%...%' нужен триграммный индекс:
-- строка режется на тройки символов, и индекс GIN находит строки, где они встречаются.
-- ---------------------------------------------------------------------
CREATE EXTENSION IF NOT EXISTS pg_trgm;
DROP INDEX IF EXISTS idx_owners_phone_trgm;

\echo '=== pg_trgm: поиск по части телефона, до ==='
EXPLAIN ANALYZE
SELECT id, name, phone FROM owners WHERE phone LIKE '%0012345%';

CREATE INDEX idx_owners_phone_trgm ON owners USING gin (phone gin_trgm_ops);

\echo '=== pg_trgm: поиск по части телефона, после ==='
EXPLAIN ANALYZE
SELECT id, name, phone FROM owners WHERE phone LIKE '%0012345%';


-- ---------------------------------------------------------------------
-- 2. Частичный индекс: в индекс попадают только строки, подходящие условию WHERE.
-- Запланированных приёмов 25%, поэтому индекс в несколько раз меньше обычного,
-- а запрос 3 из 06_optimization.sql он обслуживает так же.
-- ---------------------------------------------------------------------
DROP INDEX IF EXISTS idx_appointments_scheduled_only;
CREATE INDEX idx_appointments_scheduled_only ON appointments (scheduled_at)
WHERE status = 'scheduled';

\echo '=== Частичный индекс: размер в сравнении с обычными ==='
SELECT indexrelname AS index_name,
       pg_size_pretty(pg_relation_size(indexrelid)) AS size
FROM pg_stat_user_indexes
WHERE indexrelname IN ('idx_appointments_scheduled_only',
                       'idx_appointments_status_time',
                       'idx_appointments_scheduled_at')
ORDER BY pg_relation_size(indexrelid);

\echo '=== Частичный индекс в плане запроса (составной временно отключён в транзакции) ==='
BEGIN;
DROP INDEX idx_appointments_status_time;
EXPLAIN ANALYZE
SELECT id, animal_id, doctor_id, title, scheduled_at FROM appointments
WHERE status = 'scheduled' AND scheduled_at >= '2026-06-01'
ORDER BY scheduled_at LIMIT 20;
ROLLBACK;


-- ---------------------------------------------------------------------
-- 3. Представление — сохранённый запрос, к которому обращаются как к таблице.
-- Отчёт руководителя: загрузка и выручка каждого врача.
-- ---------------------------------------------------------------------
CREATE OR REPLACE VIEW doctor_workload AS
SELECT d.id AS doctor_id, d.name, d.specialty, d.is_active,
       COUNT(a.id)                                       AS total,
       COUNT(a.id) FILTER (WHERE a.status = 'done')      AS done,
       COUNT(a.id) FILTER (WHERE a.status = 'scheduled') AS scheduled,
       COUNT(a.id) FILTER (WHERE a.status = 'cancelled') AS cancelled,
       COALESCE(SUM(r.amount) FILTER (WHERE a.status = 'done'), 0) AS revenue
FROM doctors d
LEFT JOIN appointments a ON a.doctor_id = d.id
LEFT JOIN (SELECT appointment_id, SUM(quantity * price) AS amount
           FROM appointment_services GROUP BY appointment_id) r
       ON r.appointment_id = a.id
GROUP BY d.id, d.name, d.specialty, d.is_active;

\echo '=== VIEW: пять врачей с наибольшей выручкой ==='
SELECT name, specialty, total, done, revenue
FROM doctor_workload ORDER BY revenue DESC LIMIT 5;

\echo '=== VIEW: врачи из исходных данных ==='
SELECT name, specialty, total, done, scheduled, revenue
FROM doctor_workload WHERE doctor_id <= 3 ORDER BY doctor_id;


-- ---------------------------------------------------------------------
-- 4. Транзакция из нескольких шагов: врач увольняется — его запланированные
-- приёмы переходят другому врачу, а сам врач помечается неактивным.
-- Либо выполняются оба шага, либо ни одного.
-- ---------------------------------------------------------------------
\echo '=== Транзакция с ошибкой: приёмы Врача №1 передаём Врачу №2 ==='
-- У сгенерированных врачей одинаковая сетка слотов, поэтому у Врача №2 в те же часы
-- свои приёмы, и UNIQUE (doctor_id, scheduled_at) отклонит второй шаг.
SELECT id AS from_id FROM doctors WHERE name = 'Врач №1' \gset
SELECT id AS to_id   FROM doctors WHERE name = 'Врач №2' \gset
BEGIN;
UPDATE doctors SET is_active = FALSE WHERE id = :from_id;
UPDATE appointments SET doctor_id = :to_id
WHERE doctor_id = :from_id AND status = 'scheduled';
COMMIT;   -- после ошибки COMMIT выполняет ROLLBACK
\echo 'Первый шаг тоже отменён — врач по-прежнему активен:'
SELECT name, is_active FROM doctors WHERE id = :from_id;

\echo '=== Транзакция без ошибки: приёмы Петрова передаём Сидоровой ==='
SELECT id, title, scheduled_at, doctor_id FROM appointments
WHERE doctor_id = 2 AND status = 'scheduled';
BEGIN;
UPDATE appointments SET doctor_id = 3
WHERE doctor_id = 2 AND status = 'scheduled';
UPDATE doctors SET is_active = FALSE WHERE id = 2;
COMMIT;
SELECT id, title, scheduled_at, doctor_id FROM appointments WHERE id = 4;
SELECT name, is_active FROM doctors WHERE id IN (2, 3) ORDER BY id;


-- ---------------------------------------------------------------------
-- 5. Пользователь для PHP-приложения: только чтение и изменение данных.
-- Менять структуру базы (CREATE, DROP, ALTER) он не может.
-- Пароль учебный; в настоящей системе он хранится вне репозитория.
-- ---------------------------------------------------------------------
SET client_min_messages = warning;
DO $$
BEGIN
    IF EXISTS (SELECT FROM pg_roles WHERE rolname = 'vet_app') THEN
        EXECUTE 'DROP OWNED BY vet_app';
        DROP ROLE vet_app;
    END IF;
END $$;
RESET client_min_messages;

CREATE ROLE vet_app LOGIN PASSWORD 'app_secret';
GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO vet_app;
GRANT USAGE ON ALL SEQUENCES IN SCHEMA public TO vet_app;   -- для SERIAL при INSERT

\echo '=== Проверка прав vet_app: SELECT разрешён, DROP TABLE — нет ==='
SET ROLE vet_app;
SELECT COUNT(*) AS owners FROM owners;
DROP TABLE services;
-- ожидаем: ERROR: must be owner of table services
RESET ROLE;
