<?php
// app/helpers.php — вспомогательные функции для всего приложения.

declare(strict_types=1);

// Читает настройку из config/app.php: config('debug')
function config(string $key): mixed
{
    static $config = null;                                 // файл читается один раз
    $config ??= require BASE_PATH . '/config/app.php';
    return $config[$key] ?? null;
}

// Безопасный вывод текста в HTML (защита от XSS)
function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

// Перенаправляет браузер на другой адрес и завершает работу скрипта.
function redirect(string $path): never
{
    header('Location: ' . $path);
    exit;
}

// Страница «Не найдено» для браузера (для адресов, которые не начинаются с /api)
function not_found_page(string $path): never
{
    http_response_code(404);
    header('Content-Type: text/html; charset=utf-8');
    echo '<h1>404</h1><p>Страница ' . e($path) . ' не найдена. Клиентское приложение: <a href="/client/">/client/</a></p>';
    exit;
}

// ---------- Помощники для REST API  ----------

// Запрос адресован API (адрес начинается с /api)?
function is_api_request(): bool
{
    return str_starts_with(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/', '/api');
}

// Отправляет ответ в формате JSON с нужным кодом и завершает работу скрипта.
function json_response(mixed $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

// Ответ об ошибке: {"error": "...", "errors": {поле: сообщение}}.
function json_error(string $message, int $status, array $errors = []): never
{
    $body = ['error' => $message];
    if ($errors) {
        $body['errors'] = $errors;
    }
    json_response($body, $status);
}

// Ответ без тела: код 204 No Content.
function no_content(): never
{
    http_response_code(204);
    exit;
}

// Читает тело запроса (JSON) и превращает его в массив; иначе отвечает кодом 400.
function json_input(): array
{
    $data = json_decode(file_get_contents('php://input'), true);
    if (!is_array($data)) {
        json_error('Тело запроса должно быть корректным JSON-объектом', 400);
    }
    return $data;
}

// Строковый параметр адреса (?status=done) или '' — если его нет. Массив вместо строки (?status[]=1) — ответ 422.
function query_string(string $name): string
{
    $value = $_GET[$name] ?? '';
    if (!is_string($value)) {
        json_error('Ошибка проверки данных', 422, [$name => 'Нужна строка']);
    }
    return trim($value);
}

// Номер из параметра адреса (?animal_id=2) или null — если параметра нет. Не число — ответ 422.
function query_int(string $name): ?int
{
    $value = query_string($name);
    if ($value === '') {
        return null;
    }
    if (!ctype_digit($value)) {
        json_error('Ошибка проверки данных', 422, [$name => 'Нужен номер (целое число)']);
    }
    return (int) $value;
}

// Дата и время в формате API: 2026-11-20T10:00 (как в лабораторных работах №5 и №7), и такая дата существует
function valid_datetime(mixed $value): bool
{
    if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/D', $value)) {
        return false;
    }
    $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $value);
    return $date !== false && $date->format('Y-m-d\TH:i') === $value;
}

// ---------- Авторизация ----------

// Токен из заголовка «Authorization: Bearer <токен>» или null, если заголовка нет.
function bearer_token(): ?string
{
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    return preg_match('/^Bearer\s+(\S+)$/i', $header, $m) ? $m[1] : null;
}

// Пользователь, который отправил запрос (по токену), или null.
// Запись содержит и password_hash: наружу её отдавать нельзя, только через public_user().
function current_user(): ?array
{
    static $user = false;                                  // false = ещё не искали
    if ($user === false) {
        $token = bearer_token();
        $user = $token === null ? null : App\Core\Auth::userByToken($token);
    }
    return $user;
}

// Требует вход: нет действительного токена — ответ 401 и завершение работы.
function require_auth(): array
{
    $user = current_user();
    if ($user === null) {
        header('WWW-Authenticate: Bearer');
        json_error('Требуется авторизация', 401);
    }
    return $user;
}

// Требует вход и одну из ролей: роль не подходит — ответ 403 и завершение работы.
//   require_role('admin');  или  require_role('admin', 'registrar');
function require_role(string ...$roles): array
{
    $user = require_auth();
    if (!in_array($user['role'], $roles, true)) {
        json_error('Недостаточно прав для этого действия', 403);
    }
    return $user;
}

// Адреса API, доступные без входа (иначе войти было бы невозможно).
function is_public_api(): bool
{
    return (parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '') === '/api/auth/login';
}

// Запись пользователя без секретных полей: её можно отправлять клиенту.
function public_user(array $user): array
{
    unset($user['password_hash']);                         // хеш пароля клиенту не нужен и опасен
    return $user;
}

// ---------- CORS  ----------
// Браузер не пускает страницу с одного адреса (origin) к API на другом, пока сервер не разрешит это заголовками.
// Адреса клиентов, которым разрешено, перечислены в config/app.php → cors_origins.
function send_cors_headers(): void
{
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origin !== '' && in_array($origin, config('cors_origins') ?? [], true)) {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Vary: Origin');
        header('Access-Control-Allow-Headers: Authorization, Content-Type');
        header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
        header('Access-Control-Max-Age: 600');
    }
}
