<?php
namespace App\Models;
class AppointmentRepository extends JsonRepository {
    protected string $name = 'appointments';
    public function byAnimal(int $id): array {
        return array_values(array_filter($this->all(), fn($item) => $item['animal_id'] === $id));
    }
}
