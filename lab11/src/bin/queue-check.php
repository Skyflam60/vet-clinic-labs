<?php
// bin/queue-check.php — проверка очереди без приложения и воркера: поставить задачу, взять её, подтвердить.
// Запуск:  php bin/queue-check.php      Код завершения: 0 — очередь работает, 1 — нет.

declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));
define('APP_PATH', BASE_PATH . '/app');
require APP_PATH . '/autoload.php';
require APP_PATH . '/helpers.php';

use App\Core\Queue;

if (!extension_loaded('redis')) {
    echo "Не установлено расширение PHP redis (phpredis): pecl install redis\n";
    exit(1);
}
try {
    Queue::flush();
} catch (Throwable $e) {
    $c = config('queue');
    echo "Redis недоступен ({$c['host']}:{$c['port']}): {$e->getMessage()}\n";
    echo "Проверьте: docker compose ps, docker compose up -d, переменные REDIS_HOST и REDIS_PORT.\n";
    exit(1);
}

function show(string $step): void
{
    $s = Queue::stats();
    echo sprintf("%-34s ожидают: %d, в работе: %d, повторы: %d, мёртвых: %d\n", $step, $s['waiting'], $s['processing'], $s['delayed'], $s['dead']);
}

$ok = true;
show('очередь пуста:');

$id = Queue::push('demo', ['seconds' => 0]);
echo "поставлена задача: " . ($id === '' ? '(номер пустой: push не работает)' : $id) . "\n";
show('после push:');
$ok = $ok && $id !== '' && Queue::stats()['waiting'] === 1;

$reserved = Queue::reserve('default', 1);
if ($reserved === null) {
    echo "взять задачу не удалось (reserve не работает или задача не была поставлена)\n";
    $ok = false;
} else {
    [$job, $raw] = $reserved;
    echo 'взята задача ' . $job['id'] . ', тип ' . $job['type'] . ', данные ' . json_encode($job['payload']) . "\n";
    show('после reserve:');
    $ok = $ok && Queue::stats()['waiting'] === 0 && Queue::stats()['processing'] === 1;

    Queue::ack($raw);
    show('после ack:');
    $ok = $ok && Queue::stats()['processing'] === 0;
}

Queue::flush();
echo $ok ? "Очередь работает.\n" : "Есть ошибки: проверьте push, reserve и ack в app/Core/Queue.php.\n";
exit($ok ? 0 : 1);
