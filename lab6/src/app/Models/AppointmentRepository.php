<?php
namespace App\Models;
class AppointmentRepository {
    public function all(): array {
        $text = file_get_contents(BASE_PATH . '/storage/appointments.json');
        if ($text === false) throw new \RuntimeException('Не удалось прочитать приёмы');
        return json_decode($text, true, 512, JSON_THROW_ON_ERROR);
    }
    public function find(int $id): ?array {
        foreach ($this->all() as $item) {
            if ($item['id'] === $id) return $item;
        }
        return null;
    }
    public function byAnimal(int $id): array {
        return array_values(array_filter($this->all(), fn($item) => $item['animal_id'] === $id));
    }
    public function add(array $item): void {
        $items = $this->all();
        $ids = array_column($items, 'id');
        $item['id'] = $ids ? max($ids) + 1 : 1;
        $items[] = $item;
        $text = json_encode($items, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        if (file_put_contents(BASE_PATH . '/storage/appointments.json', $text, LOCK_EX) === false) {
            throw new \RuntimeException('Не удалось сохранить приём');
        }
    }
}
