<?php
// app/Core/Queue.php — очередь фоновых задач в Redis.
//
// Ключи одной очереди (все с префиксом vetclinic:):
//   queue:<имя>             список ожидающих задач: новая кладётся слева (LPUSH), берётся справа (первой — самая старая)
//   queue:<имя>:processing  задачи «в работе»: воркер взял, но ещё не подтвердил
//   queue:<имя>:delayed     упорядоченное множество задач для повтора (оценка — время, когда вернуть в очередь)
//   queue:<имя>:dead        «мёртвая» очередь: задачи, не выполненные за все попытки
//   queue:<имя>:reserved    хеш «номер задачи → когда воркер её взял»: по нему recover отличает зависшую задачу от выполняемой
// Очередей две: default (письма владельцам) и reports (отчёты), см. Jobs::queueFor().
//
// Задача — JSON-строка: {"id": "a1b2c3d4e5f60718", "type": "notification", "payload": {...}, "attempts": 0, "created_at": "..."}

declare(strict_types=1);

namespace App\Core;

use Redis;
use RedisException;

final class Queue
{
    private static ?Redis $redis = null;

    // Соединение с Redis. Если Redis недоступен — исключение с кодом 503 (public/index.php ответит «503 Service Unavailable»).
    public static function redis(): Redis
    {
        if (self::$redis === null) {
            $c = config('queue');
            $redis = new Redis();
            try {
                $redis->connect($c['host'], $c['port'], $c['timeout']);
                $redis->setOption(Redis::OPT_PREFIX, $c['prefix']);
                $redis->setOption(Redis::OPT_READ_TIMEOUT, $c['read_timeout']);   // иначе ожидание задачи (BLMOVE) оборвалось бы
                $redis->ping();
            } catch (RedisException $e) {
                throw new \RuntimeException('Очередь недоступна: Redis не отвечает (' . $c['host'] . ':' . $c['port'] . ')', 503, $e);
            }
            self::$redis = $redis;
        }
        return self::$redis;
    }

    // key('default') → queue:default, key('default', 'dead') → queue:default:dead
    private static function key(string $queue, string $part = ''): string
    {
        return 'queue:' . $queue . ($part === '' ? '' : ':' . $part);
    }

    // Кладёт задачу в очередь (LPUSH) и возвращает её номер.
    public static function push(string $type, array $payload = [], string $queue = 'default'): string
    {
        $job = [
            'id'         => bin2hex(random_bytes(8)),
            'type'       => $type,
            'payload'    => $payload,
            'attempts'   => 0,
            'created_at' => date('c'),
        ];
        self::redis()->lPush(self::key($queue), json_encode($job, JSON_UNESCAPED_UNICODE));
        return $job['id'];
    }

    // Берёт следующую задачу: одной командой BLMOVE перекладывает её из очереди в «в работе».
    // Ждёт не дольше $timeout секунд; задач нет — null. Возвращает [массив задачи, исходная строка] (строка нужна для ack).
    public static function reserve(string $queue = 'default', int $timeout = 2): ?array
    {
        $raw = self::redis()->blmove(self::key($queue), self::key($queue, 'processing'), 'RIGHT', 'LEFT', $timeout);
        if ($raw === false) {
            return null;
        }
        $job = json_decode($raw, true);
        self::redis()->hSet(self::key($queue, 'reserved'), $job['id'], (string) time());
        return [$job, $raw];
    }

    // Подтверждение (ack): задача выполнена, убрать её из «в работе» (LREM).
    public static function ack(string $raw, string $queue = 'default'): void
    {
        self::redis()->lRem(self::key($queue, 'processing'), $raw, 1);
        self::redis()->hDel(self::key($queue, 'reserved'), (string) (json_decode($raw, true)['id'] ?? ''));
    }

