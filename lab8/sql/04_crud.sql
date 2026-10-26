-- =====================================================================
-- Лабораторная работа №8. Часть 4. CRUD-операции
-- Запуск в psql:  \i /sql/04_crud.sql   (после 02_seed.sql и 03_integrity.sql)
-- Рядом с каждым запросом — адрес REST API из ЛР №7, который будет его выполнять,
-- когда API подключится к базе (следующая лабораторная работа).
-- Правило: перед UPDATE и DELETE — SELECT с тем же WHERE.
-- =====================================================================

-- =====================================================================
-- ПРИЁМЫ (основная сущность)
-- =====================================================================

\echo '=== CREATE: записать животное на приём (POST /api/appointments) ==='
-- RETURNING возвращает созданную строку; \gset сохраняет номер в переменную :appointment_id.
-- Номер будет 14, а не 9: номера 9–13 израсходованы на неудачные вставки в 03_integrity.sql.
INSERT INTO appointments (animal_id, doctor_id, title, scheduled_at)
VALUES (5, 3, 'Осмотр кожи', '2026-11-10 12:00')
RETURNING id AS appointment_id \gset
SELECT id, animal_id, doctor_id, title, scheduled_at, status
FROM appointments WHERE id = :appointment_id;

\echo '=== CREATE: назначить на приём две услуги (N:M), цена берётся из прайса ==='
INSERT INTO appointment_services (appointment_id, service_id, quantity, price)
SELECT :appointment_id, id, 1, price FROM services WHERE id IN (1, 4);

-- ---------------------------------------------------------------------
-- READ
-- ---------------------------------------------------------------------

\echo '=== READ: список с фильтром и сортировкой (GET /api/appointments?status=scheduled&animal_id=1) ==='
SELECT id, animal_id, title, scheduled_at, status
FROM appointments
WHERE status = 'scheduled' AND animal_id = 1
ORDER BY scheduled_at;

\echo '=== READ: поиск по причине обращения (GET /api/appointments?q=осмотр) ==='
SELECT id, title, status FROM appointments WHERE title ILIKE '%осмотр%' ORDER BY id;

\echo '=== READ: один приём в формате API ЛР №7 (GET /api/appointments/1) ==='
-- Имя врача теперь не хранится в приёме, его подставляет LEFT JOIN.
SELECT a.id, a.animal_id, a.title, d.name AS doctor,
       to_char(a.scheduled_at, 'YYYY-MM-DD"T"HH24:MI') AS date, a.status
FROM appointments a
LEFT JOIN doctors d ON d.id = a.doctor_id
WHERE a.id = 1;

\echo '=== READ (JOIN): журнал приёмов — животное, владелец, врач ==='
-- animals и owners у приёма есть всегда — обычный JOIN;
-- врач может быть не назначен — LEFT JOIN, иначе приём № 7 пропадёт.
SELECT a.id, a.scheduled_at, an.name AS animal, o.name AS owner,
       d.name AS doctor, a.title, a.status
FROM appointments a
JOIN animals an     ON an.id = a.animal_id
JOIN owners o       ON o.id = an.owner_id
LEFT JOIN doctors d ON d.id = a.doctor_id
ORDER BY a.scheduled_at;

\echo '=== READ: приёмы без врача (IS NULL, а не = NULL) ==='
SELECT id, title, scheduled_at FROM appointments WHERE doctor_id IS NULL;

\echo '=== READ: история лечения животного (GET /api/animals/2/appointments, FR-07) ==='
-- LEFT JOIN к medical_records: у запланированных и отменённых приёмов записи нет.
SELECT a.id, a.scheduled_at, a.title, a.status, m.diagnosis, m.prescriptions
FROM appointments a
LEFT JOIN medical_records m ON m.appointment_id = a.id
WHERE a.animal_id = 2
ORDER BY a.scheduled_at DESC;

\echo '=== READ (N:M): услуги и сумма каждого приёма (счёт) ==='
SELECT a.id, a.title,
       string_agg(s.name || ' ×' || x.quantity, ', ' ORDER BY s.name) AS services,
       SUM(x.quantity * x.price) AS total
FROM appointments a
JOIN appointment_services x ON x.appointment_id = a.id
JOIN services s             ON s.id = x.service_id
GROUP BY a.id, a.title
ORDER BY a.id;

