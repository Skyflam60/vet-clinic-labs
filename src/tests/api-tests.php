<?php
// tests/api-tests.php — автоматические тесты API ветеринарной клиники (комплексная проверка серверной части).
//
// Запуск (сервер должен быть запущен: php -S localhost:8000 -t public):
//     php tests/api-tests.php
//     php tests/api-tests.php http://localhost:8000     # другой адрес сервера
//
// Тест — это запрос и проверка ответа. Каждая проверка печатает PASS (прошла) или FAIL (не прошла) и пояснение.
// В начале база данных возвращается к исходному состоянию (bin/reset-db.php), поэтому тесты можно запускать сколько угодно раз.
// Код завершения 0 — все проверки прошли, 1 — есть ошибки (удобно для автоматического запуска).

declare(strict_types=1);

$base = rtrim($argv[1] ?? 'http://localhost:8000', '/');
$root = dirname(__DIR__);

// ---------------------------------------------------------------------
// Инструменты теста (ГОТОВО)
// ---------------------------------------------------------------------

// Отправляет запрос. Возвращает ['status' => 200, 'headers' => [...], 'json' => [...], 'body' => '...'].
function call(string $method, string $path, ?array $body = null, ?string $token = null, ?string $rawBody = null): array
{
    global $base;
    $headers = ['Accept: application/json'];
    if ($token !== null) {
        $headers[] = 'Authorization: Bearer ' . $token;
    }
    $content = $rawBody ?? ($body !== null ? json_encode($body, JSON_UNESCAPED_UNICODE) : null);
    if ($content !== null) {
        $headers[] = 'Content-Type: application/json';
    }
    $context = stream_context_create(['http' => [
        'method' => $method, 'header' => implode("\r\n", $headers), 'content' => $content ?? '',
        'ignore_errors' => true,                           // коды 4xx и 5xx — это тоже ответ, а не сбой
        'timeout' => 10,
    ]]);
    $text = @file_get_contents($base . $path, false, $context);
    if ($text === false) {
        fwrite(STDERR, "Не удалось подключиться к $base. Запущен ли сервер (php -S localhost:8000 -t public)?\n");
        exit(2);
    }
    $status = 0;
    $responseHeaders = [];
    foreach ($http_response_header as $line) {
        if (preg_match('#^HTTP/\S+\s+(\d+)#', $line, $m)) {
            $status = (int) $m[1];
        } elseif (str_contains($line, ':')) {
            [$name, $value] = explode(':', $line, 2);
            $responseHeaders[strtolower(trim($name))] = trim($value);
        }
    }
    return ['status' => $status, 'headers' => $responseHeaders, 'json' => json_decode($text, true), 'body' => $text];
}

// Входит в систему и возвращает токен.
function login(string $email, string $password = '123456'): string
{
    $r = call('POST', '/api/auth/login', ['email' => $email, 'password' => $password]);
    return $r['json']['token'] ?? '';
}

$passed = 0;
$failed = 0;

// Одна проверка: $ok — условие, $name — что проверяем, $details — что получили (печатается при ошибке).
function check(string $name, bool $ok, string $details = ''): void
{
    global $passed, $failed;
    if ($ok) {
        $passed++;
        echo "  \033[32mPASS\033[0m  $name\n";
    } else {
        $failed++;
        echo "  \033[31mFAIL\033[0m  $name" . ($details !== '' ? "  ($details)" : '') . "\n";
    }
}

// Проверка кода ответа: expectStatus('POST /api/appointments без токена', 401, $response)
function expectStatus(string $name, int $expected, array $r): void
{
    check($name . ' → ' . $expected, $r['status'] === $expected, 'получен код ' . $r['status']);
}

function section(string $title): void
{
    echo "\n$title\n";
}

// Список записей из ответа. Если сервер ответил ошибкой (объект {"error": ...}), вернёт пустой массив,
// чтобы тест просто не прошёл, а не завершился аварийно.
function rows(array $r): array
{
    return is_array($r['json']) && array_is_list($r['json']) ? $r['json'] : [];
}

