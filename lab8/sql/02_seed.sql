-- =====================================================================
-- Лабораторная работа №8. Часть 2. Исходные данные
-- Владельцы, животные и приёмы — те же, что в storage/*.json ЛР №7, плюс несколько новых.
-- Запуск в psql:  \i /sql/02_seed.sql   (после 01_schema.sql, на пустых таблицах)
-- Номера (id) не указываются: база присваивает их сама (1, 2, 3, ...).
-- =====================================================================

-- Владельцы. У № 3 и № 5 почты нет (NULL). У № 5 нет животных — он нужен для LEFT JOIN.
INSERT INTO owners (name, phone, email) VALUES
    ('Иванов И.И.',    '+79000000001', 'ivanov@example.org'),
    ('Петрова А.А.',   '+79000000002', 'petrova@example.org'),
    ('Сидоров П.П.',   '+79000000003', NULL),
    ('Кузнецова О.В.', '+79000000004', 'kuznetsova@example.org'),
    ('Смирнов Д.А.',   '+79000000005', NULL);

-- Животные. У Бобика неизвестны дата рождения и вес, у Мурки — порода.
INSERT INTO animals (owner_id, name, species, breed, sex, birth_date, weight_kg) VALUES
    (1, 'Рекс',   'dog',  'Немецкая овчарка',  'm', '2019-05-10', 32.50),
    (2, 'Мурка',  'cat',  NULL,                'f', '2020-03-01',  4.20),
    (3, 'Бобик',  'dog',  'Дворняжка',         'm', NULL,          NULL),
    (4, 'Кеша',   'bird', 'Волнистый попугай', 'm', '2023-06-15',  0.04),
    (1, 'Барсик', 'cat',  'Британская',        'm', '2021-08-20',  5.80);

-- Врачи. Фамилии те же, что в поле doctor ЛР №7. У Сидоровой пока нет приёмов.
INSERT INTO doctors (name, specialty, phone) VALUES
    ('Иванова',  'терапевт',   '+79001110001'),
    ('Петров',   'хирург',     '+79001110002'),
    ('Сидорова', 'дерматолог', NULL);

-- Приёмы 1–6 — из ЛР №7. У приёма № 7 врач ещё не назначен (doctor_id = NULL).
INSERT INTO appointments (animal_id, doctor_id, title, scheduled_at, status) VALUES
    (1, 1,    'Плановый осмотр',         '2026-11-01 10:00', 'scheduled'),
    (2, 2,    'Вакцинация кошки',        '2026-11-02 10:00', 'done'),
    (3, 1,    'Повторный осмотр',        '2026-11-03 10:00', 'cancelled'),
    (1, 2,    'Осмотр уха',              '2026-11-04 10:00', 'scheduled'),
    (2, 1,    'Обработка раны',          '2026-11-05 10:00', 'done'),
    (3, 2,    'Проверка зубов',          '2026-11-06 10:00', 'cancelled'),
    (4, NULL, 'Подрезка когтей',         '2026-11-07 11:00', 'scheduled'),
    (5, 1,    'Вакцинация от бешенства', '2026-10-20 12:00', 'done');

-- Медицинские записи (1:1) — у завершённых приёмов. Жалобы и назначения могут быть пустыми.
INSERT INTO medical_records (appointment_id, complaints, diagnosis, prescriptions) VALUES
    (2, NULL,           'Здорова, вакцинация проведена', 'Ревакцинация через год'),
    (5, 'Рана на лапе', 'Поверхностная рана',            'Хлоргексидин 2 раза в день, 5 дней'),
    (8, NULL,           'Здоров',                        NULL);

-- Прайс-лист. «Чистка зубов» ещё ни разу не оказывалась.
INSERT INTO services (name, price) VALUES
    ('Первичный осмотр', 1200.00),
    ('Вакцинация',       1800.00),
    ('Обработка раны',    900.00),
    ('Чистка зубов',     2500.00),
    ('Подрезка когтей',   500.00);

-- Услуги приёмов (N:M). Цена копируется из прайса на момент приёма.
INSERT INTO appointment_services (appointment_id, service_id, quantity, price) VALUES
    (1, 1, 1, 1200.00),
    (2, 1, 1, 1200.00),
    (2, 2, 1, 1800.00),
    (5, 1, 1, 1200.00),
    (5, 3, 2,  900.00),
    (7, 5, 1,  500.00),
    (8, 2, 1, 1800.00);

SELECT 'owners' AS table_name, COUNT(*) FROM owners
UNION ALL SELECT 'animals',              COUNT(*) FROM animals
UNION ALL SELECT 'doctors',              COUNT(*) FROM doctors
UNION ALL SELECT 'appointments',         COUNT(*) FROM appointments
UNION ALL SELECT 'medical_records',      COUNT(*) FROM medical_records
UNION ALL SELECT 'services',             COUNT(*) FROM services
UNION ALL SELECT 'appointment_services', COUNT(*) FROM appointment_services;
