-- =====================================================================
-- Лабораторная работа №8. Часть 1. Структура базы данных
-- Предметная область: система управления ветеринарной клиникой
-- Запуск в psql:  \i /sql/01_schema.sql
-- Скрипт можно выполнять повторно: сначала удаляются старые таблицы.
-- =====================================================================

SET client_min_messages = warning;   -- не показывать сообщения «таблицы ещё нет»
DROP VIEW  IF EXISTS doctor_workload;
DROP TABLE IF EXISTS appointment_services, services, medical_records,
                     appointments, doctors, animals, owners CASCADE;

-- ---------------------------------------------------------------------
-- Владельцы животных (ресурс /api/owners из ЛР №7)
-- Почта необязательна: в API пустая строка, в базе NULL.
-- UNIQUE разрешает несколько NULL, поэтому владельцы без почты не мешают друг другу.
-- ---------------------------------------------------------------------
CREATE TABLE owners (
    id          SERIAL PRIMARY KEY,
    name        VARCHAR(150) NOT NULL CHECK (char_length(name) >= 3),
    phone       VARCHAR(20)  NOT NULL UNIQUE,
    email       VARCHAR(255) UNIQUE CHECK (email LIKE '%_@_%'),
    created_at  TIMESTAMP    NOT NULL DEFAULT NOW()
);

-- ---------------------------------------------------------------------
-- Животные (ресурс /api/animals). Связь 1:N с owners.
-- Владельца с животными удалить нельзя (RESTRICT, в API — ответ 409).
-- Вид по умолчанию 'other': API ЛР №7 передаёт только кличку и владельца.
-- ---------------------------------------------------------------------
CREATE TABLE animals (
    id          SERIAL PRIMARY KEY,
    owner_id    INTEGER      NOT NULL REFERENCES owners (id) ON DELETE RESTRICT,
    name        VARCHAR(100) NOT NULL CHECK (char_length(name) >= 3),
    species     VARCHAR(20)  NOT NULL DEFAULT 'other'
                CHECK (species IN ('dog', 'cat', 'bird', 'rodent', 'other')),
    breed       VARCHAR(100),
    sex         CHAR(1)      CHECK (sex IN ('m', 'f')),
    birth_date  DATE,
    weight_kg   NUMERIC(5, 2) CHECK (weight_kg > 0),
    created_at  TIMESTAMP    NOT NULL DEFAULT NOW()
);

-- ---------------------------------------------------------------------
-- Врачи. В ЛР №7 врач хранился строкой в каждом приёме; по правилу
-- нормализации имя врача вынесено в отдельную таблицу, в приёме — только номер.
-- ---------------------------------------------------------------------
CREATE TABLE doctors (
    id          SERIAL PRIMARY KEY,
    name        VARCHAR(150) NOT NULL CHECK (char_length(name) >= 3),
    specialty   VARCHAR(100) NOT NULL DEFAULT 'терапевт',
    phone       VARCHAR(20)  UNIQUE,
    is_active   BOOLEAN      NOT NULL DEFAULT TRUE
);

-- ---------------------------------------------------------------------
-- Приёмы — основная сущность (ресурс /api/appointments).
-- 1:N с animals: животное с приёмами удалить нельзя (RESTRICT, как 409 в API).
-- 1:N с doctors: врач уволен и удалён — приём остаётся без врача (SET NULL).
-- UNIQUE (doctor_id, scheduled_at): у врача не может быть двух приёмов в одно время.
-- ---------------------------------------------------------------------
CREATE TABLE appointments (
    id           SERIAL PRIMARY KEY,
    animal_id    INTEGER      NOT NULL REFERENCES animals (id) ON DELETE RESTRICT,
    doctor_id    INTEGER      REFERENCES doctors (id) ON DELETE SET NULL,
    title        VARCHAR(200) NOT NULL CHECK (char_length(title) >= 3),
    scheduled_at TIMESTAMP    NOT NULL,
    status       VARCHAR(20)  NOT NULL DEFAULT 'scheduled'
                 CHECK (status IN ('scheduled', 'done', 'cancelled')),
    created_at   TIMESTAMP    NOT NULL DEFAULT NOW(),
    CONSTRAINT appointments_doctor_time_key UNIQUE (doctor_id, scheduled_at)
);

-- ---------------------------------------------------------------------
-- Медицинская запись — результат приёма. Связь 1:1 с appointments:
-- первичный ключ одновременно является внешним, поэтому у приёма не больше одной записи.
-- Удалили приём — удалилась и запись (CASCADE).
-- ---------------------------------------------------------------------
CREATE TABLE medical_records (
    appointment_id INTEGER PRIMARY KEY REFERENCES appointments (id) ON DELETE CASCADE,
    complaints     TEXT,
    diagnosis      TEXT      NOT NULL CHECK (char_length(diagnosis) >= 3),
    prescriptions  TEXT,
    created_at     TIMESTAMP NOT NULL DEFAULT NOW()
);

-- ---------------------------------------------------------------------
-- Услуги клиники (прайс-лист). Деньги — NUMERIC, не REAL/FLOAT.
-- ---------------------------------------------------------------------
CREATE TABLE services (
    id          SERIAL PRIMARY KEY,
    name        VARCHAR(100)   NOT NULL UNIQUE,
    price       NUMERIC(10, 2) NOT NULL CHECK (price >= 0)
);

-- ---------------------------------------------------------------------
-- Связь N:M «приём — услуга». На приёме несколько услуг, услуга бывает на многих приёмах.
-- Составной первичный ключ не даёт добавить одну услугу в приём дважды
-- (для повтора увеличивается quantity).
-- price — цена на момент приёма: прайс может измениться, а счёт — нет.
-- Удалили приём — строки удалились (CASCADE); услугу, которая уже оказывалась,
-- удалить нельзя (RESTRICT), чтобы не потерять историю счетов.
-- ---------------------------------------------------------------------
CREATE TABLE appointment_services (
    appointment_id INTEGER        NOT NULL REFERENCES appointments (id) ON DELETE CASCADE,
    service_id     INTEGER        NOT NULL REFERENCES services (id)     ON DELETE RESTRICT,
    quantity       INTEGER        NOT NULL DEFAULT 1 CHECK (quantity > 0),
    price          NUMERIC(10, 2) NOT NULL CHECK (price >= 0),
    PRIMARY KEY (appointment_id, service_id)
);
