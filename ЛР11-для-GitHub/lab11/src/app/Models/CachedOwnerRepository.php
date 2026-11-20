<?php
// app/Models/CachedOwnerRepository.php — владельцы. Сам список владельцев не кешируем (его читает только форма
// «Новое животное»), но имя владельца есть в справочнике животных и в приёмах, поэтому при изменении владельца
// эти копии нужно удалить.

declare(strict_types=1);

namespace App\Models;

use App\Core\Cache;

final class CachedOwnerRepository extends OwnerRepository
{
    public function add(array $data): array
    {
        return parent::add($data);                        // у нового владельца ещё нет животных: кеш не устарел
    }

    public function update(int $id, array $data): ?array
    {
        $owner = parent::update($id, $data);
        ClinicCache::animalsChanged();
        return $owner;
    }

    public function delete(int $id): bool
    {
        $deleted = parent::delete($id);
        Cache::forget('animals:all');                     // удалить можно только владельца без животных
        return $deleted;
    }
}
