<?php
// config/app.php — настройки приложения. Файл возвращает массив.

// Число из переменной окружения или значение по умолчанию (0 — тоже число: QUEUE_BACKOFF=0 значит «без паузы»)
if (!function_exists('env_int')) {
    function env_int(string $name, int $default): int
    {
        $value = getenv($name);
        return $value === false || $value === '' ? $default : (int) $value;
    }
}

return [
    'name'         => 'Ветеринарная клиника',   // название системы
    'debug'        => true,               // true — в ответе 500 показывается текст ошибки (только при разработке!)
    'token_ttl'    => 7200,               // срок жизни токена входа, секунд (2 часа)
    'cors_origins' => [],                 // адреса клиентов с ДРУГОГО origin, которым разрешён доступ к API
                                          // (пример: ['http://localhost:5500']); для клиента с того же адреса не нужно

    // Подключение к PostgreSQL. Значения можно переопределить переменными окружения, например:
    //   DB_PORT=5433 php -S localhost:8000 -t public
    // (в рабочей системе пароль хранится не в коде, а в переменных окружения или в файле .env вне репозитория)
    'db' => [
        'host'     => getenv('DB_HOST') ?: '127.0.0.1',
        'port'     => env_int('DB_PORT', 5432),
        'name'     => getenv('DB_NAME') ?: 'vetdb',      // база ветеринарной клиники (схема из лабораторной работы №8)
        'user'     => getenv('DB_USER') ?: 'postgres',
        'password' => getenv('DB_PASSWORD') ?: 'secret',
    ],

    // Кеш в Redis. CACHE_ENABLED=0 выключает кеш целиком (приложение работает как в лабораторной работе №9).
    'cache' => [
        'enabled' => getenv('CACHE_ENABLED') !== '0',
        'host'    => getenv('REDIS_HOST') ?: '127.0.0.1',
        'port'    => env_int('REDIS_PORT', 6379),
        'prefix'  => 'vetclinic:',        // все ключи приложения начинаются с этого префикса
        'timeout' => 0.5,                 // секунд на подключение: недоступный Redis не должен «вешать» приложение
    ],

    // Очередь фоновых задач в Redis (лабораторная работа №11). Redis тот же, что и для кеша, но очередь от кеша не зависит:
    // CACHE_ENABLED=0 очередь не выключает.
    'queue' => [
        'mode'           => getenv('QUEUE_MODE') ?: 'async',            // async — через очередь; sync — выполнить сразу в запросе (для сравнения)
        'host'           => getenv('REDIS_HOST') ?: '127.0.0.1',
        'port'           => env_int('REDIS_PORT', 6379),
        'prefix'         => 'vetclinic:',                               // ключи очереди: vetclinic:queue:default и т. д.
        'timeout'        => 0.5,                                        // секунд на подключение
        'read_timeout'   => 10,                                         // секунд ожидания ответа Redis (больше, чем ожидание задачи в reserve)
        'max_attempts'   => env_int('QUEUE_MAX_ATTEMPTS', 3),  // сколько раз пробовать, прежде чем отправить задачу в «мёртвую» очередь
        'backoff'        => env_int('QUEUE_BACKOFF', 2),       // пауза перед повтором: 2 с, 4 с, 8 с... (0 — повторять сразу)
        'notify_seconds' => env_int('NOTIFY_SECONDS', 2),      // «отправка письма владельцу» занимает столько секунд
        'report_seconds' => env_int('REPORT_SECONDS', 0),      // дополнительная пауза в отчёте (0: на большой базе он и так долгий)
        'stuck_after'    => env_int('QUEUE_STUCK_AFTER', 120), // через сколько секунд задача «в работе» считается зависшей (больше самой долгой)
        'queues'         => ['default', 'reports'],                     // все очереди: быстрые письма и долгие отчёты отдельно
    ],
];