\echo '=== READ (GROUP BY): отчёт руководителя — загрузка и выручка врачей ==='
-- LEFT JOIN, чтобы в отчёт попали и врачи без приёмов.
-- Выручка считается в подзапросе, иначе JOIN с услугами размножил бы строки приёмов.
SELECT d.name, d.specialty,
       COUNT(a.id)                                       AS total,
       COUNT(a.id) FILTER (WHERE a.status = 'done')      AS done,
       COUNT(a.id) FILTER (WHERE a.status = 'scheduled') AS scheduled,
       COALESCE(SUM((SELECT SUM(x.quantity * x.price)
                     FROM appointment_services x
                     WHERE x.appointment_id = a.id)) FILTER (WHERE a.status = 'done'), 0) AS revenue
FROM doctors d
LEFT JOIN appointments a ON a.doctor_id = d.id
GROUP BY d.id, d.name, d.specialty
ORDER BY d.id;

-- ---------------------------------------------------------------------
-- UPDATE
-- ---------------------------------------------------------------------

\echo '=== UPDATE: перенести приём на день позже (PATCH /api/appointments/{id}, FR-04) ==='
SELECT id, scheduled_at, status FROM appointments WHERE id = :appointment_id;
UPDATE appointments SET scheduled_at = scheduled_at + INTERVAL '1 day'
WHERE id = :appointment_id
RETURNING id, scheduled_at, status;

\echo '=== UPDATE: приём проведён — статус done и медицинская запись (FR-08) ==='
UPDATE appointments SET status = 'done' WHERE id = :appointment_id RETURNING id, status;
INSERT INTO medical_records (appointment_id, complaints, diagnosis)
VALUES (:appointment_id, 'Зуд, расчёсы за ушами', 'Аллергический дерматит');

\echo '=== UPDATE: врач дополняет медицинскую запись (FR-09) ==='
UPDATE medical_records SET prescriptions = 'Гипоаллергенный корм, осмотр через 2 недели'
WHERE appointment_id = :appointment_id
RETURNING appointment_id, diagnosis, prescriptions;

-- ---------------------------------------------------------------------
-- DELETE
-- ---------------------------------------------------------------------

\echo '=== DELETE: удалить приём (DELETE /api/appointments/{id}) ==='
SELECT id, title FROM appointments WHERE id = :appointment_id;
DELETE FROM appointments WHERE id = :appointment_id RETURNING id, title;
\echo 'Медзапись и услуги удалены каскадом:'
SELECT (SELECT COUNT(*) FROM medical_records      WHERE appointment_id = :appointment_id) AS records_left,
       (SELECT COUNT(*) FROM appointment_services WHERE appointment_id = :appointment_id) AS services_left;
\echo 'Повторное удаление — DELETE 0, в API это ответ 404:'
DELETE FROM appointments WHERE id = :appointment_id;

-- =====================================================================
-- ВЛАДЕЛЬЦЫ И ЖИВОТНЫЕ (остальные методы API ЛР №7)
-- =====================================================================

\echo '=== POST /api/owners и POST /api/animals ==='
INSERT INTO owners (name, phone) VALUES ('Морозова Е.Н.', '+79000000009')
RETURNING id AS owner_id \gset
INSERT INTO animals (owner_id, name) VALUES (:owner_id, 'Тузик')
RETURNING id AS animal_id \gset
SELECT id, owner_id, name, species FROM animals WHERE id = :animal_id;

\echo '=== GET /api/owners: владельцы и число их животных (LEFT JOIN + GROUP BY) ==='
SELECT o.id, o.name, o.phone, COALESCE(o.email, '') AS email, COUNT(an.id) AS animals
FROM owners o
LEFT JOIN animals an ON an.owner_id = o.id
GROUP BY o.id
ORDER BY o.id;

\echo '=== PATCH /api/owners/{id} и PATCH /api/animals/{id} ==='
UPDATE owners  SET email = 'morozova@example.org' WHERE id = :owner_id RETURNING id, name, email;
UPDATE animals SET name = 'Тузик-младший', species = 'dog' WHERE id = :animal_id RETURNING id, name, species;

\echo '=== DELETE /api/animals/{id}, затем DELETE /api/owners/{id} ==='
-- Сначала животное: пока оно есть, владельца удалить нельзя (RESTRICT).
DELETE FROM animals WHERE id = :animal_id RETURNING id, name;
DELETE FROM owners  WHERE id = :owner_id  RETURNING id, name;
