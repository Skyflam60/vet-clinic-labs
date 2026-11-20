<?php
// app/Jobs/DemoJob.php — учебная задача для экспериментов с воркером.
// Ставится командой php bin/queue-demo.php. Что делает, зависит от данных (payload):
//   seconds    сколько секунд «работать» (по умолчанию 1)
//   fail       true — всегда падать с ошибкой (чтобы посмотреть повторы и «мёртвую» очередь)
//   succeed_on номер попытки, с которой задача начинает получаться (до неё падает): «нестабильная» задача

declare(strict_types=1);

namespace App\Jobs;

final class DemoJob
{
    public function handle(array $payload, string $jobId, int $attempt): void
    {
        Jobs::pause((int) ($payload['seconds'] ?? 1));
        if (!empty($payload['fail'])) {
            throw new \RuntimeException('учебная ошибка: задача падает всегда');
        }
        if ($attempt < (int) ($payload['succeed_on'] ?? 1)) {
            throw new \RuntimeException('учебная ошибка: попытка ' . $attempt . ' не удалась');
        }
    }
}
