<?php
ini_set('display_errors', '0');
define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/app/autoload.php';
require BASE_PATH . '/app/helpers.php';
try {
    $router = new App\Core\Router();
    require BASE_PATH . '/routes/web.php';
    $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';
    if ($path === '/' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        header('Content-Type: text/html; charset=utf-8');
        echo '<h1>Ветеринарная клиника</h1><p>Лабораторная работа №7</p><ul><li><a href="/api/appointments">Приёмы</a></li><li><a href="/api/animals">Животные</a></li><li><a href="/api/owners">Владельцы</a></li></ul>';
        exit;
    }
    $router->dispatch($_SERVER['REQUEST_METHOD'], $path);
} catch (Throwable $error) {
    error_log((string) $error);
    json_error('Ошибка сервера', 500);
}
