<?php
// app/Core/Auth.php — выдача и проверка токенов входа .
//
// Токен — длинная случайная строка, «пропуск» в систему. Сервер выдаёт его после проверки пароля,
// клиент присылает с каждым запросом в заголовке «Authorization: Bearer <токен>».
// В таблице auth_tokens хранится не сам токен, а его хеш (SHA-256): если таблица утечёт, войти по ней нельзя.
// Рядом записаны номер пользователя и время, когда токен перестанет действовать.

declare(strict_types=1);

namespace App\Core;

use App\Models\UserRepository;

final class Auth
{
    // Создаёт токен для пользователя. Возвращает ['token' => '...', 'expires_at' => '2026-10-27T12:00:00+00:00'].
    public static function issueToken(int $userId): array
    {
        $token = bin2hex(random_bytes(32));                // 64 случайных символа
        $expires = time() + (int) config('token_ttl');

        $pdo = Database::connection();
        $pdo->exec('DELETE FROM auth_tokens WHERE expires_at < now()');   // заодно убираем просроченные токены
        $stmt = $pdo->prepare('INSERT INTO auth_tokens (token_hash, user_id, expires_at) VALUES (:hash, :user_id, to_timestamp(:expires))');
        $stmt->execute(['hash' => hash('sha256', $token), 'user_id' => $userId, 'expires' => $expires]);

        return ['token' => $token, 'expires_at' => date('c', $expires)];
    }

    // Находит пользователя по токену. Токен не найден или срок вышел — null.
    public static function userByToken(string $token): ?array
    {
        $stmt = Database::connection()->prepare('SELECT user_id FROM auth_tokens WHERE token_hash = :hash AND expires_at > now()');
        $stmt->execute(['hash' => hash('sha256', $token)]);
        $userId = $stmt->fetchColumn();
        return $userId === false ? null : (new UserRepository())->find((int) $userId);
    }

    // Делает токен недействительным (выход из системы).
    public static function revoke(string $token): void
    {
        $stmt = Database::connection()->prepare('DELETE FROM auth_tokens WHERE token_hash = :hash');
        $stmt->execute(['hash' => hash('sha256', $token)]);
    }
}
