<?php
// app/Controllers/Api/ReportApiController.php — отчёты: GET /api/reports/doctors

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Cache;
use App\Models\ClinicCache;
use App\Models\ReportRepository;

final class ReportApiController
{
    private ReportRepository $reports;

    public function __construct()
    {
        $this->reports = new ReportRepository();
    }

    // GET /api/reports/doctors — загрузка и выручка врачей (администратор и врачи; регистратору отчёт не нужен)
    public function doctors(): void
    {
        require_role('admin', 'doctor');
        // Отчёт не зависит от параметров, поэтому ключ один. Запрос дорогой, а открывают отчёт часто.
        $data = Cache::remember('report:doctors', ClinicCache::TTL_REPORT, fn(): array => $this->reports->doctors());
        json_response($data);
    }
}