// Возвращаем исходные данные в базе (вывод скрипта не нужен: в Windows он уходит в NUL, в Linux и macOS — в /dev/null)
$devNull = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/bin/reset-db.php') . ' > ' . $devNull);

$admin = login('admin@vet.example');                       // администратор
$registrar = login('registrar@vet.example');               // регистратор
$doctor = login('doctor@vet.example');                     // врач

// ---------------------------------------------------------------------
section('1. Вход в систему');
// ---------------------------------------------------------------------
$r = call('POST', '/api/auth/login', ['email' => 'admin@vet.example', 'password' => '123456']);
expectStatus('вход с верными данными', 200, $r);
check('в ответе есть токен из 64 символов', strlen($r['json']['token'] ?? '') === 64);
check('в ответе есть срок действия токена', isset($r['json']['expires_at']));
check('в ответе есть пользователь с ролью admin', ($r['json']['user']['role'] ?? '') === 'admin');
check('в ответе НЕТ хеша пароля', !str_contains($r['body'], 'password_hash') && !str_contains($r['body'], '$2y$'));

$wrongPassword = call('POST', '/api/auth/login', ['email' => 'admin@vet.example', 'password' => '000000']);
expectStatus('вход с неверным паролем', 401, $wrongPassword);
$unknownEmail = call('POST', '/api/auth/login', ['email' => 'nobody@vet.example', 'password' => '123456']);
expectStatus('вход с несуществующей почтой', 401, $unknownEmail);
check('сообщения для неверного пароля и несуществующей почты одинаковы',
    ($wrongPassword['json']['error'] ?? 'a') === ($unknownEmail['json']['error'] ?? 'b'));
$r = call('POST', '/api/auth/login', ['email' => '', 'password' => '']);
expectStatus('вход с пустыми полями', 422, $r);
check('ошибки 422 указывают поля email и password', isset($r['json']['errors']['email'], $r['json']['errors']['password']));
expectStatus('вход с телом «abc» (не JSON)', 400, call('POST', '/api/auth/login', null, null, 'abc'));
check('вход с почтой в другом регистре (ADMIN@vet.example)', strlen(login('ADMIN@vet.example')) === 64);

// ---------------------------------------------------------------------
section('2. Защита адресов API (401)');
// ---------------------------------------------------------------------
foreach (['/api/appointments', '/api/animals', '/api/owners', '/api/doctors', '/api/users', '/api/auth/me'] as $path) {
    expectStatus("GET $path без токена", 401, call('GET', $path));
}
expectStatus('POST /api/appointments без токена', 401, call('POST', '/api/appointments', ['animal_id' => 1, 'title' => 'Без входа', 'date' => '2026-12-01T10:00']));
expectStatus('GET /api/appointments с неверным токеном', 401, call('GET', '/api/appointments', null, str_repeat('a', 64)));
$r = call('GET', '/api/auth/me', null, $doctor);
expectStatus('GET /api/auth/me с токеном врача', 200, $r);
check('/api/auth/me возвращает вошедшего пользователя без хеша',
    ($r['json']['email'] ?? '') === 'doctor@vet.example' && !isset($r['json']['password_hash']));
expectStatus('несуществующий адрес с токеном', 404, call('GET', '/api/unknown', null, $admin));
$r = call('DELETE', '/api/appointments', null, $admin);
expectStatus('DELETE /api/appointments (нет такого маршрута)', 405, $r);
check('в ответе 405 есть заголовок Allow', isset($r['headers']['allow']));

