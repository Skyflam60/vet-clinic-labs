<?php
// app/Models/CachedAppointmentRepository.php — приёмы с кешем в Redis.
// Наследует AppointmentRepository: всё, что не переопределено здесь, работает как раньше.
// Схема cache-aside: сначала кеш, при промахе база и запись в кеш; при любом изменении — инвалидация.

declare(strict_types=1);

namespace App\Models;

use App\Core\Cache;

final class CachedAppointmentRepository extends AppointmentRepository
{
    // Карточка приёма. Ключ: appointments:item:r0:5 (r — версия справочников, см. ClinicCache)
    public function find(int $id): ?array
    {
        return Cache::remember(ClinicCache::itemKey($id), ClinicCache::TTL_ITEM, fn(): ?array => parent::find($id));
    }

    // Список приёмов. Вариантов запроса много, поэтому в ключ входят ВСЕ параметры: у разных запросов разные ключи.
    public function search(string $status, ?int $animalId, ?int $doctorId, string $q, string $sort, int $limit = 100, int $offset = 0): array
    {
        $key = ClinicCache::listKey([$status, $animalId, $doctorId, $q, $sort, $limit, $offset]);
        return Cache::remember($key, ClinicCache::TTL_LIST,
            fn(): array => parent::search($status, $animalId, $doctorId, $q, $sort, $limit, $offset));
    }

    public function add(array $data): array
    {
        $item = parent::add($data);
        ClinicCache::appointmentsChanged();               // у нового приёма ещё нет карточки в кеше
        return $item;
    }

    public function update(int $id, array $data): ?array
    {
        $item = parent::update($id, $data);
        ClinicCache::appointmentsChanged($id);
        return $item;
    }

    public function delete(int $id): bool
    {
        $deleted = parent::delete($id);
        ClinicCache::appointmentsChanged($id);
        return $deleted;
    }
}
