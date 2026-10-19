<?php
namespace App\Controllers;
use App\Models\AppointmentRepository;
use App\Models\AnimalRepository;
class AppointmentController {
    public function index(): void {
        $status = is_string($_GET['status'] ?? null) ? $_GET['status'] : '';
        $items = (new AppointmentRepository())->all();
        if ($status !== '') $items = array_values(array_filter($items, fn($item) => $item['status'] === $status));
        view('appointments/index', ['items' => $items, 'status' => $status]);
    }
    public function show(string $id): void {
        $item = (new AppointmentRepository())->find((int) $id);
        if ($item === null) notFound();
        $animal = (new AnimalRepository())->find($item['animal_id']);
        view('appointments/show', ['item' => $item, 'animal' => $animal]);
    }
    public function create(): void {
        view('appointments/create', ['animals' => (new AnimalRepository())->all(), 'values' => [], 'errors' => []]);
    }
    public function store(): void {
        $values = ['title' => input('title'), 'animal_id' => input('animal_id'),
            'doctor' => input('doctor'), 'date' => input('date'), 'status' => input('status')];
        $errors = [];
        if (mb_strlen($values['title']) < 3) $errors['title'] = 'Введите причину обращения — не менее трёх символов.';
        if (!(new AnimalRepository())->find((int) $values['animal_id'])) $errors['animal_id'] = 'Выберите животное.';
        if ($values['doctor'] === '') $errors['doctor'] = 'Укажите врача.';
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $values['date']);
        if (!$date || $date->format('Y-m-d\TH:i') !== $values['date']) $errors['date'] = 'Укажите правильные дату и время.';
        if (!array_key_exists($values['status'], statusNames())) $errors['status'] = 'Выберите статус.';
        if ($errors) {
            http_response_code(422);
            view('appointments/create', ['animals' => (new AnimalRepository())->all(), 'values' => $values, 'errors' => $errors]);
            return;
        }
        $values['animal_id'] = (int) $values['animal_id'];
        (new AppointmentRepository())->add($values);
        redirect('/appointments');
    }
}
