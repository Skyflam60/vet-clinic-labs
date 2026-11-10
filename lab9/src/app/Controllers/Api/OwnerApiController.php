<?php
// app/Controllers/Api/OwnerApiController.php — REST API владельцев (/api/owners).
// Смотреть может любой сотрудник; добавлять и изменять — администратор и регистратор; удалять — только администратор.

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Models\AnimalRepository;
use App\Models\OwnerRepository;

final class OwnerApiController
{
    private OwnerRepository $owners;

    public function __construct()
    {
        $this->owners = new OwnerRepository();
    }

    // GET /api/owners
    public function index(): void
    {
        json_response($this->owners->all());
    }

    // GET /api/owners/1
    public function show(string $id): void
    {
        json_response($this->find($id));
    }

    // POST /api/owners — тело: {"name": "...", "phone": "+7...", "email": "..." или null}
    public function store(): void
    {
        require_role('admin', 'registrar');
        $data = json_input() + ['email' => null];
        $this->check($data, 0);
        $owner = $this->owners->add($this->clean($data));
        header('Location: /api/owners/' . $owner['id']);
        json_response($owner, 201);
    }

    // PUT или PATCH /api/owners/1
    public function update(string $id): void
    {
        require_role('admin', 'registrar');
        $owner = $this->find($id);
        $data = array_merge($owner, json_input());
        $this->check($data, $owner['id']);
        json_response($this->owners->update($owner['id'], $this->clean($data)));
    }

    // DELETE /api/owners/1 — только администратор; владельца с животными удалить нельзя (409)
    public function destroy(string $id): void
    {
        require_role('admin');
        $owner = $this->find($id);
        if ((new AnimalRepository())->byOwner($owner['id'])) {
            json_error('Нельзя удалить владельца: у него есть животные', 409);
        }
        $this->owners->delete($owner['id']);
        no_content();
    }

    private function find(string $id): array
    {
        $owner = ctype_digit($id) ? $this->owners->find((int) $id) : null;
        if ($owner === null) {
            json_error('Владелец не найден', 404);
        }
        return $owner;
    }

    private function clean(array $data): array
    {
        $email = trim((string) ($data['email'] ?? ''));
        return [
            'name'  => trim($data['name']),
            'phone' => trim($data['phone']),
            'email' => $email === '' ? null : $email,       // в ЛР №7 «нет почты» было пустой строкой, в базе это NULL
        ];
    }

    private function check(array $data, int $exceptId): void
    {
        $errors = [];
        if (!is_string($data['name'] ?? null) || mb_strlen(trim($data['name'])) < 3 || mb_strlen($data['name']) > 150) {
            $errors['name'] = 'ФИО — от 3 до 150 символов';
        }
        if (!is_string($data['phone'] ?? null) || !preg_match('/^\+?[0-9 ()-]{5,20}$/', trim($data['phone']))) {
            $errors['phone'] = 'Телефон из цифр, например +79000000001';
        }
        $email = $data['email'] ?? null;
        if ($email !== null && (!is_string($email) || ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)))) {
            $errors['email'] = 'Неверная электронная почта (или пусто)';
        }
        if ($errors) {
            json_error('Ошибка проверки данных', 422, $errors);
        }
        $other = $this->owners->findByPhone(trim($data['phone']));
        if ($other !== null && $other['id'] !== $exceptId) {
            json_error('Владелец с таким телефоном уже есть', 409, ['phone' => 'Этот телефон уже записан у другого владельца']);
        }
    }
}
