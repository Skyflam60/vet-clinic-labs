<?php
namespace App\Models;
class JsonRepository {
    protected string $name;
    public function all(): array {
        $text = file_get_contents(BASE_PATH . '/storage/' . $this->name . '.json');
        if ($text === false) throw new \RuntimeException('Не удалось прочитать файл');
        $items = json_decode($text, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($items) || !array_is_list($items)) throw new \RuntimeException('Неверный файл данных');
        return $items;
    }
    private function save(array $items): void {
        $text = json_encode(array_values($items), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        if (file_put_contents(BASE_PATH . '/storage/' . $this->name . '.json', $text, LOCK_EX) === false) {
            throw new \RuntimeException('Не удалось сохранить файл');
        }
    }
    public function find(int $id): ?array {
        foreach ($this->all() as $item) if ($item['id'] === $id) return $item;
        return null;
    }
    public function add(array $data): array {
        $items = $this->all();
        $ids = array_column($items, 'id');
        $data['id'] = $ids ? max($ids) + 1 : 1;
        $items[] = $data;
        $this->save($items);
        return $data;
    }
    public function update(int $id, array $data): ?array {
        $items = $this->all();
        foreach ($items as $index => $item) {
            if ($item['id'] !== $id) continue;
            $data['id'] = $id;
            $items[$index] = $data;
            $this->save($items);
            return $data;
        }
        return null;
    }
    public function delete(int $id): bool {
        $items = $this->all();
        foreach ($items as $index => $item) {
            if ($item['id'] !== $id) continue;
            unset($items[$index]);
            $this->save($items);
            return true;
        }
        return false;
    }
}
