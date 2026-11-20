<?php
// app/Core/Database.php — подключение к PostgreSQL через PDO (лекция 2.2, раздел «Как приложение работает с базой данных»).
// Соединение создаётся один раз за запрос и используется всеми репозиториями.

declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOException;

final class Database
{
    private static ?PDO $pdo = null;

    public static function connection(): PDO
    {
        if (self::$pdo === null) {
            $db = config('db');
            $dsn = sprintf('pgsql:host=%s;port=%d;dbname=%s', $db['host'], $db['port'], $db['name']);
            try {
                self::$pdo = new PDO($dsn, $db['user'], $db['password'], [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,   // ошибки SQL — исключения, а не молчаливое false
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,         // строки — ассоциативные массивы
                    PDO::ATTR_EMULATE_PREPARES   => false,                    // настоящие подготовленные запросы
                ]);
            } catch (PDOException $e) {
                // код 503 (Service Unavailable): сервер работает, но база недоступна; public/index.php ответит клиенту понятно
                throw new \RuntimeException('База данных недоступна', 503, $e);
            }
        }
        return self::$pdo;
    }
}
