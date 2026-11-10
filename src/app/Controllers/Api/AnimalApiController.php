<?php
// app/Controllers/Api/AnimalApiController.php — REST API животных (/api/animals).
// Смотреть может любой сотрудник; добавлять и изменять — администратор и регистратор; удалять — только администратор.

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Models\AnimalRepository;
use App\Models\AppointmentRepository;
use App\Models\OwnerRepository;

final class AnimalApiController
{
    private AnimalRepository $animals;

    public function __construct()
    {
        $this->animals = new AnimalRepository();
    }

    // GET /api/animals  или  ?owner_id=1
    public function index(): void
    {
        $owner = query_int('owner_id');
        json_response($owner === null ? $this->animals->all() : $this->animals->byOwner($owner));
    }

    // GET /api/animals/1
    public function show(string $id): void
    {
        json_response($this->find($id));
    }

    // GET /api/animals/1/appointments — история приёмов животного
    public function appointments(string $id): void
    {
        $animal = $this->find($id);
        json_response((new AppointmentRepository())->byAnimal($animal['id']));
    }

    // POST /api/animals — тело: {"name": "Тузик", "owner_id": 1, "species": "dog"}
    public function store(): void
    {
        require_role('admin', 'registrar');
        $data = json_input() + ['species' => 'other', 'breed' => null];
        $this->check($data);
        $animal = $this->animals->add($this->clean($data));
        header('Location: /api/animals/' . $animal['id']);
        json_response($animal, 201);
    }

    // PUT или PATCH /api/animals/1
    public function update(string $id): void
    {
        require_role('admin', 'registrar');
        $animal = $this->find($id);
        $data = array_merge($animal, json_input());
        $this->check($data);
        json_response($this->animals->update($animal['id'], $this->clean($data)));
    }

    // DELETE /api/animals/1 — только администратор; животное с приёмами удалить нельзя (409)
    public function destroy(string $id): void
    {
        require_role('admin');
        $animal = $this->find($id);
        if ((new AppointmentRepository())->byAnimal($animal['id'])) {
            json_error('Нельзя удалить животное: у него есть приёмы', 409);
        }
        $this->animals->delete($animal['id']);
        no_content();
    }

    private function find(string $id): array
    {
        $animal = ctype_digit($id) ? $this->animals->find((int) $id) : null;
        if ($animal === null) {
            json_error('Животное не найдено', 404);
        }
        return $animal;
    }

    private function clean(array $data): array
    {
        $breed = is_string($data['breed'] ?? null) ? trim($data['breed']) : '';
        return [
            'owner_id' => $data['owner_id'],
            'name'     => trim($data['name']),
            'species'  => $data['species'],
            'breed'    => $breed === '' ? null : $breed,         // пустая строка в базе хранится как NULL
        ];
    }

    private function check(array $data): void
    {
        $errors = [];
        if (!is_string($data['name'] ?? null) || mb_strlen(trim($data['name'])) < 3 || mb_strlen($data['name']) > 100) {
            $errors['name'] = 'Кличка — от 3 до 100 символов';
        }
        if (!is_int($data['owner_id'] ?? null) || (new OwnerRepository())->find($data['owner_id']) === null) {
            $errors['owner_id'] = 'Выберите владельца из списка';
        }
        if (!in_array($data['species'] ?? null, ['dog', 'cat', 'bird', 'rodent', 'other'], true)) {
            $errors['species'] = 'Вид: dog, cat, bird, rodent или other';
        }
        if (($data['breed'] ?? null) !== null && !is_string($data['breed'])) {
            $errors['breed'] = 'Порода — строка или null';
        }
        if ($errors) {
            json_error('Ошибка проверки данных', 422, $errors);
        }
    }
}
