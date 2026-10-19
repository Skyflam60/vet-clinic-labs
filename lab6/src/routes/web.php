<?php
use App\Controllers\HomeController;
use App\Controllers\AppointmentController;
use App\Controllers\AnimalController;
$router->get('/', [HomeController::class, 'index']);
$router->get('/appointments', [AppointmentController::class, 'index']);
$router->get('/appointments/create', [AppointmentController::class, 'create']);
$router->get('/appointments/{id}', [AppointmentController::class, 'show']);
$router->post('/appointments', [AppointmentController::class, 'store']);
$router->get('/animals', [AnimalController::class, 'index']);
$router->get('/animals/{id}', [AnimalController::class, 'show']);
