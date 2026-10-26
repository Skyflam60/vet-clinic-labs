-- =====================================================================
-- Лабораторная работа №8. Часть 5. Большой объём тестовых данных
-- На 8 приёмах любой запрос выполняется мгновенно, индексы не видны.
-- Генерируем 50 врачей, 100 000 владельцев, 150 000 животных, 500 000 приёмов,
-- около 300 000 медицинских записей и около 450 000 строк услуг.
-- Запуск в psql:  \i /sql/05_test_data.sql   (после 02_seed.sql; 20–60 секунд)
-- =====================================================================

\timing on

-- одинаковое «случайное» заполнение при каждом запуске
SELECT setseed(0.42);

INSERT INTO doctors (name, specialty)
SELECT 'Врач №' || i,
       (ARRAY['терапевт', 'терапевт', 'хирург', 'дерматолог', 'офтальмолог'])[1 + floor(random() * 5)::int]
FROM generate_series(1, 50) AS i;

-- телефон уникален: номер владельца входит в телефон
INSERT INTO owners (name, phone, email)
SELECT 'Владелец №' || i,
       '+7901' || lpad(i::text, 7, '0'),
       CASE WHEN random() < 0.7 THEN 'owner' || i || '@example.org' END   -- у 30% почты нет
FROM generate_series(1, 100000) AS i;

-- Номера владельцев собираем в массив и выбираем из него случайно:
-- так внешние ключи всегда указывают на существующие строки.
WITH o AS (SELECT array_agg(id) AS ids FROM owners)
INSERT INTO animals (owner_id, name, species, sex, weight_kg)
SELECT o.ids[1 + floor(random() * cardinality(o.ids))::int],
       'Питомец №' || g.i,
       CASE WHEN g.r < 0.45 THEN 'dog'
            WHEN g.r < 0.85 THEN 'cat'
            WHEN g.r < 0.92 THEN 'bird'
            WHEN g.r < 0.97 THEN 'rodent'
            ELSE 'other' END,
       (ARRAY['m', 'f'])[1 + floor(random() * 2)::int],
       round((0.1 + random() * 40)::numeric, 2)
-- random() для вида считается в подзапросе g: так он свой у каждой строки
FROM (SELECT i, random() AS r FROM generate_series(1, 150000) AS i) AS g, o;

-- Приёмы. Ограничение UNIQUE (doctor_id, scheduled_at) запрещает врачу два приёма
-- в одно время, поэтому время не случайное: i-й приём попадает к врачу (i mod 50)
-- в его очередной слот. Слоты — по 30 минут с 9:00 до 17:00, 16 в день,
-- 625 дней начиная с 2025-01-01. Так все пары «врач — время» различны.
WITH d AS (SELECT array_agg(id ORDER BY id) AS ids FROM doctors WHERE name LIKE 'Врач №%'),
     an AS (SELECT array_agg(id) AS ids FROM animals)
INSERT INTO appointments (animal_id, doctor_id, title, scheduled_at, status, created_at)
SELECT an.ids[1 + floor(random() * cardinality(an.ids))::int],
       d.ids[1 + (g.i - 1) % 50],
       (ARRAY['Плановый осмотр', 'Вакцинация', 'Обработка раны', 'Осмотр уха',
              'Проверка зубов', 'Кастрация', 'Повторный осмотр', 'Подрезка когтей'])[1 + floor(random() * 8)::int],
       TIMESTAMP '2025-01-01 09:00'
           + (((g.i - 1) / 50) / 16) * INTERVAL '1 day'
           + (((g.i - 1) / 50) % 16) * INTERVAL '30 minutes',
       CASE WHEN g.r < 0.60 THEN 'done'                -- 60% проведено
            WHEN g.r < 0.85 THEN 'scheduled'           -- 25% запланировано
            ELSE 'cancelled' END,                      -- 15% отменено
       TIMESTAMP '2025-01-01' + random() * INTERVAL '600 days'
FROM (SELECT i, random() AS r FROM generate_series(1, 500000) AS i) AS g, d, an;

-- Медицинские записи и услуги добавляются только к сгенерированным приёмам
-- (их проводят «Врач №...»), исходные данные из 02_seed.sql не меняются.
CREATE TEMP TABLE gen_doctors AS SELECT id FROM doctors WHERE name LIKE 'Врач №%';

-- Медицинские записи (1:1) — у проведённых приёмов.
INSERT INTO medical_records (appointment_id, complaints, diagnosis, prescriptions)
SELECT a.id,
       CASE WHEN random() < 0.5 THEN 'Жалобы владельца к приёму №' || a.id END,
       (ARRAY['Здоров', 'Отит', 'Дерматит', 'Гастрит', 'Зубной камень', 'Ушиб лапы'])[1 + floor(random() * 6)::int],
       CASE WHEN random() < 0.6 THEN 'Назначения к приёму №' || a.id END
FROM appointments a
WHERE a.status = 'done' AND a.doctor_id IN (SELECT id FROM gen_doctors);

-- Услуги (N:M): у каждого проведённого приёма первичный осмотр (id = 1)
-- и у половины — ещё одна случайная услуга (id 2–5), поэтому пары не повторяются.
-- Цена берётся из прайса.
INSERT INTO appointment_services (appointment_id, service_id, quantity, price)
SELECT a.id, s.id, 1, s.price
FROM appointments a
JOIN services s ON s.name = 'Первичный осмотр'
WHERE a.status = 'done' AND a.doctor_id IN (SELECT id FROM gen_doctors);

INSERT INTO appointment_services (appointment_id, service_id, quantity, price)
SELECT x.id, s.id, x.qty, s.price
FROM (SELECT a.id, 2 + floor(random() * 4)::int AS sid, 1 + floor(random() * 2)::int AS qty
      FROM appointments a
      WHERE a.status = 'done' AND a.doctor_id IN (SELECT id FROM gen_doctors)
        AND random() < 0.5) AS x
JOIN services s ON s.id = x.sid;

-- обновить статистику, по которой планировщик выбирает способ выполнения запроса
ANALYZE;

\timing off

SELECT 'owners' AS table_name, COUNT(*) FROM owners
UNION ALL SELECT 'animals',              COUNT(*) FROM animals
UNION ALL SELECT 'doctors',              COUNT(*) FROM doctors
UNION ALL SELECT 'appointments',         COUNT(*) FROM appointments
UNION ALL SELECT 'medical_records',      COUNT(*) FROM medical_records
UNION ALL SELECT 'services',             COUNT(*) FROM services
UNION ALL SELECT 'appointment_services', COUNT(*) FROM appointment_services
ORDER BY 1;
