<?php
// app/Controllers/Api/UserApiController.php — REST API пользователей системы (/api/users): сотрудники клиники и их роли.
// Всё управление пользователями (в том числе просмотр списка) — только администратор.
// Пароль хранится в виде хеша (password_hash) и клиенту никогда не отдаётся.

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Models\UserRepository;

final class UserApiController
{
    private UserRepository $users;

    public function __construct()
    {
        $this->users = new UserRepository();
    }

    // GET /api/users
    public function index(): void
    {
        require_role('admin');
        json_response(array_map('public_user', $this->users->all()));
    }

    // GET /api/users/2
    public function show(string $id): void
    {
        require_role('admin');
        $user = $this->users->find((int) $id);
        if ($user === null) {
            json_error('Пользователь не найден', 404);
        }
        json_response(public_user($user));
    }

    // POST /api/users — тело: {"name": "...", "email": "...", "password": "...", "role": "..."}
    public function store(): void
    {
        require_role('admin');
        $data = json_input();
        $errors = $this->validate($data, true);
        if ($errors) {
            json_error('Ошибка проверки данных', 422, $errors);
        }
        if ($this->users->findByEmail(trim($data['email'])) !== null) {
            json_error('Пользователь с такой почтой уже есть', 409);   // почта — это логин, она не должна повторяться
        }

        $user = $this->users->add([
            'name'          => trim($data['name']),
            'email'         => trim($data['email']),
            'role'          => $data['role'],
            'password_hash' => password_hash($data['password'], PASSWORD_DEFAULT),   // в файле — только хеш
        ]);
        header('Location: /api/users/' . $user['id']);
        json_response(public_user($user), 201);
    }

    // PUT или PATCH /api/users/2 — пароль можно не присылать: тогда он не меняется
    public function update(string $id): void
    {
        require_role('admin');
        $user = $this->users->find((int) $id);
        if ($user === null) {
            json_error('Пользователь не найден', 404);
        }

        $input = json_input();
        $data = array_merge($user, $input);
        $errors = $this->validate($data, false);
        if ($errors) {
            json_error('Ошибка проверки данных', 422, $errors);
        }
        $other = $this->users->findByEmail(trim($data['email']));
        if ($other !== null && $other['id'] !== $user['id']) {
            json_error('Пользователь с такой почтой уже есть', 409);
        }

        $updated = $this->users->update($user['id'], [
            'name'          => trim($data['name']),
            'email'         => trim($data['email']),
            'role'          => $data['role'],
            'password_hash' => isset($input['password']) ? password_hash($input['password'], PASSWORD_DEFAULT) : $user['password_hash'],
        ]);
        json_response(public_user($updated));
    }

    // DELETE /api/users/2
    public function destroy(string $id): void
    {
        $me = require_role('admin');
        if ((int) $id === $me['id']) {
            json_error('Нельзя удалить самого себя', 409);   // иначе в системе может не остаться администратора
        }
        if (!$this->users->delete((int) $id)) {
            json_error('Пользователь не найден', 404);
        }
        no_content();
    }

    // Проверка данных. $isNew — создание: пароль обязателен.
    private function validate(array $data, bool $isNew): array
    {
        $errors = [];
        if (!is_string($data['name'] ?? null) || mb_strlen(trim($data['name'])) < 2) {
            $errors['name'] = 'Имя — не короче 2 символов';
        }
        if (!is_string($data['email'] ?? null) || !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Некорректный адрес электронной почты';
        }
        if (!in_array($data['role'] ?? null, ['admin', 'registrar', 'doctor'], true)) {
            $errors['role'] = 'Роль: admin, registrar или doctor';
        }
        if (($isNew || isset($data['password'])) && (!is_string($data['password'] ?? null) || mb_strlen($data['password']) < 6)) {
            $errors['password'] = 'Пароль — не короче 6 символов';
        }
        return $errors;
    }
}
