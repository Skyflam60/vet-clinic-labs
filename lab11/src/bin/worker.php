<?php
// bin/worker.php — воркер: берёт задачи из очереди и выполняет их.
//
//     php bin/worker.php                      # работает, пока не остановят (Ctrl+C)
//     php bin/worker.php --queue=reports      # очередь отчётов (по умолчанию default — письма)
//     php bin/worker.php --until-empty        # выполнить всё, что есть, и выйти (для проверок и тестов)
//     php bin/worker.php --once               # выполнить одну задачу и выйти

declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));
define('APP_PATH', BASE_PATH . '/app');
require APP_PATH . '/autoload.php';
require APP_PATH . '/helpers.php';

use App\Core\Queue;
use App\Jobs\Jobs;

$options = getopt('', ['queue::', 'once', 'until-empty']);
$queue = (string) ($options['queue'] ?? 'default');
$once = isset($options['once']);
$untilEmpty = isset($options['until-empty']);

function out(string $line): void
{
    echo '[' . date('H:i:s') . '] ' . $line . PHP_EOL;
}

$running = true;

// Корректная остановка: Ctrl+C (SIGINT) или kill (SIGTERM) только снимают флаг, текущая задача доделывается.
// В Windows расширения pcntl нет: там воркер останавливается сразу, а задача остаётся «в работе» до следующего запуска.
if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
    $stop = function () use (&$running): void {
        $running = false;
        out('получен сигнал остановки: заканчиваю текущую задачу и выхожу');
    };
    pcntl_signal(SIGTERM, $stop);
    pcntl_signal(SIGINT, $stop);
}

out('воркер запущен (очередь ' . $queue . ', процесс ' . getmypid() . '). Остановить: Ctrl+C');

$recovered = Queue::recover($queue);
if ($recovered > 0) {
    out('возвращено в очередь задач, застрявших после сбоя: ' . $recovered);
}

$done = 0;
$lastRecover = time();
while ($running) {
    Queue::promote($queue);
    if (time() - $lastRecover >= 10) {                     // раз в 10 секунд: не зависла ли задача упавшего соседа
        $lastRecover = time();
        $recovered = Queue::recover($queue);
        if ($recovered > 0) {
            out('возвращено в очередь задач, зависших у другого воркера: ' . $recovered);
        }
    }
    $reserved = Queue::reserve($queue, $untilEmpty ? 1 : 2);   // ждём не дольше 1–2 секунд, чтобы проверять флаг остановки
    if ($reserved === null) {
        if ($untilEmpty && Queue::stats($queue)['delayed'] === 0) {
            break;
        }
        continue;
    }
    [$job, $raw] = $reserved;
    $started = microtime(true);
    out(sprintf('#%s %s: начал (попытка %d)', $job['id'], $job['type'], $job['attempts'] + 1));
    try {
        Jobs::run($job);
        Queue::ack($raw, $queue);
        $done++;
        out(sprintf('#%s %s: готово за %.2f с', $job['id'], $job['type'], microtime(true) - $started));
    } catch (Throwable $e) {
        $result = Queue::fail($job, $raw, $e->getMessage(), $queue);
        out(sprintf('#%s %s: ОШИБКА «%s» → %s', $job['id'], $job['type'], $e->getMessage(),
            $result === 'retry' ? 'повтор позже' : 'в «мёртвую» очередь'));
    }
    if ($once) {
        break;
    }
}

out('воркер остановлен (выполнено задач: ' . $done . ')');
