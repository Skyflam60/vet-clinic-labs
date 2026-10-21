<?php
namespace App\Models;
class AnimalRepository extends JsonRepository {
    protected string $name = 'animals';
    public function byOwner(int $id): array {
        return array_values(array_filter($this->all(), fn($item) => $item['owner_id'] === $id));
    }
}
