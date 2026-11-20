<?php
// bin/queue-stats.php — что сейчас в очередях default (письма) и reports (отчёты).
//     php bin/queue-stats.php            # сколько задач в каждом списке
//     php bin/queue-stats.php --dead     # и содержимое «мёртвых» очередей: какие задачи и почему не выполнились
// То же в Redis: redis-cli LLEN vetclinic:queue:default, redis-cli LRANGE vetclinic:queue:default 0 -1

declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));
define('APP_PATH', BASE_PATH . '/app');
require APP_PATH . '/autoload.php';
require APP_PATH . '/helpers.php';

use App\Core\Queue;

$showDead = in_array('--dead', $argv, true);
foreach (config('queue')['queues'] as $queue) {
    try {
        $s = Queue::stats($queue);
    } catch (Throwable $e) {
        echo $e->getMessage() . "\n";
        exit(1);
    }
    echo "очередь $queue: ожидают: {$s['waiting']}, в работе: {$s['processing']}, ждут повтора: {$s['delayed']}, мёртвых: {$s['dead']}\n";
    if ($showDead) {
        foreach (Queue::dead($queue) as $job) {
            echo "  #{$job['id']} {$job['type']}, попыток: {$job['attempts']}, ошибка: " . ($job['error'] ?? '?') . "\n";
            echo '    данные: ' . json_encode($job['payload'], JSON_UNESCAPED_UNICODE) . "\n";
        }
    }
}
