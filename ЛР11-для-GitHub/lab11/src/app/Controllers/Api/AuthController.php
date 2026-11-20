<?php
// app/Controllers/Api/AuthController.php — вход и выход: /api/auth/login, /api/auth/me, /api/auth/logout.

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Auth;
use App\Models\UserRepository;

final class AuthController
{
    private UserRepository $users;

    public function __construct()
    {
        $this->users = new UserRepository();
    }

    // POST /api/auth/login  с телом {"email": "...", "password": "..."}
    // Успех: 200 и {"token": "...", "expires_at": "...", "user": {...}}. Неверные данные: 401.
    public function login(): void
    {
        $data = json_input();
        $errors = [];
        if (!is_string($data['email'] ?? null) || trim($data['email']) === '') {
            $errors['email'] = 'Введите email';
        }
        if (!is_string($data['password'] ?? null) || $data['password'] === '') {
            $errors['password'] = 'Введите пароль';
        }
        if ($errors) {
            json_error('Ошибка проверки данных', 422, $errors);
        }

        // Одно и то же сообщение для «нет такого email» и «неверный пароль»: так нельзя узнать, какие адреса зарегистрированы
        $user = $this->users->findByEmail(trim($data['email']));
        if ($user === null || !password_verify($data['password'], $user['password_hash'])) {
            json_error('Неверный email или пароль', 401);
        }

        $session = Auth::issueToken($user['id']);
        json_response([
            'token'      => $session['token'],
            'expires_at' => $session['expires_at'],
            'user'       => public_user($user),
        ]);
    }

    // GET /api/auth/me — кто сейчас вошёл. Ответ: 200 и запись пользователя (без хеша пароля).
    public function me(): void
    {
        json_response(public_user(require_auth()));
    }

    // POST /api/auth/logout — выход: токен перестаёт действовать. Ответ: 204.
    public function logout(): void
    {
        $token = bearer_token();
        if ($token !== null) {
            Auth::revoke($token);
        }
        no_content();
    }
}
