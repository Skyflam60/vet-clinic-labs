<?php
namespace App\Controllers;
use App\Models\AnimalRepository;
use App\Models\AppointmentRepository;
class AnimalController {
    public function index(): void {
        view('animals/index', ['animals' => (new AnimalRepository())->all()]);
    }
    public function show(string $id): void {
        $animal = (new AnimalRepository())->find((int) $id);
        if ($animal === null) notFound();
        view('animals/show', ['animal' => $animal, 'items' => (new AppointmentRepository())->byAnimal((int) $id)]);
    }
}
