-- =====================================================================
-- Лабораторная работа №9. Структура базы данных ветеринарной клиники (PostgreSQL)
-- Таблицы из лабораторной работы №8 без изменений, плюс пользователи системы (users)
-- с хешами паролей и ролями и токены входа (auth_tokens).
-- Выполняется командой php bin/reset-db.php. Старые таблицы удаляются и создаются заново:
-- не запускайте этот файл на базе с данными, которые жалко потерять.
-- =====================================================================

SET client_min_messages = warning;   -- не показывать сообщения «таблицы ещё нет»
DROP TABLE IF EXISTS auth_tokens, users, appointment_services, services, medical_records,
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

-- ---------------------------------------------------------------------
-- Пользователи системы (сотрудники клиники, которые входят в программу).
-- Пароль хранится только в виде хеша (password_hash из PHP), открытого пароля в базе нет.
-- Роли: admin — администратор клиники, registrar — регистратор, doctor — врач.
-- ---------------------------------------------------------------------
CREATE TABLE users (
    id             SERIAL PRIMARY KEY,
    name           VARCHAR(100) NOT NULL CHECK (char_length(name) >= 2),
    email          VARCHAR(255) NOT NULL UNIQUE,
    role           VARCHAR(20)  NOT NULL DEFAULT 'registrar'
                   CHECK (role IN ('admin', 'registrar', 'doctor')),
    password_hash  VARCHAR(255) NOT NULL,
    created_at     TIMESTAMP    NOT NULL DEFAULT NOW()
);

-- ---------------------------------------------------------------------
-- Токены входа. Хранится не сам токен, а его хеш (SHA-256): утечка таблицы не даёт войти в систему.
-- При удалении пользователя его токены удаляются вместе с ним (CASCADE).
-- ---------------------------------------------------------------------
CREATE TABLE auth_tokens (
    token_hash  CHAR(64) PRIMARY KEY,
    user_id     INTEGER NOT NULL REFERENCES users (id) ON DELETE CASCADE,
    expires_at  TIMESTAMPTZ NOT NULL
);

-- Индексы на внешние ключи и частые условия запросов API (лабораторная работа №8)
CREATE INDEX idx_animals_owner_id          ON animals (owner_id);
CREATE INDEX idx_appointments_animal_id    ON appointments (animal_id);
CREATE INDEX idx_appointments_scheduled_at ON appointments (scheduled_at);
CREATE INDEX idx_auth_tokens_user_id       ON auth_tokens (user_id);
