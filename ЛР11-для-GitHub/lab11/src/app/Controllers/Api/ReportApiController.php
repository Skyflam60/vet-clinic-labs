<?php
// app/Controllers/Api/ReportApiController.php — отчёты:
//   GET  /api/reports/doctors        загрузка и выручка врачей (быстро, с кешем — ЛР №10)
//   POST /api/reports                заказать отчёт по приёмам в CSV: строит воркер, ответ сразу (202 Accepted)
//   GET  /api/reports/{id}           состояние заявки: queued, processing, done или failed
//   GET  /api/reports/{id}/download  скачать готовый файл
// Отчёты нужны администратору и врачам; заявку видят её автор и администратор.

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Cache;
use App\Jobs\Jobs;
use App\Models\ClinicCache;
use App\Models\ReportRepository;
use App\Models\ReportRequestRepository;

final class ReportApiController
{
    private ReportRepository $reports;
    private ReportRequestRepository $requests;

    public function __construct()
    {
        $this->reports = new ReportRepository();
        $this->requests = new ReportRequestRepository();
    }

    // GET /api/reports/doctors — загрузка и выручка врачей
    public function doctors(): void
    {
        require_role('admin', 'doctor');
        $data = Cache::remember('report:doctors', ClinicCache::TTL_REPORT, fn(): array => $this->reports->doctors());
        json_response($data);
    }

    // POST /api/reports — тело (всё необязательно): {"date_from": "2026-01-01", "date_to": "2026-12-31", "status": "done"}
    // Обработчик только оформляет заявку и ставит задачу; файл строит воркер.
    public function create(): void
    {
        $user = require_role('admin', 'doctor');
        $data = json_input();
        $errors = [];
        foreach (['date_from', 'date_to'] as $field) {
            $value = $data[$field] ?? null;
            if ($value !== null && (!is_string($value) || !self::validDate($value))) {
                $errors[$field] = 'Дата в виде 2026-11-20 или null';
            }
        }
        $status = $data['status'] ?? null;
        if ($status !== null && !in_array($status, ['scheduled', 'done', 'cancelled'], true)) {
            $errors['status'] = 'Статус: scheduled, done, cancelled или null (все)';
        }
        if (!$errors && isset($data['date_from'], $data['date_to']) && $data['date_from'] > $data['date_to']) {
            $errors['date_to'] = 'Дата окончания раньше даты начала';
        }
        if ($errors) {
            json_error('Ошибка проверки данных', 422, $errors);
        }

        $report = $this->requests->create($user['id'], $data['date_from'] ?? null, $data['date_to'] ?? null, $status);
        $jobId = Jobs::dispatch('report', ['report_id' => $report['id']]);
        if ($jobId === null) {
            $this->requests->markFailed($report['id'], 'Очередь недоступна');
            json_error('Очередь недоступна, отчёт не поставлен в обработку', 503);
        }
        header('Location: /api/reports/' . $report['id']);
        header('X-Job-Id: ' . $jobId);
        json_response($this->requests->find($report['id']), 202);   // в режиме sync отчёт к этому моменту уже готов
    }

    // GET /api/reports/5 — состояние заявки
    public function show(string $id): void
    {
        json_response($this->findAllowed($id));
    }

    // GET /api/reports/5/download — готовый CSV-файл; не готов — 409
    public function download(string $id): void
    {
        $report = $this->findAllowed($id);
        if ($report['status'] !== 'done') {
            json_error('Отчёт ещё не готов (статус: ' . $report['status'] . ')', 409);
        }
        $path = BASE_PATH . '/storage/reports/' . $report['file_name'];
        if (!is_file($path)) {
            json_error('Файл отчёта не найден', 404);
        }
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $report['file_name'] . '"');
        header('Content-Length: ' . filesize($path));
        readfile($path);
        exit;
    }

    // Заявка, которую можно показать этому пользователю: не найдена — 404, чужая (не администратору) — 403
    private function findAllowed(string $id): array
    {
        $user = require_role('admin', 'doctor');
        $report = ctype_digit($id) ? $this->requests->find((int) $id) : null;
        if ($report === null) {
            json_error('Заявка на отчёт не найдена', 404);
        }
        if ($report['user_id'] !== $user['id'] && $user['role'] !== 'admin') {
            json_error('Недостаточно прав для этого действия', 403);
        }
        return $report;
    }

    private static function validDate(string $value): bool
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date !== false && $date->format('Y-m-d') === $value;
    }
}
