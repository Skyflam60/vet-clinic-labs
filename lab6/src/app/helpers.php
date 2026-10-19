<?php
function e($value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function view(string $name, array $data = []): void {
    extract($data, EXTR_SKIP);
    $config = require BASE_PATH . '/config/app.php';
    require APP_PATH . '/Views/layout/header.php';
    require APP_PATH . '/Views/' . $name . '.php';
    require APP_PATH . '/Views/layout/footer.php';
}
function notFound(): void {
    http_response_code(404);
    view('errors/404');
    exit;
}
function redirect(string $url): void {
    header('Location: ' . $url, true, 302);
    exit;
}
function input(string $key): string {
    return is_string($_POST[$key] ?? null) ? trim($_POST[$key]) : '';
}
function statusNames(): array {
    return ['scheduled' => 'Запланирован', 'done' => 'Завершён', 'cancelled' => 'Отменён'];
}
