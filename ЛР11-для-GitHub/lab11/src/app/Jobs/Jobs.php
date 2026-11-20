<?php
// app/Jobs/Jobs.php — реестр фоновых задач: какой класс выполняет задачу каждого типа и в какой очереди она ждёт.

declare(strict_types=1);

namespace App\Jobs;

use App\Core\Queue;

final class Jobs
{
    public static function handlers(): array
    {
        return [
            'notification' => NotificationJob::class,   // письмо владельцу о записи, переносе, отмене или завершении приёма
            'report'       => ReportJob::class,         // отчёт по приёмам в CSV-файл
            'demo'         => DemoJob::class,           // учебная задача для экспериментов (bin/queue-demo.php)
        ];
    }

    // Очередь для задачи. Долгие отчёты отдельно, чтобы быстрые письма не ждали за ними.
    public static function queueFor(string $type): string
    {
        return $type === 'report' ? 'reports' : 'default';
    }

    // Пауза, которая не прерывается сигналом остановки: имитация медленной работы, которую воркер доделывает перед выходом.
    public static function pause(float $seconds): void
    {
        $end = microtime(true) + $seconds;
        while (microtime(true) < $end) {
            usleep(50_000);
        }
    }

    // Выполняет задачу. Неизвестный тип или ошибка обработчика — исключение: воркер поймёт, что задача не выполнена.
    public static function run(array $job): void
    {
        $class = self::handlers()[$job['type']] ?? null;
        if ($class === null) {
            throw new \RuntimeException('Неизвестный тип задачи: ' . $job['type']);
        }
        (new $class())->handle($job['payload'], $job['id'], (int) ($job['attempts'] ?? 0) + 1);
    }

    // Отдаёт операцию в фон и возвращает номер задачи.
    //   async (обычно): задача кладётся в очередь, метод возвращается сразу.
    //   sync: выполнить здесь же, как было бы без очереди. QUEUE_MODE=sync или (при debug) заголовок «X-Queue-Mode: sync».
    // Очередь недоступна — null: действие пользователя уже выполнено, откатывать его из-за письма нельзя.
    public static function dispatch(string $type, array $payload): ?string
    {
        $sync = config('queue')['mode'] === 'sync'
            || (config('debug') && ($_SERVER['HTTP_X_QUEUE_MODE'] ?? '') === 'sync');
        if ($sync) {
            self::run(['id' => 'sync' . bin2hex(random_bytes(6)), 'type' => $type, 'payload' => $payload, 'attempts' => 0]);
            return 'sync';
        }
        try {
            return Queue::push($type, $payload, self::queueFor($type));
        } catch (\Throwable $e) {
            error_log('Не удалось поставить задачу «' . $type . '» в очередь: ' . $e->getMessage());
            return null;
        }
    }
}