// ---------------------------------------------------------------------
section('3. Роли и права (403)');
// ---------------------------------------------------------------------
$created = call('POST', '/api/appointments', ['animal_id' => 2, 'doctor_id' => 3, 'title' => 'Проверка прав', 'date' => '2026-12-01T09:00'], $registrar);
expectStatus('registrar записывает на приём', 201, $created);
$id = $created['json']['id'] ?? 0;
expectStatus('doctor отмечает приём проведённым', 200, call('PATCH', "/api/appointments/$id", ['status' => 'done'], $doctor));
expectStatus('registrar удаляет приём', 403, call('DELETE', "/api/appointments/$id", null, $registrar));
expectStatus('doctor удаляет приём', 403, call('DELETE', "/api/appointments/$id", null, $doctor));
expectStatus('doctor добавляет животное', 403, call('POST', '/api/animals', ['name' => 'Шарик', 'owner_id' => 1], $doctor));
expectStatus('registrar удаляет владельца', 403, call('DELETE', '/api/owners/5', null, $registrar));
expectStatus('registrar добавляет врача', 403, call('POST', '/api/doctors', ['name' => 'Новый врач'], $registrar));
expectStatus('registrar смотрит пользователей', 403, call('GET', '/api/users', null, $registrar));
expectStatus('doctor создаёт пользователя', 403, call('POST', '/api/users', ['name' => 'Хакер', 'email' => 'h@vet.example', 'password' => '123456', 'role' => 'admin'], $doctor));
$r = call('GET', '/api/users', null, $admin);
expectStatus('admin смотрит пользователей', 200, $r);
check('в списке пользователей НЕТ хешей паролей', count(rows($r)) === 3 && !str_contains($r['body'], 'password_hash'));
expectStatus('admin удаляет приём', 204, call('DELETE', "/api/appointments/$id", null, $admin));

// ---------------------------------------------------------------------
section('4. Приёмы: полный цикл (создание → поиск → изменение → удаление)');
// ---------------------------------------------------------------------
$r = call('POST', '/api/appointments', ['animal_id' => 5, 'doctor_id' => 2, 'title' => 'Удаление клеща', 'date' => '2026-12-05T14:30'], $registrar);
expectStatus('создание приёма', 201, $r);
check('в ответе есть Location', str_starts_with($r['headers']['location'] ?? '', '/api/appointments/'));
check('статус по умолчанию — scheduled', ($r['json']['status'] ?? '') === 'scheduled');
check('дата вернулась в формате API 2026-12-05T14:30', ($r['json']['date'] ?? '') === '2026-12-05T14:30');
check('сервер подставил имена животного, владельца и врача',
    ($r['json']['animal'] ?? '') === 'Барсик' && ($r['json']['owner'] ?? '') === 'Иванов И.И.' && ($r['json']['doctor'] ?? '') === 'Петров');
$id = $r['json']['id'] ?? 0;
check('номер приёма — число, а не строка', is_int($id));

$r = call('GET', '/api/appointments?q=' . rawurlencode('КЛЕЩ'), null, $doctor);   // русские буквы в адресе кодируются
check('поиск по части причины без учёта регистра находит приём', count(rows($r)) === 1 && (rows($r)[0]['id'] ?? 0) === $id);
$r = call('GET', '/api/appointments?q=' . rawurlencode("' OR 1=1 --"), null, $admin);
check('SQL-инъекция в поиске не срабатывает (ничего не найдено, запрос не сломан)', $r['status'] === 200 && rows($r) === [] && is_array($r['json']));
$r = call('GET', '/api/appointments?q=' . rawurlencode('%'), null, $admin);
check('знак % в поиске ищется как обычный символ', $r['status'] === 200 && rows($r) === [] && is_array($r['json']));
$r = call('GET', '/api/appointments?sort=-id', null, $admin);
check('sort=-id: новый приём первый', (rows($r)[0]['id'] ?? 0) === $id);
$r = call('GET', '/api/appointments?sort=date', null, $admin);
$dates = array_column(rows($r), 'date');
$sorted = $dates;
sort($sorted);
check('sort=date: по возрастанию даты', count($dates) === 9 && $dates === $sorted);
$r = call('GET', '/api/appointments?status=done', null, $admin);
check('фильтр status=done оставляет только проведённые', count(rows($r)) === 3 && count(array_filter(rows($r), fn($a) => $a['status'] !== 'done')) === 0);
$r = call('GET', '/api/appointments?animal_id=1', null, $admin);
check('фильтр animal_id=1 — приёмы Рекса', count(rows($r)) === 2);
expectStatus('фильтр animal_id=abc', 422, call('GET', '/api/appointments?animal_id=abc', null, $admin));

