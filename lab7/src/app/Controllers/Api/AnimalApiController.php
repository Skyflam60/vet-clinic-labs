<?php
namespace App\Controllers\Api;
use App\Models\AppointmentRepository;
use App\Models\AnimalRepository;
use App\Models\OwnerRepository;
class AnimalApiController {
    private function repository(): AnimalRepository { return new AnimalRepository(); }
    private function find(string $id): array {
        $item = $this->repository()->find((int) $id);
        if ($item === null) json_error('Запись не найдена', 404);
        return $item;
    }
    private function clean(array $data): array {
        return array_intersect_key($data, array_flip(['name', 'owner_id']));
    }
    private function validate(array $data): array {
        $errors = [];
        if (!is_string($data['name'] ?? null) || mb_strlen(trim($data['name'])) < 3) $errors['name'] = 'Кличка — не короче трёх символов';
        if (!is_int($data['owner_id'] ?? null) || !(new OwnerRepository())->find($data['owner_id'])) $errors['owner_id'] = 'Укажите номер существующего владельца';
        return $errors;
    }
    public function index(): void {
        $items = $this->repository()->all();
        json_response($items);
    }
    public function show(string $id): void { json_response($this->find($id)); }
    public function store(): void {
        $data = array_replace([], $this->clean(json_input()));
        $errors = $this->validate($data);
        if ($errors) json_error('Ошибка проверки данных', 422, $errors);
        $item = $this->repository()->add($data);
        header('Location: /api/animals/' . $item['id']);
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
        if ((new AppointmentRepository())->byAnimal((int) $id)) json_error('У животного есть приёмы', 409);
        $this->repository()->delete((int) $id);
        no_content();
    }
    public function appointments(string $id): void {
        $this->find($id);
        json_response((new AppointmentRepository())->byAnimal((int) $id));
    }
}
