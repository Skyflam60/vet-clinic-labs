<?php
// app/Models/DoctorRepository.php — модель врачей (таблица doctors).

declare(strict_types=1);

namespace App\Models;

class DoctorRepository extends PgRepository
{
    protected string $table = 'doctors';
    protected string $columns = 'id, name, specialty, phone, is_active';
}
