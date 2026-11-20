<?php
// tests/api-tests.php — автоматические тесты API ветеринарной клиники: вход, права, кеш в Redis, очереди и воркер.
//
// Запуск (сервер должен быть запущен: php -S localhost:8000 -t public):
//     php tests/api-tests.php
//     php tests/api-tests.php http://localhost:8000     # другой адрес сервера
// Тесты сами запускают воркеры и очищают очереди. Свои воркеры на время тестов остановите: они заберут задачи раньше теста.
//
// Тест — это запрос и проверка ответа. Каждая проверка печатает PASS (прошла) или FAIL (не прошла) и пояснение.
// В начале база данных возвращается к исходному состоянию (bin/reset-db.php: заодно очищается кеш в Redis),
// поэтому тесты можно запускать сколько угодно раз. Тесты рассчитаны на небольшую базу (8 приёмов): после
// php bin/load-bigdata.php сначала выполните php bin/reset-db.php (тесты делают это сами в начале).
// Код завершения 0 — все проверки прошли, 1 — есть ошибки (удобно для автоматического запуска).

declare(strict_types=1);

$base = rtrim($argv[1] ?? 'http://localhost:8000', '/');
$root = dirname(__DIR__);

// Подключаем код приложения: тесту нужны база данных и Redis (чтобы «состарить» токены и заглянуть в кеш)
define('BASE_PATH', $root);
define('APP_PATH', $root . '/app');
require APP_PATH . '/autoload.php';
require APP_PATH . '/helpers.php';

// ---------------------------------------------------------------------
// Инструменты теста (ГОТОВО)
// ---------------------------------------------------------------------

// Отправляет запрос. Возвращает ['status' => 200, 'headers' => [...], 'json' => [...], 'body' => '...'].
function call(string $method, string $path, ?array $body = null, ?string $token = null, ?string $rawBody = null, array $extraHeaders = []): array
{
    global $base;
    $headers = ['Accept: application/json', ...$extraHeaders];
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

// Запускает воркер очереди $queue, который выполняет всё, что есть, и выходит. Паузы «отправки письма», «построения отчёта»
// и повторов сокращены до нуля, чтобы тесты шли быстро. Возвращает журнал воркера.
function runWorker(string $queue = 'default', array $env = []): string
{
    $process = startWorker($queue, ['--until-empty'], $env);
    return finishWorker($process);
}

// Запускает воркер в отдельном процессе и не ждёт его (для проверок остановки и падения)
function startWorker(string $queue, array $flags = [], array $env = []): array
{
    global $root;
    $command = [PHP_BINARY, $root . '/bin/worker.php', '--queue=' . $queue, ...$flags];
    $env = array_merge(getenv(), ['NOTIFY_SECONDS' => '0', 'REPORT_SECONDS' => '0', 'QUEUE_BACKOFF' => '0'], $env);
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root, $env);
    return [$process, $pipes];
}

function finishWorker(array $worker): string
{
    [$process, $pipes] = $worker;
    $log = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);
    return $log;
}

