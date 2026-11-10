<?php
// app/Models/AnimalRepository.php — модель животных (таблица animals).
// Кроме столбцов таблицы отдаём имя владельца (owner): клиенту из лабораторной работы №5 оно нужно для списка
// «Рекс — Иванов И.И.». Имя берётся подзапросом по owner_id; столбец только для чтения, в базу его не пишут.

declare(strict_types=1);

namespace App\Models;

final class AnimalRepository extends PgRepository
{
    protected string $table = 'animals';
    protected string $columns = 'id, owner_id, name, species, breed,
        (SELECT o.name FROM owners o WHERE o.id = animals.owner_id) AS owner';

    // Животные одного владельца
    public function byOwner(int $ownerId): array
    {
        $stmt = $this->pdo()->prepare("SELECT {$this->columns} FROM animals WHERE owner_id = :owner_id ORDER BY id");
        $stmt->execute(['owner_id' => $ownerId]);
        return $stmt->fetchAll();
    }
}
