<?php
// app/Controllers/Api/AppointmentApiController.php — REST API приёмов (/api/appointments), основной ресурс.
// Все адреса API защищены входом (public/index.php). Создавать и изменять приёмы может любой сотрудник
// (врач отмечает приём проведённым), удалять — только администратор.

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Models\AnimalRepository;
use App\Models\AppointmentRepository;
use App\Models\CachedAppointmentRepository;
use App\Models\DoctorRepository;

final class AppointmentApiController
{
    private AppointmentRepository $appointments;

    public function __construct()
    {
        $this->appointments = new CachedAppointmentRepository();   // приёмы с кешем в Redis
    }

    // GET /api/appointments  или  ?status=scheduled&animal_id=1&doctor_id=2&q=осмотр&sort=-id&limit=50&offset=100
    //   limit (по умолчанию 100, не больше 500) и offset — постраничный вывод. Общее число приёмов — в заголовке X-Total-Count.
    public function index(): void
    {
        $limit = query_int('limit') ?? 100;
        $result = $this->appointments->search(
            query_string('status'),
            query_int('animal_id'),
            query_int('doctor_id'),
            query_string('q'),
            query_string('sort'),
            min(max($limit, 1), 500),
            query_int('offset') ?? 0
        );
        header('X-Total-Count: ' . $result['total']);
        json_response($result['rows']);
    }

    // GET /api/appointments/5
    public function show(string $id): void
    {
        json_response($this->find($id));
    }

    // POST /api/appointments — тело: {"animal_id": 1, "doctor_id": 2, "title": "...", "date": "2026-11-20T10:00"}
    public function store(): void
    {
        $data = json_input() + ['doctor_id' => null, 'status' => 'scheduled'];   // значения по умолчанию
        $this->check($data, 0);
        $item = $this->appointments->add($this->clean($data));
        header('Location: /api/appointments/' . $item['id']);
        json_response($item, 201);
    }

    // PUT или PATCH /api/appointments/5 — меняются только присланные поля
    public function update(string $id): void
    {
        $item = $this->find($id);
        $data = array_merge($item, json_input());
        $this->check($data, $item['id']);
        json_response($this->appointments->update($item['id'], $this->clean($data)));
    }

    // DELETE /api/appointments/5 — только администратор
    public function destroy(string $id): void
    {
        require_role('admin');                             // другая роль — ответ 403
        if (!$this->appointments->delete((int) $id)) {
            json_error('Приём не найден', 404);
        }
        no_content();                                      // медзапись и услуги приёма удалит база (ON DELETE CASCADE)
    }

    private function find(string $id): array
    {
        $item = ctype_digit($id) ? $this->appointments->find((int) $id) : null;
        if ($item === null) {
            json_error('Приём не найден', 404);
        }
        return $item;
    }

    // Поля API → столбцы таблицы. Имена animal, owner, doctor только для чтения и в базу не пишутся.
    private function clean(array $data): array
    {
        return [
            'animal_id'    => $data['animal_id'],
            'doctor_id'    => $data['doctor_id'],
            'title'        => trim($data['title']),
            'scheduled_at' => str_replace('T', ' ', $data['date']),   // 2026-11-20T10:00 → 2026-11-20 10:00
            'status'       => $data['status'],
        ];
    }

    // Проверка данных: 422 с ошибками полей; занятое время врача — 409
    private function check(array $data, int $exceptId): void
    {
        $errors = [];
        if (!is_string($data['title'] ?? null) || mb_strlen(trim($data['title'])) < 3 || mb_strlen($data['title']) > 200) {
            $errors['title'] = 'Причина обращения — от 3 до 200 символов';
        }
        if (!is_int($data['animal_id'] ?? null) || (new AnimalRepository())->find($data['animal_id']) === null) {
            $errors['animal_id'] = 'Выберите животное из списка';
        }
        $doctor = $data['doctor_id'] ?? null;
        if ($doctor !== null && (!is_int($doctor) || (new DoctorRepository())->find($doctor) === null)) {
            $errors['doctor_id'] = 'Выберите врача из списка или оставьте пустым (null)';
        }
        if (!valid_datetime($data['date'] ?? null)) {
            $errors['date'] = 'Дата и время в виде 2026-11-20T10:00';
        }
        if (!in_array($data['status'] ?? null, ['scheduled', 'done', 'cancelled'], true)) {
            $errors['status'] = 'Статус: scheduled, done или cancelled';
        }
        if ($errors) {
            json_error('Ошибка проверки данных', 422, $errors);
        }
        $time = str_replace('T', ' ', $data['date']);
        if ($doctor !== null && $this->appointments->doctorBusy($doctor, $time, $exceptId)) {
            json_error('У врача уже есть приём в это время', 409, ['date' => 'Это время у врача занято, выберите другое']);
        }
    }
}
