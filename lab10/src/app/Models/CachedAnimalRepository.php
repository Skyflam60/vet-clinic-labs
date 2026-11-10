<?php
// app/Models/CachedAnimalRepository.php — животные с кешем. Справочник для формы записи на приём:
// читается при каждом открытии страницы, а запрос с именами владельцев (подзапрос на каждую строку) не бесплатный.

declare(strict_types=1);

namespace App\Models;

use App\Core\Cache;

final class CachedAnimalRepository extends AnimalRepository
{
    public function all(): array
    {
        return Cache::remember('animals:all', ClinicCache::TTL_DICTIONARY, fn(): array => parent::all());
    }

    public function add(array $data): array
    {
        $animal = parent::add($data);
        Cache::forget('animals:all');                     // нового животного ещё нет ни в одном приёме: версию не трогаем
        return $animal;
    }

    public function update(int $id, array $data): ?array
    {
        $animal = parent::update($id, $data);
        ClinicCache::animalsChanged();
        return $animal;
    }

    public function delete(int $id): bool
    {
        $deleted = parent::delete($id);
        ClinicCache::animalsChanged();
        return $deleted;
    }
}
