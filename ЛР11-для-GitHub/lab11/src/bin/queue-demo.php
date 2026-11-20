<?php
// bin/queue-demo.php — ставит в очередь учебные задачи (DemoJob) для экспериментов с воркером.
// Запуск:
//     php bin/queue-demo.php              # 3 задачи по 1 секунде
//     php bin/queue-demo.php 5            # 5 задач по 1 секунде
//     php bin/queue-demo.php --slow       # одна долгая задача (20 секунд): успеть остановить воркер посреди работы
//     php bin/queue-demo.php --fail       # задача, которая всегда падает: посмотреть повторы и «мёртвую» очередь
//     php bin/queue-demo.php --flaky      # задача, которая падает дважды и получается с третьей попытки
//     php bin/queue-demo.php 10 --seconds=2   # 10 задач по 2 секунды (сравнить один и два воркера)
// Потом запустите воркер: php bin/worker.php --until-empty

declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));
define('APP_PATH', BASE_PATH . '/app');
require APP_PATH . '/autoload.php';
require APP_PATH . '/helpers.php';

use App\Core\Queue;

$args = array_slice($argv, 1);
$seconds = 1;
foreach ($args as $arg) {
    if (str_starts_with($arg, '--seconds=')) {
        $seconds = max(0, (int) substr($arg, 10));
    }
}
$jobs = [];
if (in_array('--slow', $args, true)) {
    $jobs[] = ['seconds' => 20];
} elseif (in_array('--fail', $args, true)) {
    $jobs[] = ['seconds' => 0, 'fail' => true];
} elseif (in_array('--flaky', $args, true)) {
    $jobs[] = ['seconds' => 0, 'succeed_on' => 3];
} else {
    $count = max(1, (int) ($args[0] ?? 3));
    for ($i = 0; $i < $count; $i++) {
        $jobs[] = ['seconds' => $seconds];
    }
}

foreach ($jobs as $payload) {
    $id = Queue::push('demo', $payload);
    echo 'поставлена задача #' . $id . ' ' . json_encode($payload) . "\n";
}
$s = Queue::stats();
echo "В очереди: {$s['waiting']}. Запустите воркер: php bin/worker.php --until-empty\n";
