<?php
// app/Models/ReportRequestRepository.php — заявки на отчёт по приёмам (таблица report_requests).
// Заявку создаёт обработчик запроса (queued), меняет воркер: processing → done или failed.

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

final class ReportRequestRepository
{
    private const COLUMNS = "id, user_id, to_char(date_from, 'YYYY-MM-DD') AS date_from, to_char(date_to, 'YYYY-MM-DD') AS date_to,
        status_filter, status, file_name, rows_count, total_revenue::float AS total_revenue, error,
        to_char(created_at, 'YYYY-MM-DD\"T\"HH24:MI:SS') AS created_at, to_char(finished_at, 'YYYY-MM-DD\"T\"HH24:MI:SS') AS finished_at";

    public function create(int $userId, ?string $dateFrom, ?string $dateTo, ?string $status): array
    {
        $stmt = Database::connection()->prepare(
            'INSERT INTO report_requests (user_id, date_from, date_to, status_filter)
             VALUES (:user_id, :date_from, :date_to, :status) RETURNING ' . self::COLUMNS
        );
        $stmt->execute(['user_id' => $userId, 'date_from' => $dateFrom, 'date_to' => $dateTo, 'status' => $status]);
        return self::typed($stmt->fetch());
    }

    public function find(int $id): ?array
    {
        $stmt = Database::connection()->prepare('SELECT ' . self::COLUMNS . ' FROM report_requests WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row === false ? null : self::typed($row);
    }

    // Сумма — числом, а не строкой
    private static function typed(array $row): array
    {
        $row['total_revenue'] = $row['total_revenue'] === null ? null : (float) $row['total_revenue'];
        return $row;
    }

    public function markProcessing(int $id): void
    {
        $stmt = Database::connection()->prepare("UPDATE report_requests SET status = 'processing', error = NULL WHERE id = :id");
        $stmt->execute(['id' => $id]);
    }

    public function markDone(int $id, string $fileName, int $rows, float $revenue): void
    {
        $stmt = Database::connection()->prepare(
            "UPDATE report_requests SET status = 'done', file_name = :file, rows_count = :rows, total_revenue = :revenue,
                    error = NULL, finished_at = NOW() WHERE id = :id"
        );
        $stmt->execute(['id' => $id, 'file' => $fileName, 'rows' => $rows, 'revenue' => $revenue]);
    }

    public function markRetry(int $id, string $error): void
    {
        $stmt = Database::connection()->prepare("UPDATE report_requests SET status = 'queued', error = :error WHERE id = :id");
        $stmt->execute(['id' => $id, 'error' => $error]);
    }

    public function markFailed(int $id, string $error): void
    {
        $stmt = Database::connection()->prepare("UPDATE report_requests SET status = 'failed', error = :error, finished_at = NOW() WHERE id = :id");
        $stmt->execute(['id' => $id, 'error' => $error]);
    }
}
