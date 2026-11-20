<?php
// app/Core/Cache.php — кеш приложения в Redis (лекция 3.1).
//
// Кеш — быстрое хранилище копий данных, которые часто нужны и долго достаются из базы.
// Все методы устроены так, чтобы кеш был ускорителем, а не обязательной частью: если Redis недоступен
// или выключен, приложение работает как раньше, просто медленнее (статус BYPASS).
//
// Значения хранятся в Redis как строки JSON, поэтому в кеш можно класть массивы.
// К каждому ключу автоматически добавляется префикс из config/app.php (vetclinic:), чтобы ключи приложения
// не смешивались с чужими.

declare(strict_types=1);

namespace App\Core;

use Redis;
use RedisException;

final class Cache
{
    private static ?Redis $redis = null;
    private static bool $failed = false;                   // подключиться не удалось: больше не пытаемся в этом запросе
    private static string $status = 'BYPASS';              // чем закончился последний remember(): HIT, MISS или BYPASS

    // Включён ли кеш для этого запроса.
    public static function enabled(): bool
    {
        if (!config('cache')['enabled']) {
            return false;                                   // выключен настройкой CACHE_ENABLED=0
        }
        // Режим измерений: заголовок «X-Cache-Mode: off» обходит кеш для одного запроса (только при debug = true).
        // Так можно сравнить скорость с кешем и без него, не перезапуская сервер.
        if (config('debug') && ($_SERVER['HTTP_X_CACHE_MODE'] ?? '') === 'off') {
            return false;
        }
        return true;
    }

    // Соединение с Redis или null, если кеш выключен или Redis недоступен.
    public static function redis(): ?Redis
    {
        if (!self::enabled() || self::$failed) {
            return null;
        }
        if (self::$redis === null) {
            try {
                $c = config('cache');
                $redis = new Redis();
                $redis->connect($c['host'], $c['port'], $c['timeout']);   // подключение с коротким таймаутом
                $redis->setOption(Redis::OPT_PREFIX, $c['prefix']);       // префикс ключей: vetclinic:
                $redis->ping();                                           // проверяем, что сервер отвечает
                self::$redis = $redis;
            } catch (RedisException $e) {
                self::$failed = true;                                     // Redis недоступен: работаем без кеша, но не падаем
                error_log('Redis недоступен: ' . $e->getMessage());
                return null;
            }
        }
        return self::$redis;
    }

    // Значение по ключу или null, если ключа нет (или Redis недоступен).
    public static function get(string $key): mixed
    {
        $redis = self::redis();
        if ($redis === null) {
            return null;
        }
        try {
            $raw = $redis->get($key);                                 // строка JSON или false, если ключа нет
        } catch (RedisException $e) {
            return null;
        }
        return $raw === false ? null : json_decode($raw, true);
    }

    // Кладёт значение в кеш на $ttl секунд. После этого срока Redis удалит ключ сам.
    public static function set(string $key, mixed $value, int $ttl): void
    {
        $redis = self::redis();
        if ($redis === null) {
            return;
        }
        try {
            $redis->setex($key, $ttl, json_encode($value, JSON_UNESCAPED_UNICODE));   // SET с временем жизни
        } catch (RedisException $e) {
            // не получилось записать в кеш: не страшно, при следующем запросе попробуем снова
        }
    }

    // Главный метод: «достань из кеша, а если там нет — получи из источника и положи в кеш».
    //   $key  — ключ,  $ttl — время жизни в секундах,  $load — функция, которая достаёт данные из базы
    public static function remember(string $key, int $ttl, callable $load): mixed
    {
        if (self::redis() === null) {
            self::mark('BYPASS');                                         // кеша нет: просто идём в базу
            return $load();
        }
        $cached = self::get($key);                                    // 1. проверить кеш
        if ($cached !== null) {
            self::mark('HIT');                                        // попадание: база не нужна
            return $cached;
        }
        $value = $load();                                             // 2. промах: получить из базы
        if ($value !== null) {                                        //    (отсутствие записи в кеш не кладём)
            self::set($key, $value, $ttl);                            // 3. сохранить в Redis с временем жизни
        }
        self::mark('MISS');
        return $value;                                                // 4. вернуть данные
    }

    // Удаляет ключи из кеша (инвалидация: данные изменились, копия устарела).
    public static function forget(string ...$keys): void
    {
        $redis = self::redis();
        if ($redis === null || $keys === []) {
            return;
        }
        try {
            $redis->del(...$keys);
        } catch (RedisException $e) {
            // кеш недоступен: копии устареют сами по истечении времени жизни
        }
    }

    // Номер версии набора ключей (0, если ещё не создан). Нужен для массовой инвалидации: см. bump().
    public static function version(string $name): int
    {
        return (int) (self::get($name) ?? 0);
    }

    // Увеличивает номер версии на 1. Ключи, в имя которых входит номер версии, перестают использоваться:
    // новые запросы ищут ключи с новой версией, а старые Redis удалит по истечении времени жизни.
    public static function bump(string $name): void
    {
        $redis = self::redis();
        if ($redis === null) {
            return;
        }
        try {
            $redis->incr($name);
        } catch (RedisException $e) {
            // см. forget()
        }
    }

    // Статус последнего remember(): HIT (из кеша), MISS (из базы, положили в кеш) или BYPASS (кеша нет).
    public static function status(): string
    {
        return self::$status;
    }

    // Запоминает статус и сообщает его клиенту заголовком X-Cache: так видно, откуда пришли данные.
    private static function mark(string $status): void
    {
        self::$status = $status;
        if (PHP_SAPI !== 'cli' && !headers_sent()) {
            header('X-Cache: ' . $status);
        }
    }

    // Удаляет ВСЕ ключи приложения (с префиксом из настроек). Возвращает число удалённых ключей.
    // Используется при сбросе базы, в тестах и измерениях; чужие ключи в Redis не затрагиваются.
    public static function flushApp(): int
    {
        $redis = self::redis();
        if ($redis === null) {
            return 0;
        }
        $prefix = config('cache')['prefix'];
        $removed = 0;
        $iterator = null;
        do {
            $keys = $redis->scan($iterator, $prefix . '*', 200);          // SCAN возвращает ключи уже с префиксом
            foreach ($keys ?: [] as $key) {
                $redis->del(substr($key, strlen($prefix)));               // а del добавит префикс сам, поэтому отрезаем
                $removed++;
            }
        } while ($iterator > 0);
        return $removed;
    }
}
