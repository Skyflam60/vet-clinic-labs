<?php
namespace App\Controllers;
use App\Models\AppointmentRepository;
use App\Models\AnimalRepository;
class HomeController {
    public function index(): void {
        $items = (new AppointmentRepository())->all();
        $counts = array_fill_keys(array_keys(statusNames()), 0);
        foreach ($items as $item) $counts[$item['status']]++;
        view('home', ['counts' => $counts, 'animalCount' => count((new AnimalRepository())->all())]);
    }
}