// Ждёт, пока в журнале воркера (stdout) появится строка с $text, но не дольше $seconds секунд. Возвращает прочитанное.
function waitFor(array $worker, string $text, float $seconds = 5): string
{
    $out = '';
    stream_set_blocking($worker[1][1], false);
    $end = microtime(true) + $seconds;
    while (!str_contains($out, $text) && microtime(true) < $end) {
        $out .= (string) fread($worker[1][1], 8192);
        usleep(50_000);
    }
    stream_set_blocking($worker[1][1], true);
    return $out;
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
section('7. Кеширование в Redis');
// ---------------------------------------------------------------------
$redis = App\Core\Cache::redis();                          // то же соединение, что у приложения (ключи с префиксом vetclinic:)
if ($redis === null) {
    check('Redis доступен тесту (php bin/redis-check.php)', false, 'не удалось подключиться к Redis');
} else {
    passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/bin/reset-db.php') . ' > ' . $devNull);   // разделы 1–6 меняли данные
    $admin = login('admin@vet.example');                   // раздел 6 «состарил» токены
    $registrar = login('registrar@vet.example');
    $doctor = login('doctor@vet.example');
    $hit = fn(array $r): string => $r['headers']['x-cache'] ?? '-';   // значение заголовка X-Cache: HIT, MISS или BYPASS

    // --- справочник врачей
    $a = call('GET', '/api/doctors', null, $registrar);
    $b = call('GET', '/api/doctors', null, $registrar);
    check('врачи: первый запрос — MISS, второй — HIT', $hit($a) === 'MISS' && $hit($b) === 'HIT', 'получено ' . $hit($a) . ', ' . $hit($b));
    check('врачи: данные из кеша совпадают с данными из базы', $a['json'] === $b['json'] && count(rows($a)) === 3);
    $ttl = $redis->ttl('doctors:all');
    check('у ключа doctors:all время жизни от 1 до 300 с', $ttl > 0 && $ttl <= 300, "ttl = $ttl");
    expectStatus('кеш не отменяет проверку входа: без токена', 401, call('GET', '/api/doctors'));

    // --- справочник животных и инвалидация при добавлении
    $a = call('GET', '/api/animals', null, $registrar);
    $b = call('GET', '/api/animals', null, $registrar);
    check('животные: MISS, затем HIT', $hit($a) === 'MISS' && $hit($b) === 'HIT', 'получено ' . $hit($a) . ', ' . $hit($b));
    call('POST', '/api/animals', ['name' => 'Тузик', 'owner_id' => 5, 'species' => 'dog'], $registrar);
    $c = call('GET', '/api/animals', null, $registrar);
    check('после POST животного справочник пересоздан (MISS) и содержит Тузика',
        $hit($c) === 'MISS' && in_array('Тузик', array_column(rows($c), 'name'), true));

    // --- карточка приёма и инвалидация при изменении
    $a = call('GET', '/api/appointments/1', null, $doctor);
    $b = call('GET', '/api/appointments/1', null, $doctor);
    check('карточка приёма: MISS, затем HIT', $hit($a) === 'MISS' && $hit($b) === 'HIT', 'получено ' . $hit($a) . ', ' . $hit($b));
    check('в Redis лежит JSON карточки (ключ vetclinic:appointments:item:r0:1)',
        json_decode((string) $redis->get('appointments:item:r0:1'), true) === $a['json']);
    call('PATCH', '/api/appointments/1', ['title' => 'Плановый осмотр и прививка'], $registrar);
    $c = call('GET', '/api/appointments/1', null, $doctor);
    check('после PATCH карточка пересоздана (MISS) и содержит новую причину',
        $hit($c) === 'MISS' && ($c['json']['title'] ?? '') === 'Плановый осмотр и прививка', 'получено ' . $hit($c) . ', ' . ($c['json']['title'] ?? ''));

    // --- список приёмов: ключ из всех параметров, X-Total-Count, версия
    $a = call('GET', '/api/appointments?sort=-id', null, $admin);
    $b = call('GET', '/api/appointments?sort=-id', null, $admin);
    check('список: MISS, затем HIT', $hit($a) === 'MISS' && $hit($b) === 'HIT', 'получено ' . $hit($a) . ', ' . $hit($b));
    check('X-Total-Count одинаков при MISS и HIT и равен 8', ($a['headers']['x-total-count'] ?? '') === '8' && ($b['headers']['x-total-count'] ?? '') === '8');
    $r1 = call('GET', '/api/appointments?status=done', null, $admin);
    $r2 = call('GET', '/api/appointments?status=cancelled', null, $admin);
    check('разные параметры — разные ключи (оба MISS, данные разные)',
        $hit($r1) === 'MISS' && $hit($r2) === 'MISS' && count(rows($r1)) === 3 && count(rows($r2)) === 2);
    $r3 = call('GET', '/api/appointments?status=done&limit=1', null, $admin);
    check('limit входит в ключ: ?status=done&limit=1 — MISS и одна строка', $hit($r3) === 'MISS' && count(rows($r3)) === 1);
    $version = (int) $redis->get('appointments:version');
    $created = call('POST', '/api/appointments', ['animal_id' => 3, 'doctor_id' => 2, 'title' => 'Проверка кеша', 'date' => '2026-12-10T12:00'], $registrar);
    $newId = $created['json']['id'] ?? 0;
    $list = call('GET', '/api/appointments?sort=-id', null, $admin);
    check('после POST версия списков выросла, список — MISS, новый приём первый',
        (int) $redis->get('appointments:version') === $version + 1 && $hit($list) === 'MISS' && (rows($list)[0]['id'] ?? 0) === $newId);

    // --- отчёт по врачам
    $a = call('GET', '/api/reports/doctors', null, $admin);
    $b = call('GET', '/api/reports/doctors', null, $doctor);
    check('отчёт: MISS, затем HIT', $hit($a) === 'MISS' && $hit($b) === 'HIT', 'получено ' . $hit($a) . ', ' . $hit($b));
    expectStatus('кеш не отменяет проверку роли: регистратору отчёт недоступен', 403, call('GET', '/api/reports/doctors', null, $registrar));
    $before = array_sum(array_column(rows($a), 'total'));
    call('PATCH', "/api/appointments/$newId", ['status' => 'done'], $doctor);
    expectStatus('DELETE приёма', 204, call('DELETE', "/api/appointments/$newId", null, $admin));
    $report = call('GET', '/api/reports/doctors', null, $admin);
    check('после DELETE отчёт пересчитан (MISS): приёмов на один меньше',
        $hit($report) === 'MISS' && array_sum(array_column(rows($report), 'total')) === $before - 1);
    expectStatus('после DELETE карточка не «залипла»: GET — 404', 404, call('GET', "/api/appointments/$newId", null, $admin));
    check('отсутствующий приём (404) не кладётся в кеш', $redis->exists('appointments:item:r0:' . $newId) === 0);

    // --- переименование врача: имя есть в справочнике, отчёте, карточках и списках приёмов
    call('GET', '/api/appointments/2', null, $admin);
    call('GET', '/api/appointments?doctor_id=2', null, $admin);
    call('PATCH', '/api/doctors/2', ['name' => 'Петров А.В.'], $admin);
    $card = call('GET', '/api/appointments/2', null, $admin);
    $list = call('GET', '/api/appointments?doctor_id=2', null, $admin);
    $docs = call('GET', '/api/doctors', null, $admin);
    $report = call('GET', '/api/reports/doctors', null, $admin);
    check('после переименования врача карточка, список, справочник и отчёт пересозданы (все MISS)',
        [$hit($card), $hit($list), $hit($docs), $hit($report)] === ['MISS', 'MISS', 'MISS', 'MISS']);
    check('везде новое имя врача «Петров А.В.»',
        ($card['json']['doctor'] ?? '') === 'Петров А.В.' && (rows($list)[0]['doctor'] ?? '') === 'Петров А.В.'
        && in_array('Петров А.В.', array_column(rows($docs), 'name'), true) && in_array('Петров А.В.', array_column(rows($report), 'name'), true));
    check('в Redis выросла версия справочников refs:version', (int) $redis->get('refs:version') >= 1);

    // --- переименование владельца
    call('GET', '/api/animals', null, $admin);
    call('PATCH', '/api/owners/1', ['name' => 'Иванов Иван Иванович'], $admin);
    $animals = call('GET', '/api/animals', null, $admin);
    $card = call('GET', '/api/appointments/1', null, $admin);
    check('после переименования владельца справочник животных и карточка приёма показывают новое имя',
        $hit($animals) === 'MISS' && (rows($animals)[0]['owner'] ?? '') === 'Иванов Иван Иванович' && ($card['json']['owner'] ?? '') === 'Иванов Иван Иванович');

    // --- обход кеша и срок жизни
    $bypass = call('GET', '/api/doctors', null, $admin, null, ['X-Cache-Mode: off']);
    check('заголовок X-Cache-Mode: off обходит кеш (BYPASS)', $hit($bypass) === 'BYPASS', 'получено ' . $hit($bypass));
    call('GET', '/api/doctors', null, $admin);
    $redis->expire('doctors:all', 1);                      // «состарим» ключ: через секунду Redis его удалит
    sleep(2);
    $after = call('GET', '/api/doctors', null, $admin);
    check('по истечении времени жизни ключ удалён, следующий запрос — MISS', $hit($after) === 'MISS', 'получено ' . $hit($after));

    // --- все ключи приложения с префиксом
    $keys = $redis->keys('*');                             // KEYS с префиксом: phpredis добавляет vetclinic: сам
    check('все ключи приложения начинаются с префикса vetclinic:', $keys !== [] && count(array_filter($keys, fn($k) => !str_starts_with($k, 'vetclinic:'))) === 0);
}

// ---------------------------------------------------------------------
section('8. Очередь: письмо владельцу о приёме');
// ---------------------------------------------------------------------
use App\Core\Queue;
use App\Core\Database;

passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/bin/reset-db.php') . ' > ' . $devNull);   // разделы 1–7 меняли данные и ставили письма
$admin = login('admin@vet.example');
$registrar = login('registrar@vet.example');
$doctor = login('doctor@vet.example');
$notes = fn(?int $appointment = null): array => rows(call('GET', '/api/notifications' . ($appointment ? "?appointment_id=$appointment" : ''), null, $registrar));

$started = microtime(true);
$r = call('PATCH', '/api/appointments/1', ['status' => 'cancelled'], $registrar);   // регистратор отменяет приём Рекса (владелец Иванов)
$ms = (microtime(true) - $started) * 1000;
expectStatus('PATCH: отмена приёма', 200, $r);
check('ответ пришёл быстро (меньше 1 с), хотя «отправка письма» занимает 2 с', $ms < 1000, round($ms) . ' мс');
check('в ответе есть заголовок X-Job-Id (16 символов)', strlen($r['headers']['x-job-id'] ?? '') === 16, 'получено «' . ($r['headers']['x-job-id'] ?? '') . '»');
check('задача лежит в очереди default, очередь отчётов пуста', Queue::stats()['waiting'] === 1 && Queue::stats('reports')['waiting'] === 0, json_encode(Queue::stats()));
$raw = Queue::redis()->lIndex('queue:default', 0);
$job = json_decode((string) $raw, true) ?? [];
check('в задаче тип, событие, почта и снимок данных письма (не вся запись из базы)',
    ($job['type'] ?? '') === 'notification' && ($job['attempts'] ?? -1) === 0
    && array_keys($job['payload'] ?? []) === ['appointment_id', 'event', 'email', 'owner', 'animal', 'title', 'date', 'doctor']
    && $job['payload']['event'] === 'cancelled' && $job['payload']['email'] === 'ivanov@example.org', (string) $raw);
check('письма ещё нет: его «отправит» воркер', count($notes()) === 0);

$log = runWorker();
$s = Queue::stats();
check('воркер выполнил задачу: очередь, «в работе», повторы и «мёртвые» пусты', $s === ['waiting' => 0, 'processing' => 0, 'delayed' => 0, 'dead' => 0], json_encode($s));
check('в журнале воркера «начал» и «готово»', str_contains($log, 'notification: начал (попытка 1)') && str_contains($log, 'notification: готово за'));
$list = $notes();
check('письмо записано: Иванову, тема «Приём отменён», в тексте причина и кличка',
    count($list) === 1 && $list[0]['recipient'] === 'ivanov@example.org' && $list[0]['subject'] === 'Приём отменён'
    && str_contains($list[0]['message'], '«Плановый осмотр»') && str_contains($list[0]['message'], 'Рекс')
    && $list[0]['job_id'] === ($job['id'] ?? ''), json_encode($list, JSON_UNESCAPED_UNICODE));

$r = call('POST', '/api/appointments', ['animal_id' => 2, 'doctor_id' => 3, 'title' => 'Осмотр после прививки', 'date' => '2026-12-01T09:00'], $registrar);
expectStatus('POST: новая запись', 201, $r);
check('новая запись тоже ставит письмо (X-Job-Id)', strlen($r['headers']['x-job-id'] ?? '') === 16);
$newId = $r['json']['id'] ?? 0;
call('PATCH', "/api/appointments/$newId", ['date' => '2026-12-02T09:00'], $registrar);
call('PATCH', "/api/appointments/$newId", ['status' => 'done'], $doctor);
check('перенос и проведение — ещё две задачи (в очереди 3)', Queue::stats()['waiting'] === 3);
runWorker();
check('письма Петровой по порядку: запись, перенос, приём проведён',
    array_column(array_reverse($notes($newId)), 'subject') === ['Вы записаны на приём', 'Приём перенесён', 'Приём проведён'],
    json_encode(array_column($notes($newId), 'subject'), JSON_UNESCAPED_UNICODE));
check('в письме о переносе новое время', str_contains($notes($newId)[1]['message'] ?? '', '02.12.2026 в 09:00'));

$r = call('PATCH', '/api/appointments/4', ['title' => 'Осмотр уха и чистка'], $registrar);
check('правка причины обращения без переноса и смены статуса письма не ставит', !isset($r['headers']['x-job-id']) && Queue::stats()['waiting'] === 0);
$r = call('PATCH', '/api/appointments/3', ['status' => 'scheduled', 'date' => '2026-12-03T10:00'], $registrar);
check('владельцу без почты (Сидоров) письмо не ставится', $r['status'] === 200 && !isset($r['headers']['x-job-id']) && Queue::stats()['waiting'] === 0);

$r = call('PATCH', '/api/appointments/4', ['status' => 'cancelled'], $registrar, null, ['X-Queue-Mode: sync']);
check('режим sync: письмо отправлено прямо в запросе (X-Job-Id: sync, ответ дольше)', ($r['headers']['x-job-id'] ?? '') === 'sync');
check('режим sync: очередь пуста, а письмо уже в журнале', Queue::stats()['waiting'] === 0 && count($notes(4)) === 1);
expectStatus('GET /api/notifications без токена', 401, call('GET', '/api/notifications'));
expectStatus('врач смотрит журнал писем', 403, call('GET', '/api/notifications', null, $doctor));

// ---------------------------------------------------------------------
section('9. Воркер: ошибки, повторы, восстановление после сбоя, остановка');
// ---------------------------------------------------------------------
Queue::flush();
Queue::push('no_such_type', ['x' => 1]);
$log = runWorker();
$s = Queue::stats();
check('неудачная задача после всех попыток ушла в «мёртвую» очередь', $s === ['waiting' => 0, 'processing' => 0, 'delayed' => 0, 'dead' => 1], json_encode($s));
$dead = Queue::dead()[0] ?? [];
check('в «мёртвой» задаче число попыток (3) и текст ошибки', ($dead['attempts'] ?? 0) === 3 && str_contains($dead['error'] ?? '', 'Неизвестный тип'), json_encode($dead, JSON_UNESCAPED_UNICODE));
check('воркер не остановился из-за ошибки и записал два повтора', substr_count($log, 'повтор позже') === 2 && str_contains($log, 'воркер остановлен'));

Queue::flush();
Queue::push('demo', ['seconds' => 0, 'succeed_on' => 3]);
$log = runWorker();
$s = Queue::stats();
check('нестабильная задача выполнилась с третьей попытки', substr_count($log, 'готово за') === 1 && $s['dead'] === 0 && $s['delayed'] === 0, json_encode($s));

Queue::flush();
Queue::push('demo', ['seconds' => 1]);
$time = microtime(true);
Queue::push('demo', ['seconds' => 0, 'fail' => true]);
$log = runWorker('default', ['QUEUE_BACKOFF' => '1']);
check('паузы перед повторами растут: 1 с, затем 2 с', preg_match_all('/ОШИБКА/u', $log) === 3 && microtime(true) - $time >= 3, round(microtime(true) - $time, 1) . ' с');

Queue::flush();
Queue::push('demo', ['seconds' => 0]);
Queue::reserve('default', 1);                              // «воркер взял задачу и умер»
check('задача, взятая упавшим воркером, лежит в «в работе»', Queue::stats()['processing'] === 1);
$log = runWorker('default', ['QUEUE_STUCK_AFTER' => '0']);   // в тесте не ждём срока: других воркеров нет
check('новый воркер вернул её в очередь и выполнил', str_contains($log, 'возвращено в очередь') && str_contains($log, 'готово за') && Queue::stats()['processing'] === 0);

Queue::flush();
Queue::push('demo', ['seconds' => 30]);
$worker = startWorker('default');
waitFor($worker, 'demo: начал');
proc_terminate($worker[0], 9);                             // kill -9 посреди задачи
finishWorker($worker);
check('kill -9 посреди задачи: задача осталась в «в работе», а не пропала', Queue::stats()['processing'] === 1, json_encode(Queue::stats()));
Queue::redis()->lSet('queue:default:processing', 0, str_replace('"seconds":30', '"seconds":0', Queue::redis()->lIndex('queue:default:processing', 0)));
$log = runWorker('default', ['QUEUE_STUCK_AFTER' => '2']);
check('перезапущенный воркер не забирает только что взятую задачу (срок 2 с ещё не прошёл)', !str_contains($log, 'возвращено') && Queue::stats()['processing'] === 1);
sleep(2);
$log = runWorker('default', ['QUEUE_STUCK_AFTER' => '2']);
check('после перезапуска воркера задача выполнена', str_contains($log, 'возвращено в очередь задач, застрявших после сбоя: 1') && Queue::stats() === ['waiting' => 0, 'processing' => 0, 'delayed' => 0, 'dead' => 0]);

if (function_exists('pcntl_signal')) {
    Queue::flush();
    Queue::push('demo', ['seconds' => 2]);
    $worker = startWorker('default');
    $out = waitFor($worker, 'demo: начал');
    proc_terminate($worker[0], 2);                         // Ctrl+C (SIGINT) посреди задачи
    $log = $out . finishWorker($worker);
    check('Ctrl+C посреди задачи: воркер доделал её и вышел', str_contains($log, 'получен сигнал остановки') && str_contains($log, 'demo: готово за 2')
        && str_contains($log, 'выполнено задач: 1') && Queue::stats()['processing'] === 0, $log);
}

putenv('NOTIFY_SECONDS=0');
$payload = ['appointment_id' => 2, 'event' => 'done', 'email' => 'petrova@example.org', 'owner' => 'Петрова А.А.', 'animal' => 'Мурка', 'title' => 'Вакцинация кошки', 'date' => '2026-11-02T10:00', 'doctor' => 'Петров'];
(new App\Jobs\NotificationJob())->handle($payload, 'testjob00000001', 1);
(new App\Jobs\NotificationJob())->handle($payload, 'testjob00000001', 2);   // та же задача ещё раз (после сбоя до ack)
$count = (int) Database::connection()->query("SELECT COUNT(*) FROM notifications WHERE job_id = 'testjob00000001'")->fetchColumn();
check('идемпотентность: повторное выполнение не создаёт второго письма', $count === 1, "писем: $count");
call('DELETE', '/api/appointments/2', null, $admin);
(new App\Jobs\NotificationJob())->handle(['event' => 'cancelled'] + $payload, 'testjob00000002', 1);
$row = Database::connection()->query("SELECT appointment_id FROM notifications WHERE job_id = 'testjob00000002'")->fetch();
check('приём удалён до отправки: письмо всё равно уходит (без ссылки на приём)', $row !== false && $row['appointment_id'] === null);

// ---------------------------------------------------------------------
section('10. Отчёт по приёмам в фоне: POST → 202 → воркер → CSV-файл');
// ---------------------------------------------------------------------
passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/bin/reset-db.php') . ' > ' . $devNull);
$admin = login('admin@vet.example');
$registrar = login('registrar@vet.example');
$doctor = login('doctor@vet.example');
$pdo = Database::connection();

$started = microtime(true);
$r = call('POST', '/api/reports', [], $admin);
$ms = (microtime(true) - $started) * 1000;
expectStatus('POST /api/reports', 202, $r);
check('ответ сразу (меньше 1 с), хотя отчёт строится дольше', $ms < 1000, round($ms) . ' мс');
check('в ответе Location и X-Job-Id', ($r['headers']['location'] ?? '') === '/api/reports/' . ($r['json']['id'] ?? '?') && strlen($r['headers']['x-job-id'] ?? '') === 16);
check('статус новой заявки — queued', ($r['json']['status'] ?? '') === 'queued');
check('задача лежит в очереди reports (в default — нет)', Queue::stats('reports')['waiting'] === 1 && Queue::stats()['waiting'] === 0);
$reportId = $r['json']['id'] ?? 0;
check('до воркера заявка queued, скачать нельзя (409)', (call('GET', "/api/reports/$reportId", null, $admin)['json']['status'] ?? '') === 'queued'
    && call('GET', "/api/reports/$reportId/download", null, $admin)['status'] === 409);

$log = runWorker('reports');
$r = call('GET', "/api/reports/$reportId", null, $admin);
$total = (int) $pdo->query('SELECT COUNT(*) FROM appointments')->fetchColumn();
$revenue = (float) $pdo->query("SELECT COALESCE(SUM(s.quantity * s.price), 0) FROM appointment_services s JOIN appointments a ON a.id = s.appointment_id WHERE a.status = 'done'")->fetchColumn();
check('после воркера done: строк столько, сколько приёмов, выручка как в базе',
    ($r['json']['status'] ?? '') === 'done' && ($r['json']['rows_count'] ?? -1) === $total && abs((float) ($r['json']['total_revenue'] ?? -1) - $revenue) < 0.01,
    json_encode($r['json'], JSON_UNESCAPED_UNICODE));
$file = call('GET', "/api/reports/$reportId/download", null, $admin);
expectStatus('скачивание готового отчёта', 200, $file);
$lines = array_values(array_filter(explode("\n", trim($file['body']))));
check('CSV: метка UTF-8, заголовок на русском, по строке на приём', str_starts_with($file['body'], "\xEF\xBB\xBF")
    && str_contains($lines[0], 'Номер;Дата;Статус') && count($lines) === $total + 1);
check('в строке приёма услуги и сумма', str_contains($file['body'], '"Обработка раны ×2, Первичный осмотр";3000,00'));

$r = call('POST', '/api/reports', ['date_from' => '2026-11-02', 'date_to' => '2026-11-05', 'status' => 'done'], $doctor);
$doctorReport = $r['json']['id'] ?? 0;
runWorker('reports');
check('отчёт с периодом и статусом: только проведённые 2–5 ноября (2 приёма)', (call('GET', "/api/reports/$doctorReport", null, $doctor)['json']['rows_count'] ?? -1) === 2);
expectStatus('отчёт с неверной датой', 422, call('POST', '/api/reports', ['date_from' => '2026-02-30'], $admin));
expectStatus('отчёт с концом периода раньше начала', 422, call('POST', '/api/reports', ['date_from' => '2026-12-01', 'date_to' => '2026-11-01'], $admin));
expectStatus('регистратор заказывает отчёт', 403, call('POST', '/api/reports', [], $registrar));
call('POST', '/api/users', ['name' => 'Петров (врач)', 'email' => 'petrov@vet.example', 'password' => 'secret1', 'role' => 'doctor'], $admin);
$doctor2 = login('petrov@vet.example', 'secret1');
expectStatus('врач смотрит чужую заявку', 403, call('GET', "/api/reports/$doctorReport", null, $doctor2));
expectStatus('администратор смотрит заявку врача', 200, call('GET', "/api/reports/$doctorReport", null, $admin));
expectStatus('несуществующая заявка', 404, call('GET', '/api/reports/9999', null, $admin));
expectStatus('POST /api/reports без токена', 401, call('POST', '/api/reports', []));

Queue::flush();
$pdo->exec("INSERT INTO report_requests (id, user_id) VALUES (500, 1)");
Queue::push('report', ['report_id' => 500], 'reports');
$pdo->exec("UPDATE report_requests SET date_from = '2026-01-01', date_to = '2026-12-31' WHERE id = 500");
$pdo->exec("ALTER TABLE report_requests ADD CONSTRAINT test_no_done CHECK (status <> 'done') NOT VALID");   // «сбой»: записать результат нельзя
runWorker('reports');
$failedReport = call('GET', '/api/reports/500', null, $admin)['json'] ?? [];
check('отчёт, который не удалось построить за 3 попытки: заявка failed с текстом ошибки, задача в «мёртвой» очереди',
    ($failedReport['status'] ?? '') === 'failed' && ($failedReport['error'] ?? '') !== '' && Queue::stats('reports')['dead'] === 1, json_encode($failedReport, JSON_UNESCAPED_UNICODE));
$pdo->exec('ALTER TABLE report_requests DROP CONSTRAINT test_no_done');

// ---------------------------------------------------------------------
section('11. Свои проверки: отдельные очереди, несколько воркеров, состояние очередей');
// ---------------------------------------------------------------------
Queue::flush();
call('POST', '/api/reports', [], $admin);
call('POST', '/api/reports', [], $admin);
call('PATCH', '/api/appointments/4', ['status' => 'done'], $doctor);
$time = microtime(true);
runWorker('default', ['NOTIFY_SECONDS' => '1']);
check('письмо не ждёт отчёты: воркер default выполнил его, отчёты остались в своей очереди',
    Queue::stats()['waiting'] === 0 && Queue::stats('reports')['waiting'] === 2 && microtime(true) - $time < 3, round(microtime(true) - $time, 1) . ' с');

$stats = call('GET', '/api/queue/stats', null, $admin);
expectStatus('GET /api/queue/stats (администратор)', 200, $stats);
check('в состоянии очередей видно 2 отчёта в ожидании и возраст самой старой задачи',
    ($stats['json']['reports']['waiting'] ?? -1) === 2 && is_int($stats['json']['reports']['oldest_wait_seconds'] ?? null)
    && array_key_exists('oldest_wait_seconds', $stats['json']['default'] ?? []) && $stats['json']['default']['oldest_wait_seconds'] === null, json_encode($stats['json']));
expectStatus('регистратор смотрит состояние очередей', 403, call('GET', '/api/queue/stats', null, $registrar));

Queue::flush();
for ($i = 0; $i < 6; $i++) {
    Queue::push('demo', ['seconds' => 1]);
}
$time = microtime(true);
$w1 = startWorker('default', ['--until-empty']);
waitFor($w1, 'demo: начал');                               // первый уже выполняет задачу, когда запускается второй
$w2 = startWorker('default', ['--until-empty']);
$log = finishWorker($w1) . finishWorker($w2);
$elapsed = microtime(true) - $time;
preg_match_all('/#(\w+) demo: готово/u', $log, $m);
check('два воркера: 6 задач по 1 с выполнены каждая ровно один раз', count($m[1]) === 6 && count(array_unique($m[1])) === 6, count($m[1]) . ' выполнено');
check('два воркера вдвое быстрее одного: меньше 5 с вместо 6', $elapsed < 5, round($elapsed, 1) . ' с');
Queue::flush();

// ---------------------------------------------------------------------
echo "\nИтого: $passed прошло, $failed не прошло\n";
passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/bin/reset-db.php') . ' > ' . $devNull);   // убираем за собой
exit($failed === 0 ? 0 : 1);
