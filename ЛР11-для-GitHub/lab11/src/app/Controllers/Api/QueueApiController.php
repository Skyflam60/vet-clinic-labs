<?php
// app/Controllers/Api/QueueApiController.php — GET /api/queue/stats: состояние очередей для администратора.
// По нему видно, что воркер не справляется (растёт waiting и oldest_wait_seconds) или что есть «мёртвые» задачи.

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Queue;

final class QueueApiController
{
    public function stats(): void
    {
        require_role('admin');
        $result = [];
        foreach (config('queue')['queues'] as $queue) {
            $result[$queue] = Queue::stats($queue) + [
                'oldest_wait_seconds' => Queue::oldestAge($queue),
                'dead_jobs'           => array_map(fn(array $job): array => [
                    'id' => $job['id'], 'type' => $job['type'], 'attempts' => $job['attempts'], 'error' => $job['error'] ?? null,
                ], array_slice(Queue::dead($queue), 0, 10)),
            ];
        }
        json_response($result);
    }
}
