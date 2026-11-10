<?php
// app/Models/AppointmentRepository.php — модель приёмов (таблица appointments), основная сущность системы.
//
// Согласование с API лабораторной работы №7:
//   — в базе время приёма хранится в столбце scheduled_at (TIMESTAMP), а API называет поле date и передаёт его строкой
//     2026-11-01T10:00 (как в ЛР №5 и №7). Преобразование делает to_char прямо в SELECT и RETURNING;
//   — врач в ЛР №7 был строкой doctor, в базе ЛР №8 он вынесен в таблицу doctors (нормализация). Теперь в приёме
//     хранится doctor_id, а имена животного, владельца и врача (animal, owner, doctor) подставляются подзапросами
//     только для чтения: клиенту не нужно делать отдельные запросы, чтобы показать таблицу.

declare(strict_types=1);

namespace App\Models;

class AppointmentRepository extends PgRepository
{
    protected string $table = 'appointments';
    protected string $columns = "id, animal_id, doctor_id, title,
        to_char(scheduled_at, 'YYYY-MM-DD\"T\"HH24:MI') AS date, status,
        (SELECT a.name FROM animals a WHERE a.id = appointments.animal_id) AS animal,
        (SELECT o.name FROM animals a JOIN owners o ON o.id = a.owner_id WHERE a.id = appointments.animal_id) AS owner,
        (SELECT d.name FROM doctors d WHERE d.id = appointments.doctor_id) AS doctor";

    // Приёмы одного животного (история лечения), сначала новые
    public function byAnimal(int $animalId): array
    {
        $stmt = $this->pdo()->prepare("SELECT {$this->columns} FROM appointments WHERE animal_id = :animal_id ORDER BY scheduled_at DESC, id");
        $stmt->execute(['animal_id' => $animalId]);
        return $stmt->fetchAll();
    }

    // Есть ли у врача другой приём в то же время (ограничение UNIQUE (doctor_id, scheduled_at) из ЛР №8)
    public function doctorBusy(int $doctorId, string $scheduledAt, int $exceptId = 0): bool
    {
        $stmt = $this->pdo()->prepare(
            'SELECT 1 FROM appointments WHERE doctor_id = :doctor_id AND scheduled_at = :scheduled_at AND id <> :id'
        );
        $stmt->execute(['doctor_id' => $doctorId, 'scheduled_at' => $scheduledAt, 'id' => $exceptId]);
        return $stmt->fetchColumn() !== false;
    }

    // Страница списка приёмов и общее число подходящих приёмов. Отбор делает база данных.
    //   $status   — 'scheduled', 'done', 'cancelled' или '' (любой)
    //   $animalId — номер животного или null; $doctorId — номер врача или null
    //   $q        — часть причины обращения или '' (без поиска)
    //   $sort     — 'id', 'date', 'title' (с «-» — по убыванию) или '' (по возрастанию номера)
    //   $limit, $offset — сколько строк взять и сколько пропустить (постраничный вывод)
    // Возвращает ['rows' => [...приёмы страницы...], 'total' => число всех подходящих приёмов].
    // Два запроса к базе (строки и подсчёт) — поэтому список приёмов стоит кешировать.
    public function search(string $status, ?int $animalId, ?int $doctorId, string $q, string $sort, int $limit = 100, int $offset = 0): array
    {
        $where = ' WHERE 1 = 1';
        $params = [];

        if ($status !== '') {
            $where .= ' AND status = :status';
            $params['status'] = $status;
        }
        if ($animalId !== null) {
            $where .= ' AND animal_id = :animal_id';
            $params['animal_id'] = $animalId;
        }
        if ($doctorId !== null) {
            $where .= ' AND doctor_id = :doctor_id';
            $params['doctor_id'] = $doctorId;
        }
        if ($q !== '') {
            // ILIKE — поиск без учёта регистра; % и _ экранируем, чтобы они искались как обычные символы
            $where .= ' AND title ILIKE :q';
            $params['q'] = '%' . addcslashes($q, '%_\\') . '%';
        }

        // Имя столбца в ORDER BY параметром передать нельзя: берём его из списка допустимых.
        // Поле API date соответствует столбцу scheduled_at.
        $columns = ['id' => 'id', 'date' => 'scheduled_at', 'title' => 'title'];
        $field = $columns[ltrim($sort, '-')] ?? 'id';
        $order = " ORDER BY $field " . (str_starts_with($sort, '-') ? 'DESC' : 'ASC') . ', id';

        $stmt = $this->pdo()->prepare("SELECT {$this->columns} FROM appointments" . $where . $order . ' LIMIT :limit OFFSET :offset');
        $stmt->execute($params + ['limit' => $limit, 'offset' => $offset]);
        $rows = $stmt->fetchAll();

        $stmt = $this->pdo()->prepare('SELECT COUNT(*) FROM appointments' . $where);
        $stmt->execute($params);

        return ['rows' => $rows, 'total' => (int) $stmt->fetchColumn()];
    }
}
