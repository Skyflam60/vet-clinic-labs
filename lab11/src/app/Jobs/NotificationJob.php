<?php
// app/Jobs/NotificationJob.php — письмо владельцу животного: запись на приём, перенос, отмена, приём проведён.
//
// Задачу ставит AppointmentApiController (store, update), выполняет воркер.
// payload: appointment_id, event (created | rescheduled | cancelled | done), email, owner, animal, title, date, doctor.
// В payload — снимок данных на момент события: письмо «приём отменён» должно уйти, даже если приём потом удалят.
// Настоящей почты в учебной системе нет: письмо записывается в журнал notifications,
// а медленный почтовый сервер имитируется паузой NOTIFY_SECONDS (по умолчанию 2 секунды).

declare(strict_types=1);

namespace App\Jobs;

use App\Core\Database;

final class NotificationJob
{
    private const SUBJECTS = [
        'created'     => 'Вы записаны на приём',
        'rescheduled' => 'Приём перенесён',
        'cancelled'   => 'Приём отменён',
        'done'        => 'Приём проведён',
    ];

    public function handle(array $payload, string $jobId, int $attempt): void
    {
        $subject = self::SUBJECTS[$payload['event']] ?? throw new \RuntimeException('Неизвестное событие: ' . $payload['event']);
        $when = date('d.m.Y в H:i', strtotime($payload['date']));
        $doctor = $payload['doctor'] !== null ? ', врач ' . $payload['doctor'] : '';
        $message = 'Здравствуйте, ' . $payload['owner'] . '! ' . match ($payload['event']) {
            'created'     => "{$payload['animal']} записан(а) на приём «{$payload['title']}» {$when}{$doctor}.",
            'rescheduled' => "Приём «{$payload['title']}» ({$payload['animal']}) перенесён на {$when}{$doctor}.",
            'cancelled'   => "Приём «{$payload['title']}» ({$payload['animal']}) {$when} отменён.",
            'done'        => "Приём «{$payload['title']}» ({$payload['animal']}) {$when} проведён. Спасибо, что выбрали нашу клинику!",
        };

        Jobs::pause(config('queue')['notify_seconds']);      // «отправка через почтовый сервер»

        // Идемпотентность: та же задача после сбоя (письмо записано, ack не успел) второго письма не создаст
        $stmt = Database::connection()->prepare(
            'INSERT INTO notifications (job_id, appointment_id, recipient, subject, message)
             SELECT :job_id, a.id, :recipient, :subject, :message
             FROM (SELECT :appointment_id::int AS wanted) w LEFT JOIN appointments a ON a.id = w.wanted
             ON CONFLICT (job_id, recipient) DO NOTHING'
        );
        $stmt->execute([
            'job_id' => $jobId, 'appointment_id' => $payload['appointment_id'], 'recipient' => $payload['email'],
            'subject' => $subject, 'message' => $message,
        ]);
    }

    // Данные для письма о приёме $item (как его отдаёт API). null — писать некому: у владельца нет почты.
    public static function payload(array $item, string $event, ?string $email): ?array
    {
        if ($email === null) {
            return null;
        }
        return [
            'appointment_id' => $item['id'],
            'event'          => $event,
            'email'          => $email,
            'owner'          => $item['owner'],
            'animal'         => $item['animal'],
            'title'          => $item['title'],
            'date'           => $item['date'],
            'doctor'         => $item['doctor'],
        ];
    }
}