$r = call('PATCH', "/api/appointments/$id", ['status' => 'cancelled'], $doctor);
expectStatus('PATCH: отменить приём', 200, $r);
check('изменился только статус', ($r['json']['status'] ?? '') === 'cancelled' && ($r['json']['title'] ?? '') === 'Удаление клеща');
$r = call('PATCH', "/api/appointments/$id", ['doctor_id' => null], $admin);
check('PATCH: врача можно снять (null)', $r['status'] === 200 && array_key_exists('doctor_id', $r['json']) && $r['json']['doctor_id'] === null);

$r = call('POST', '/api/appointments', ['animal_id' => '1', 'doctor_id' => 99, 'title' => 'Да', 'date' => '', 'status' => 'x'], $admin);
expectStatus('создание с неверными данными', 422, $r);
check('в ответе 422 перечислены поля animal_id, doctor_id, title, date, status',
    isset($r['json']['errors']['animal_id'], $r['json']['errors']['doctor_id'], $r['json']['errors']['title'], $r['json']['errors']['date'], $r['json']['errors']['status']));
expectStatus('несуществующая дата 2026-02-30T10:00', 422, call('POST', '/api/appointments', ['animal_id' => 1, 'title' => 'Осмотр', 'date' => '2026-02-30T10:00'], $admin));
$r = call('POST', '/api/appointments', ['animal_id' => 2, 'doctor_id' => 1, 'title' => 'Осмотр', 'date' => '2026-11-01T10:00'], $admin);
expectStatus('врач занят в это время (UNIQUE из ЛР №8)', 409, $r);
check('конфликт времени привязан к полю date', isset($r['json']['errors']['date']));

expectStatus('удаление приёма', 204, call('DELETE', "/api/appointments/$id", null, $admin));
expectStatus('повторное удаление', 404, call('DELETE', "/api/appointments/$id", null, $admin));
expectStatus('GET удалённого приёма', 404, call('GET', "/api/appointments/$id", null, $admin));
expectStatus('PATCH несуществующего приёма', 404, call('PATCH', '/api/appointments/9999', ['status' => 'done'], $admin));

// ---------------------------------------------------------------------
section('5. Животные, владельцы, врачи');
// ---------------------------------------------------------------------
$r = call('GET', '/api/animals', null, $doctor);
check('в списке животных есть имя владельца (нужно клиенту из ЛР №5)', ($r['json'][0]['owner'] ?? '') === 'Иванов И.И.');
$r = call('GET', '/api/animals/2/appointments', null, $doctor);
check('история приёмов Мурки — 2 приёма', count(rows($r)) === 2);
$r = call('POST', '/api/animals', ['name' => 'Тузик', 'owner_id' => 5], $registrar);
expectStatus('registrar добавляет животное', 201, $r);
check('вид по умолчанию — other', ($r['json']['species'] ?? '') === 'other');
$animal = $r['json']['id'] ?? 0;
expectStatus('владельца с животными удалить нельзя', 409, call('DELETE', '/api/owners/5', null, $admin));
expectStatus('животное с приёмами удалить нельзя', 409, call('DELETE', '/api/animals/1', null, $admin));
expectStatus('admin удаляет животное без приёмов', 204, call('DELETE', "/api/animals/$animal", null, $admin));
expectStatus('admin удаляет владельца без животных', 204, call('DELETE', '/api/owners/5', null, $admin));
$r = call('POST', '/api/owners', ['name' => 'Морозова Е.Н.', 'phone' => '+79000000009', 'email' => ''], $registrar);
expectStatus('registrar добавляет владельца без почты', 201, $r);
check('пустая почта сохранена как null', array_key_exists('email', $r['json'] ?? []) && $r['json']['email'] === null);
expectStatus('владелец с тем же телефоном', 409, call('POST', '/api/owners', ['name' => 'Двойник', 'phone' => '+79000000009'], $admin));
$r = call('POST', '/api/doctors', ['name' => 'Кузнецов', 'specialty' => 'офтальмолог'], $admin);
expectStatus('admin добавляет врача', 201, $r);
expectStatus('admin удаляет врача Петрова', 204, call('DELETE', '/api/doctors/2', null, $admin));
$r = call('GET', '/api/appointments/2', null, $admin);
check('приём Петрова остался без врача (ON DELETE SET NULL)', $r['status'] === 200 && array_key_exists('doctor_id', $r['json']) && $r['json']['doctor_id'] === null);

