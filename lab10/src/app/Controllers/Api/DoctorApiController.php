<?php
// app/Controllers/Api/DoctorApiController.php — REST API врачей (/api/doctors).
// Список нужен всем сотрудникам (выбор врача в форме записи); добавлять, изменять и удалять — только администратор.

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Models\CachedDoctorRepository;
use App\Models\DoctorRepository;

final class DoctorApiController
{
    private DoctorRepository $doctors;

    public function __construct()
    {
        $this->doctors = new CachedDoctorRepository();         // справочник врачей с кешем
    }

    // GET /api/doctors
    public function index(): void
    {
        json_response($this->doctors->all());
    }

    // GET /api/doctors/1
    public function show(string $id): void
    {
        json_response($this->find($id));
    }

    // POST /api/doctors — тело: {"name": "...", "specialty": "хирург", "phone": null}
    public function store(): void
    {
        require_role('admin');
        $data = json_input() + ['specialty' => 'терапевт', 'phone' => null, 'is_active' => true];
        $this->check($data);
        $doctor = $this->doctors->add($this->clean($data));
        header('Location: /api/doctors/' . $doctor['id']);
        json_response($doctor, 201);
    }

    // PUT или PATCH /api/doctors/1
    public function update(string $id): void
    {
        require_role('admin');
        $doctor = $this->find($id);
        $data = array_merge($doctor, json_input());
        $this->check($data);
        json_response($this->doctors->update($doctor['id'], $this->clean($data)));
    }

    // DELETE /api/doctors/1 — приёмы врача остаются без врача (ON DELETE SET NULL в базе)
    public function destroy(string $id): void
    {
        require_role('admin');
        $doctor = $this->find($id);
        $this->doctors->delete($doctor['id']);
        no_content();
    }

    private function find(string $id): array
    {
        $doctor = ctype_digit($id) ? $this->doctors->find((int) $id) : null;
        if ($doctor === null) {
            json_error('Врач не найден', 404);
        }
        return $doctor;
    }

    private function clean(array $data): array
    {
        $phone = trim((string) ($data['phone'] ?? ''));
        return [
            'name'      => trim($data['name']),
            'specialty' => trim($data['specialty']),
            'phone'     => $phone === '' ? null : $phone,
            'is_active' => $data['is_active'],
        ];
    }

    private function check(array $data): void
    {
        $errors = [];
        if (!is_string($data['name'] ?? null) || mb_strlen(trim($data['name'])) < 3 || mb_strlen($data['name']) > 150) {
            $errors['name'] = 'ФИО — от 3 до 150 символов';
        }
        if (!is_string($data['specialty'] ?? null) || trim($data['specialty']) === '') {
            $errors['specialty'] = 'Укажите специальность';
        }
        if (($data['phone'] ?? null) !== null && !is_string($data['phone'])) {
            $errors['phone'] = 'Телефон — строка или null';
        }
        if (!is_bool($data['is_active'] ?? null)) {
            $errors['is_active'] = 'is_active — true или false';
        }
        if ($errors) {
            json_error('Ошибка проверки данных', 422, $errors);
        }
    }
}
