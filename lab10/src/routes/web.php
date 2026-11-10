<?php
// routes/web.php — таблица маршрутов: «какой адрес — какой контроллер и метод».
// Переменная $router создаётся в public/index.php. Права ролей проверяются в контроллерах (require_role).

use App\Controllers\Api\AnimalApiController;
use App\Controllers\Api\AppointmentApiController;
use App\Controllers\Api\AuthController;
use App\Controllers\Api\DoctorApiController;
use App\Controllers\Api\OwnerApiController;
use App\Controllers\Api\ReportApiController;
use App\Controllers\Api\UserApiController;
use App\Controllers\HomeController;

$router->get('/', [HomeController::class, 'index']);       // главная отправляет в клиентское приложение /client/

// ---------- Вход и выход ----------
$router->post('/api/auth/login', [AuthController::class, 'login']);     // единственный адрес API без входа
$router->get('/api/auth/me', [AuthController::class, 'me']);
$router->post('/api/auth/logout', [AuthController::class, 'logout']);

// ---------- Приёмы (основной ресурс) ----------
$router->get('/api/appointments', [AppointmentApiController::class, 'index']);
$router->post('/api/appointments', [AppointmentApiController::class, 'store']);
$router->get('/api/appointments/{id}', [AppointmentApiController::class, 'show']);
$router->put('/api/appointments/{id}', [AppointmentApiController::class, 'update']);
$router->patch('/api/appointments/{id}', [AppointmentApiController::class, 'update']);
$router->delete('/api/appointments/{id}', [AppointmentApiController::class, 'destroy']);

// ---------- Животные ----------
$router->get('/api/animals', [AnimalApiController::class, 'index']);
$router->post('/api/animals', [AnimalApiController::class, 'store']);
$router->get('/api/animals/{id}', [AnimalApiController::class, 'show']);
$router->put('/api/animals/{id}', [AnimalApiController::class, 'update']);
$router->patch('/api/animals/{id}', [AnimalApiController::class, 'update']);
$router->delete('/api/animals/{id}', [AnimalApiController::class, 'destroy']);
$router->get('/api/animals/{id}/appointments', [AnimalApiController::class, 'appointments']);

// ---------- Владельцы ----------
$router->get('/api/owners', [OwnerApiController::class, 'index']);
$router->post('/api/owners', [OwnerApiController::class, 'store']);
$router->get('/api/owners/{id}', [OwnerApiController::class, 'show']);
$router->put('/api/owners/{id}', [OwnerApiController::class, 'update']);
$router->patch('/api/owners/{id}', [OwnerApiController::class, 'update']);
$router->delete('/api/owners/{id}', [OwnerApiController::class, 'destroy']);

// ---------- Врачи ----------
$router->get('/api/doctors', [DoctorApiController::class, 'index']);
$router->post('/api/doctors', [DoctorApiController::class, 'store']);
$router->get('/api/doctors/{id}', [DoctorApiController::class, 'show']);
$router->put('/api/doctors/{id}', [DoctorApiController::class, 'update']);
$router->patch('/api/doctors/{id}', [DoctorApiController::class, 'update']);
$router->delete('/api/doctors/{id}', [DoctorApiController::class, 'destroy']);

// ---------- Отчёты ----------
$router->get('/api/reports/doctors', [ReportApiController::class, 'doctors']);   // загрузка и выручка врачей

// ---------- Пользователи системы (только администратор) ----------
$router->get('/api/users', [UserApiController::class, 'index']);
$router->post('/api/users', [UserApiController::class, 'store']);
$router->get('/api/users/{id}', [UserApiController::class, 'show']);
$router->put('/api/users/{id}', [UserApiController::class, 'update']);
$router->patch('/api/users/{id}', [UserApiController::class, 'update']);
$router->delete('/api/users/{id}', [UserApiController::class, 'destroy']);
