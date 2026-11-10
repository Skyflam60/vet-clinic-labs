<?php
// app/Models/OwnerRepository.php — модель владельцев животных (таблица owners).
// Базовые операции (all, find, add, update, delete) унаследованы от PgRepository.

declare(strict_types=1);

namespace App\Models;

class OwnerRepository extends PgRepository
{
    protected string $table = 'owners';
    protected string $columns = 'id, name, phone, email';

    // Владелец по телефону (телефон уникален) или null
    public function findByPhone(string $phone): ?array
    {
        $stmt = $this->pdo()->prepare("SELECT {$this->columns} FROM owners WHERE phone = :phone");
        $stmt->execute(['phone' => $phone]);
        return $stmt->fetch() ?: null;
    }
}