    // Задача не выполнена. Повтор с растущей паузой (delayed) или отказ (dead). Возвращает 'retry' или 'dead'.
    // Из «в работе» задача убирается ПОСЛЕ записи в delayed или dead: при сбое между шагами она не пропадёт.
    public static function fail(array $job, string $raw, string $error, string $queue = 'default'): string
    {
        $job['attempts']++;
        $job['error'] = $error;
        $json = json_encode($job, JSON_UNESCAPED_UNICODE);
        $redis = self::redis();
        if ($job['attempts'] >= config('queue')['max_attempts']) {
            $redis->lPush(self::key($queue, 'dead'), $json);
            $result = 'dead';
        } else {
            $delay = config('queue')['backoff'] * (2 ** ($job['attempts'] - 1));     // 2, 4, 8 секунд
            $redis->zAdd(self::key($queue, 'delayed'), time() + $delay, $json);
            $result = 'retry';
        }
        self::ack($raw, $queue);
        return $result;
    }

    // Возвращает в очередь задачи, у которых прошла пауза перед повтором. Воркер вызывает его на каждом шаге цикла.
    public static function promote(string $queue = 'default'): int
    {
        $redis = self::redis();
        $moved = 0;
        foreach ($redis->zRangeByScore(self::key($queue, 'delayed'), '-inf', (string) time()) as $raw) {
            if ($redis->zRem(self::key($queue, 'delayed'), $raw) > 0) {   // убрать из delayed смог один воркер: он и вернёт в очередь
                $redis->lPush(self::key($queue), $raw);
                $moved++;
            }
        }
        return $moved;
    }

    // Возвращает в очередь задачи, застрявшие в «в работе» после аварийной остановки воркера (kill -9, отключили питание).
    // Застрявшей считается задача, взятая больше $stuckAfter секунд назад (QUEUE_STUCK_AFTER, по умолчанию 120):
    // задачу, которую прямо сейчас выполняет другой живой воркер, забирать нельзя. Срок должен быть больше самой долгой задачи.
    // Возвращённая задача кладётся в правый конец очереди, чтобы выполниться первой.
    public static function recover(string $queue = 'default', ?int $stuckAfter = null): int
    {
        $stuckAfter ??= config('queue')['stuck_after'];
        $redis = self::redis();
        $count = 0;
        foreach ($redis->lRange(self::key($queue, 'processing'), 0, -1) as $raw) {
            $id = (string) (json_decode($raw, true)['id'] ?? '');
            $takenAt = $redis->hGet(self::key($queue, 'reserved'), $id);
            if ($takenAt !== false && time() - (int) $takenAt < $stuckAfter) {
                continue;                                  // взята недавно: её, скорее всего, выполняет живой воркер
            }
            if ($redis->lRem(self::key($queue, 'processing'), $raw, 1) > 0) {   // убрать смог один воркер: он и вернёт
                $redis->rPush(self::key($queue), $raw);
                $redis->hDel(self::key($queue, 'reserved'), $id);
                $count++;
            }
        }
        return $count;
    }

    // Сколько задач в каждом списке очереди
    public static function stats(string $queue = 'default'): array
    {
        $redis = self::redis();
        return [
            'waiting'    => (int) $redis->lLen(self::key($queue)),
            'processing' => (int) $redis->lLen(self::key($queue, 'processing')),
            'delayed'    => (int) $redis->zCard(self::key($queue, 'delayed')),
            'dead'       => (int) $redis->lLen(self::key($queue, 'dead')),
        ];
    }

    // Сколько секунд ждёт самая старая задача очереди (null — очередь пуста)
    public static function oldestAge(string $queue = 'default'): ?int
    {
        $raw = self::redis()->lIndex(self::key($queue), -1);
        if ($raw === false) {
            return null;
        }
        $created = strtotime((string) (json_decode($raw, true)['created_at'] ?? ''));
        return $created === false ? null : max(0, time() - $created);
    }

    // Задачи «мёртвой» очереди (для bin/queue-stats.php и GET /api/queue/stats)
    public static function dead(string $queue = 'default'): array
    {
        return array_map(fn(string $raw): array => json_decode($raw, true), self::redis()->lRange(self::key($queue, 'dead'), 0, -1));
    }

    // Удаляет все списки очереди (для bin/reset-db.php и тестов). Без имени — все очереди приложения.
    public static function flush(?string $queue = null): void
    {
        foreach ($queue === null ? config('queue')['queues'] : [$queue] as $q) {
            self::redis()->del(self::key($q), self::key($q, 'processing'), self::key($q, 'delayed'), self::key($q, 'dead'), self::key($q, 'reserved'));
        }
    }
}
