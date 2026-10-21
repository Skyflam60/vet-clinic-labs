<?php
namespace App\Controllers\Api;
use App\Models\AppointmentRepository;
use App\Models\AnimalRepository;
use App\Models\OwnerRepository;
class AppointmentApiController {
    private function repository(): AppointmentRepository { return new AppointmentRepository(); }
    private function find(string $id): array {
        $item = $this->repository()->find((int) $id);
        if ($item === null) json_error('Запись не найдена', 404);
        return $item;
    }
    private function clean(array $data): array {
        return array_intersect_key($data, array_flip(['animal_id', 'title', 'doctor', 'date', 'status']));
    }
    private function validate(array $data): array {
        $errors = [];
        if (!is_string($data['title'] ?? null) || mb_strlen(trim($data['title'])) < 3) $errors['title'] = 'Причина — не короче трёх символов';
        if (!is_int($data['animal_id'] ?? null) || !(new AnimalRepository())->find($data['animal_id'])) $errors['animal_id'] = 'Укажите номер существующего животного';
        if (!is_string($data['doctor'] ?? null) || trim($data['doctor']) === '') $errors['doctor'] = 'Укажите врача';
        $date = false;
        if (is_string($data['date'] ?? null) && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/D', $data['date'])) {
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $data['date']);
        }
        if (!$date || $date->format('Y-m-d\TH:i') !== $data['date']) $errors['date'] = 'Дата в виде 2026-11-01T10:00';
        if (!in_array($data['status'] ?? null, ['scheduled', 'done', 'cancelled'], true)) $errors['status'] = 'Неверный статус';
        return $errors;
    }
    public function index(): void {
        $items = $this->repository()->all();
        $status = query('status');
        $animal = query('animal_id');
        $q = query('q');
        $items = array_values(array_filter($items, function ($item) use ($status, $animal, $q) {
            return ($status === '' || $item['status'] === $status)
                && ($animal === '' || (string) $item['animal_id'] === $animal)
                && ($q === '' || mb_stripos($item['title'], $q) !== false);
        }));
        json_response($items);
    }
    public function show(string $id): void { json_response($this->find($id)); }
    public function store(): void {
        $data = array_replace(['status' => 'scheduled'], $this->clean(json_input()));
        $errors = $this->validate($data);
        if ($errors) json_error('Ошибка проверки данных', 422, $errors);
        $item = $this->repository()->add($data);
        header('Location: /api/appointments/' . $item['id']);
        json_response($item, 201);
    }
    public function update(string $id): void {
        $data = array_replace($this->find($id), $this->clean(json_input()));
        $errors = $this->validate($data);
        if ($errors) json_error('Ошибка проверки данных', 422, $errors);
        json_response($this->repository()->update((int) $id, $data));
    }
    public function destroy(string $id): void {
        $this->find($id);
        $this->repository()->delete((int) $id);
        no_content();
    }
}
