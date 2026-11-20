<?php
// app/Models/CachedDoctorRepository.php — врачи с кешем. Справочник: его читает форма записи на каждом открытии
// страницы, а меняет только администратор и редко.

declare(strict_types=1);

namespace App\Models;

use App\Core\Cache;

final class CachedDoctorRepository extends DoctorRepository
{
    public function all(): array
    {
        return Cache::remember('doctors:all', ClinicCache::TTL_DICTIONARY, fn(): array => parent::all());
    }

    public function add(array $data): array
    {
        $doctor = parent::add($data);
        ClinicCache::doctorsChanged();
        return $doctor;
    }

    public function update(int $id, array $data): ?array
    {
        $doctor = parent::update($id, $data);
        ClinicCache::doctorsChanged();
        return $doctor;
    }

    // Удаление врача меняет и приёмы: у них doctor_id становится NULL (ON DELETE SET NULL),
    // поэтому устаревают ещё и списки приёмов.
    public function delete(int $id): bool
    {
        $deleted = parent::delete($id);
        ClinicCache::doctorsChanged();
        ClinicCache::appointmentsChanged();
        return $deleted;
    }
}
