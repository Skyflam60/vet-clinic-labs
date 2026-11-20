<?php
// app/Models/ReportRepository.php — отчёты для руководителя клиники.

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

final class ReportRepository
{
    // Загрузка и выручка врачей: сколько приёмов в каждом статусе и сумма оказанных услуг по проведённым приёмам
    // (тот же запрос, что представление doctor_workload в лабораторной работе №8).
    // Запрос читает ВСЕ приёмы и строки услуг, поэтому на большой базе он выполняется заметное время.
    public function doctors(): array
    {
        return Database::connection()->query("
            SELECT d.id AS doctor_id, d.name, d.specialty,
                   COUNT(a.id)                                       AS total,
                   COUNT(a.id) FILTER (WHERE a.status = 'done')      AS done,
                   COUNT(a.id) FILTER (WHERE a.status = 'scheduled') AS scheduled,
                   COUNT(a.id) FILTER (WHERE a.status = 'cancelled') AS cancelled,
                   COALESCE(SUM(r.amount) FILTER (WHERE a.status = 'done'), 0)::float AS revenue
            FROM doctors d
            LEFT JOIN appointments a ON a.doctor_id = d.id
            LEFT JOIN (SELECT appointment_id, SUM(quantity * price) AS amount
                       FROM appointment_services GROUP BY appointment_id) r
                   ON r.appointment_id = a.id
            GROUP BY d.id, d.name, d.specialty
            ORDER BY d.id
        ")->fetchAll();
    }
}
