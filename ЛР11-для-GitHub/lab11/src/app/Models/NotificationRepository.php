<?php
// app/Models/NotificationRepository.php — журнал писем владельцам (таблица notifications, пишет воркер).

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

final class NotificationRepository
{
    // Последние письма, сначала новые; $appointmentId — только по одному приёму
    public function latest(?int $appointmentId, int $limit = 50): array
    {
        $sql = "SELECT id, job_id, appointment_id, recipient, subject, message,
                       to_char(created_at, 'YYYY-MM-DD\"T\"HH24:MI:SS') AS created_at
                FROM notifications" . ($appointmentId !== null ? ' WHERE appointment_id = :appointment_id' : '') . '
                ORDER BY id DESC LIMIT :limit';
        $stmt = Database::connection()->prepare($sql);
        if ($appointmentId !== null) {
            $stmt->bindValue('appointment_id', $appointmentId, \PDO::PARAM_INT);
        }
        $stmt->bindValue('limit', $limit, \PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }
}
