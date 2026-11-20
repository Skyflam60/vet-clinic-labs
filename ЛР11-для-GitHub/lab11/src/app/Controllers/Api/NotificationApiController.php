<?php
// app/Controllers/Api/NotificationApiController.php — GET /api/notifications?appointment_id=5
// Журнал писем владельцам: администратор и регистратор (они работают с владельцами).

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Models\NotificationRepository;

final class NotificationApiController
{
    public function index(): void
    {
        require_role('admin', 'registrar');
        json_response((new NotificationRepository())->latest(query_int('appointment_id')));
    }
}
