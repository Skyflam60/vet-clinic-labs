-- =====================================================================
-- Лабораторная работа №10. Большой объём данных ветеринарной клиники для измерения скорости
-- На 8 приёмах любой запрос выполняется мгновенно и кеш не даёт заметного выигрыша.
-- Поэтому добавляем 47 врачей (всего 50), 3 000 владельцев, 5 000 животных, 500 000 приёмов
-- и около 450 000 строк оказанных услуг. Загрузка: php bin/load-bigdata.php (10–30 секунд).
-- Генерация — как в 05_test_data.sql лабораторной работы №8.
-- Внимание: автотесты рассчитаны на небольшую базу; перед ними выполните php bin/reset-db.php (тесты делают это сами).
-- =====================================================================

SELECT setseed(0.42);                                   -- одинаковое «случайное» заполнение при каждом запуске

INSERT INTO doctors (name, specialty)
SELECT 'Врач №' || i,
       (ARRAY['терапевт', 'терапевт', 'хирург', 'дерматолог', 'офтальмолог'])[1 + floor(random() * 5)::int]
FROM generate_series(1, 47) AS i;

-- телефон уникален: номер владельца входит в телефон
INSERT INTO owners (name, phone, email)
SELECT 'Владелец №' || i,
       '+7901' || lpad(i::text, 7, '0'),
       CASE WHEN random() < 0.7 THEN 'owner' || i || '@example.org' END   -- у 30% почты нет
FROM generate_series(1, 3000) AS i;

-- Номера владельцев собираем в массив и выбираем из него случайно: внешние ключи всегда указывают на существующие строки
INSERT INTO animals (owner_id, name, species, sex, weight_kg)
SELECT o.ids[1 + floor(random() * cardinality(o.ids))::int],
       'Питомец №' || g.i,
       CASE WHEN g.r < 0.45 THEN 'dog' WHEN g.r < 0.85 THEN 'cat' WHEN g.r < 0.92 THEN 'bird'
            WHEN g.r < 0.97 THEN 'rodent' ELSE 'other' END,
       (ARRAY['m', 'f'])[1 + floor(random() * 2)::int],
       round((0.1 + random() * 40)::numeric, 2)
FROM (SELECT i, random() AS r FROM generate_series(1, 5000) AS i) AS g,
     (SELECT array_agg(id) AS ids FROM owners) AS o;

-- Приёмы. Ограничение UNIQUE (doctor_id, scheduled_at) запрещает врачу два приёма в одно время, поэтому время
-- не случайное: i-й приём попадает к врачу (i mod 47) в его очередной 30-минутный слот (16 слотов в день, с 9:00).
WITH d AS (SELECT array_agg(id ORDER BY id) AS ids FROM doctors WHERE name LIKE 'Врач №%'),
     an AS (SELECT array_agg(id) AS ids FROM animals)
INSERT INTO appointments (animal_id, doctor_id, title, scheduled_at, status, created_at)
SELECT an.ids[1 + floor(random() * cardinality(an.ids))::int],
       d.ids[1 + (g.i - 1) % 47],
       (ARRAY['Плановый осмотр', 'Вакцинация', 'Обработка раны', 'Осмотр уха',
              'Проверка зубов', 'Кастрация', 'Повторный осмотр', 'Подрезка когтей'])[1 + floor(random() * 8)::int]
           || ' №' || g.i,                              -- номер в причине нужен для сценария «поиск по части текста»
       TIMESTAMP '2024-01-01 09:00'
           + (((g.i - 1) / 47) / 16) * INTERVAL '1 day'
           + (((g.i - 1) / 47) % 16) * INTERVAL '30 minutes',
       CASE WHEN g.r < 0.60 THEN 'done' WHEN g.r < 0.85 THEN 'scheduled' ELSE 'cancelled' END,
       TIMESTAMP '2024-01-01' + random() * INTERVAL '700 days'
FROM (SELECT i, random() AS r FROM generate_series(1, 500000) AS i) AS g, d, an;

-- Услуги (связь N:M): у каждого проведённого приёма первичный осмотр, у половины — ещё одна услуга. Цена из прайса.
INSERT INTO appointment_services (appointment_id, service_id, quantity, price)
SELECT a.id, s.id, 1, s.price
FROM appointments a
JOIN services s ON s.name = 'Первичный осмотр'
WHERE a.status = 'done' AND a.doctor_id > 3;

INSERT INTO appointment_services (appointment_id, service_id, quantity, price)
SELECT x.id, s.id, x.qty, s.price
FROM (SELECT a.id, 2 + floor(random() * 4)::int AS sid, 1 + floor(random() * 2)::int AS qty
      FROM appointments a
      WHERE a.status = 'done' AND a.doctor_id > 3 AND random() < 0.5) AS x
JOIN services s ON s.id = x.sid;

ANALYZE;                                                -- обновить статистику для планировщика запросов
