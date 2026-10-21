<?php
function json_response($data, int $code = 200): never {
    $text = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo $text;
    exit;
}
function json_error(string $message, int $code, array $errors = []): never {
    json_response(['error' => $message, 'errors' => (object) $errors], $code);
}
function no_content(): never {
    http_response_code(204);
    exit;
}
function json_input(): array {
    try {
        $data = json_decode(file_get_contents('php://input'), false, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $error) {
        json_error('Неверный JSON', 400);
    }
    if (!is_object($data)) json_error('Нужен объект JSON', 400);
    return (array) $data;
}
function query(string $name): string {
    $value = $_GET[$name] ?? '';
    if (!is_string($value)) json_error('Неверный параметр', 422, [$name => 'Нужна строка']);
    return trim($value);
}
