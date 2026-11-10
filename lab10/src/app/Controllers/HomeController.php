<?php
// app/Controllers/HomeController.php — главная страница: отправляет в клиентское приложение.

declare(strict_types=1);

namespace App\Controllers;

final class HomeController
{
    public function index(): void
    {
        redirect('/client/');
    }
}