// ---------------------------------------------------------------------
section('6. Пользователи, завершение сессии и срок действия токена');
// ---------------------------------------------------------------------
$r = call('POST', '/api/users', ['name' => 'Новый Регистратор', 'email' => 'new@vet.example', 'password' => 'secret1', 'role' => 'registrar'], $admin);
expectStatus('admin создаёт пользователя', 201, $r);
check('в ответе нет пароля и хеша', !isset($r['json']['password']) && !isset($r['json']['password_hash']));
expectStatus('пользователь с повторной почтой', 409, call('POST', '/api/users', ['name' => 'Двойник', 'email' => 'NEW@vet.example', 'password' => 'secret1', 'role' => 'doctor'], $admin));
expectStatus('пользователь со слабым паролем', 422, call('POST', '/api/users', ['name' => 'Слабый', 'email' => 'weak@vet.example', 'password' => '123', 'role' => 'doctor'], $admin));
expectStatus('пользователь с неизвестной ролью', 422, call('POST', '/api/users', ['name' => 'Директор', 'email' => 'boss@vet.example', 'password' => 'secret1', 'role' => 'boss'], $admin));
check('новый пользователь входит со своим паролем', strlen(login('new@vet.example', 'secret1')) === 64);

$token = login('doctor@vet.example');
expectStatus('токен работает до выхода', 200, call('GET', '/api/appointments', null, $token));
expectStatus('POST /api/auth/logout', 204, call('POST', '/api/auth/logout', null, $token));
expectStatus('после выхода токен не работает', 401, call('GET', '/api/appointments', null, $token));

$token = login('doctor@vet.example');
// «Состарим» все токены прямо в базе: срок вышел час назад (тест подключается к той же базе, что и сервер)
define('BASE_PATH', $root);
define('APP_PATH', $root . '/app');
require APP_PATH . '/autoload.php';
require APP_PATH . '/helpers.php';
try {
    $pdo = App\Core\Database::connection();
    $pdo->exec("UPDATE auth_tokens SET expires_at = now() - interval '1 hour'");
    $hashes = $pdo->query('SELECT password_hash FROM users')->fetchAll(PDO::FETCH_COLUMN);
    check('в таблице users только хеши паролей, открытого пароля нет',
        count($hashes) === 4 && count(array_filter($hashes, fn($h) => str_starts_with($h, '$2y$'))) === 4);
    $stmt = $pdo->prepare('SELECT count(*) FROM auth_tokens WHERE token_hash = :value');
    $stmt->execute(['value' => $token]);
    $plain = (int) $stmt->fetchColumn();
    $stmt->execute(['value' => hash('sha256', $token)]);
    check('в таблице auth_tokens лежит хеш токена (SHA-256), а не сам токен', $plain === 0 && (int) $stmt->fetchColumn() === 1);
} catch (Throwable $e) {
    check('подключение к базе данных из теста', false, $e->getMessage());
}
expectStatus('токен с истёкшим сроком', 401, call('GET', '/api/appointments', null, $token));

// ---------------------------------------------------------------------
echo "\nИтого: $passed прошло, $failed не прошло\n";
passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/bin/reset-db.php') . ' > ' . $devNull);   // убираем за собой
exit($failed === 0 ? 0 : 1);
