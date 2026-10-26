-- =====================================================================
-- Лабораторная работа №8. Часть 3. Проверка ссылочной целостности и ограничений
-- Проверки 1–12 НАРУШАЮТ правило: база должна ответить ERROR. Ошибка здесь — успех.
-- Проверки 13–14 (CASCADE и SET NULL) выполняются успешно, поэтому они
-- обёрнуты в транзакцию с ROLLBACK, и данные после скрипта не меняются.
-- Запуск в psql:  \i /sql/03_integrity.sql   (после 02_seed.sql)
-- =====================================================================

\echo '--- 1. Приём несуществующего животного (FOREIGN KEY) ---'
INSERT INTO appointments (animal_id, doctor_id, title, scheduled_at)
VALUES (99, 1, 'Осмотр', '2026-12-01 10:00');
-- ожидаем: violates foreign key constraint "appointments_animal_id_fkey"

\echo '--- 2. Неизвестный статус приёма (CHECK) ---'
INSERT INTO appointments (animal_id, doctor_id, title, scheduled_at, status)
VALUES (1, 1, 'Осмотр', '2026-12-01 10:00', 'finished');
-- ожидаем: violates check constraint "appointments_status_check"

\echo '--- 3. Слишком короткая причина обращения (CHECK, как проверка в API) ---'
INSERT INTO appointments (animal_id, doctor_id, title, scheduled_at)
VALUES (1, 1, 'Да', '2026-12-01 10:00');
-- ожидаем: violates check constraint "appointments_title_check"

\echo '--- 4. Приём без даты и времени (NOT NULL) ---'
INSERT INTO appointments (animal_id, doctor_id, title, scheduled_at)
VALUES (1, 1, 'Осмотр', NULL);
-- ожидаем: null value in column "scheduled_at" ... violates not-null constraint

\echo '--- 5. Второй владелец с тем же телефоном (UNIQUE) ---'
INSERT INTO owners (name, phone) VALUES ('Иванов Двойник', '+79000000001');
-- ожидаем: violates unique constraint "owners_phone_key"

\echo '--- 6. Врач Иванова уже занята в это время (составной UNIQUE) ---'
INSERT INTO appointments (animal_id, doctor_id, title, scheduled_at)
VALUES (2, 1, 'Осмотр', '2026-11-01 10:00');
-- ожидаем: violates unique constraint "appointments_doctor_time_key"

\echo '--- 7. Вторая медицинская запись к приёму № 2 (связь 1:1, PRIMARY KEY) ---'
INSERT INTO medical_records (appointment_id, diagnosis) VALUES (2, 'Повторная запись');
-- ожидаем: violates unique constraint "medical_records_pkey"

\echo '--- 8. Услуга «Первичный осмотр» в приёме № 2 повторно (связь N:M, составной PRIMARY KEY) ---'
INSERT INTO appointment_services (appointment_id, service_id, price) VALUES (2, 1, 1200.00);
-- ожидаем: violates unique constraint "appointment_services_pkey"

\echo '--- 9. Отрицательная цена услуги (CHECK) ---'
INSERT INTO services (name, price) VALUES ('Скидка', -100.00);
-- ожидаем: violates check constraint "services_price_check"

\echo '--- 10. Удаление владельца, у которого есть животные (ON DELETE RESTRICT) ---'
DELETE FROM owners WHERE id = 1;
-- ожидаем: violates foreign key constraint "animals_owner_id_fkey" (в API — ответ 409)

\echo '--- 11. Удаление животного, у которого есть приёмы (ON DELETE RESTRICT) ---'
DELETE FROM animals WHERE id = 1;
-- ожидаем: violates foreign key constraint "appointments_animal_id_fkey" (в API — ответ 409)

\echo '--- 12. Удаление услуги, которая уже оказывалась (ON DELETE RESTRICT) ---'
DELETE FROM services WHERE id = 1;
-- ожидаем: violates foreign key constraint "appointment_services_service_id_fkey"

\echo '--- 13. Удаление приёма удаляет его медзапись и услуги (ON DELETE CASCADE) ---'
BEGIN;
SELECT (SELECT COUNT(*) FROM medical_records      WHERE appointment_id = 5) AS records_before,
       (SELECT COUNT(*) FROM appointment_services WHERE appointment_id = 5) AS services_before;
DELETE FROM appointments WHERE id = 5;
SELECT (SELECT COUNT(*) FROM medical_records      WHERE appointment_id = 5) AS records_after,
       (SELECT COUNT(*) FROM appointment_services WHERE appointment_id = 5) AS services_after;
ROLLBACK;

\echo '--- 14. Удаление врача оставляет его приёмы без врача (ON DELETE SET NULL) ---'
BEGIN;
DELETE FROM doctors WHERE id = 2;
SELECT id, title, doctor_id FROM appointments WHERE id IN (2, 4, 6) ORDER BY id;
ROLLBACK;

\echo '--- Данные не изменились ---'
SELECT (SELECT COUNT(*) FROM appointments)    AS appointments,
       (SELECT COUNT(*) FROM medical_records) AS records,
       (SELECT COUNT(*) FROM doctors)         AS doctors;
-- ожидаем: 8, 3, 3
