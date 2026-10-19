<?php
namespace App\Models;
class AnimalRepository {
    public function all(): array {
        $text = file_get_contents(BASE_PATH . '/storage/animals.json');
        if ($text === false) throw new \RuntimeException('Не удалось прочитать животных');
        return json_decode($text, true, 512, JSON_THROW_ON_ERROR);
    }
    public function find(int $id): ?array {
        foreach ($this->all() as $item) {
            if ($item['id'] === $id) return $item;
        }
        return null;
    }
}
