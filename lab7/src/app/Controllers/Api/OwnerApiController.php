<?php
namespace App\Controllers\Api;
use App\Models\AppointmentRepository;
use App\Models\AnimalRepository;
use App\Models\OwnerRepository;
class OwnerApiController {
    private function repository(): OwnerRepository { return new OwnerRepository(); }
    private function find(string $id): array {
        $item = $this->repository()->find((int) $id);
        if ($item === null) json_error('Запись не найдена', 404);
        return $item;
    }
    private function clean(array $data): array {
        return array_intersect_key($data, array_flip(['name', 'phone', 'email']));
    }
    private function validate(array $data): array {
        $errors = [];
        if (!is_string($data['name'] ?? null) || mb_strlen(trim($data['name'])) < 3) $errors['name'] = 'ФИО — не короче трёх символов';
        if (!is_string($data['phone'] ?? null) || trim($data['phone']) === '') $errors['phone'] = 'Укажите телефон';
        if (!is_string($data['email'] ?? null) || ($data['email'] !== '' && !filter_var($data['email'], FILTER_VALIDATE_EMAIL))) $errors['email'] = 'Неверная электронная почта';
        return $errors;
    }
    public function index(): void {
        $items = $this->repository()->all();
        json_response($items);
    }
    public function show(string $id): void { json_response($this->find($id)); }
    public function store(): void {
        $data = array_replace(['email' => ''], $this->clean(json_input()));
        $errors = $this->validate($data);
        if ($errors) json_error('Ошибка проверки данных', 422, $errors);
        $item = $this->repository()->add($data);
        header('Location: /api/owners/' . $item['id']);
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
        if ((new AnimalRepository())->byOwner((int) $id)) json_error('У владельца есть животные', 409);
        $this->repository()->delete((int) $id);
        no_content();
    }
}
