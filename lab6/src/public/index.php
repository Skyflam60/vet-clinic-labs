<?php
// Все запросы проходят через этот файл.
define('BASE_PATH', dirname(__DIR__));
define('APP_PATH', BASE_PATH . '/app');
require APP_PATH . '/autoload.php';
require APP_PATH . '/helpers.php';
try {
    $router = new App\Core\Router();
    require BASE_PATH . '/routes/web.php';
    $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';
    $router->dispatch($_SERVER['REQUEST_METHOD'], $path);
} catch (Throwable $error) {
    error_log((string) $error);
    http_response_code(500);
    echo '<meta charset="utf-8"><h1>Ошибка сервера</h1><p>Не удалось обработать запрос.</p>';
}
