<?php
// bin/bench-async.php — сколько пользователь ждёт ответа с очередью и без неё.
//
//     php bin/bench-async.php                 # по 5 запросов на режим
//     php bin/bench-async.php --n=10 --url=http://localhost:8000
// Нужны сервер и оба воркера (php bin/worker.php и php bin/worker.php --queue=reports).
//
// 1. Письмо владельцу: регистратор переносит приём № 1 (каждый перенос — письмо, «отправка» NOTIFY_SECONDS = 2 с).
// 2. Отчёт по приёмам в CSV: администратор заказывает отчёт по всей базе.
// Режим sync — заголовок «X-Queue-Mode: sync»: сервер выполняет работу прямо в запросе, как было бы без очереди.
// Режим async — обычная работа: задача в очередь, ответ сразу; для отчёта дополнительно измеряется, когда файл готов.

declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));
define('APP_PATH', BASE_PATH . '/app');
require APP_PATH . '/autoload.php';
require APP_PATH . '/helpers.php';

$options = getopt('', ['url::', 'n::']);
$base = rtrim((string) ($options['url'] ?? 'http://localhost:8000'), '/');
$n = max(2, (int) ($options['n'] ?? 5));

// Один запрос: [время, мс; код ответа; тело JSON; заголовок X-Job-Id]
function request(string $method, string $url, string $token, ?array $body = null, array $extra = []): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_TIMEOUT => 300,
        CURLOPT_HTTPHEADER => ['Accept: application/json', 'Content-Type: application/json', 'Authorization: Bearer ' . $token, ...$extra],
        CURLOPT_POSTFIELDS => $body === null ? null : json_encode($body),
    ]);
    $start = hrtime(true);
    $response = curl_exec($ch);
    $ms = (hrtime(true) - $start) / 1e6;
    if ($response === false) {
        fwrite(STDERR, "Не удалось подключиться к $url. Запущен ли сервер?\n");
        exit(2);
    }
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    preg_match('/^x-job-id:\s*(\S+)/mi', substr($response, 0, $headerSize), $m);
    return [$ms, $code, json_decode(substr($response, $headerSize), true), $m[1] ?? null];
}

function login(string $base, string $email): string
{
    $ch = curl_init($base . '/api/auth/login');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_POSTFIELDS => json_encode(['email' => $email, 'password' => '123456'])]);
    $token = json_decode((string) curl_exec($ch), true)['token'] ?? null;
    if ($token === null) {
        fwrite(STDERR, "Не удалось войти. Запущен ли сервер ($base) и загружена ли база (php bin/reset-db.php)?\n");
        exit(2);
    }
    return $token;
}

function stats(array $times): string
{
    sort($times);
    return sprintf('среднее %6.0f мс, мин %6.0f, макс %6.0f', array_sum($times) / count($times), $times[0], end($times));
}

$registrar = login($base, 'registrar@vet.example');
$admin = login($base, 'admin@vet.example');
$appointments = (int) App\Core\Database::connection()->query('SELECT COUNT(*) FROM appointments')->fetchColumn();
echo "Сервер: $base. Запросов на режим: $n. Приёмов в базе: $appointments.\n";

// ---------- 1. Письмо владельцу ----------
echo "\n1. Перенос приёма (письмо владельцу, «отправка» " . config('queue')['notify_seconds'] . " с)\n";
$mail = [];
request('PATCH', $base . '/api/appointments/1', $registrar, ['date' => '2026-12-10T09:00']);   // исходное время, чтобы каждый замер был переносом
$k = 0;
foreach (['sync' => ['X-Queue-Mode: sync'], 'async' => []] as $mode => $extra) {
    for ($i = 0; $i < $n; $i++) {
        $date = '2026-12-10T' . (10 + $k++ % 2) . ':00';    // каждый раз другое время: иначе это не перенос и письма нет
        $start = hrtime(true);
        [$ms, $code, , $jobId] = request('PATCH', $base . '/api/appointments/1', $registrar, ['date' => $date], $extra);
        if ($code !== 200 || $jobId === null) {
            fwrite(STDERR, "PATCH вернул код $code без X-Job-Id\n");
            exit(2);
        }
        $mail[$mode][] = $ms;
        if ($mode === 'async') {                           // ждём, когда воркер запишет письмо с этим номером задачи
            do {
                usleep(50_000);
                $sent = in_array($jobId, array_column(request('GET', $base . '/api/notifications?appointment_id=1', $registrar)[2] ?? [], 'job_id'), true);
            } while (!$sent && (hrtime(true) - $start) / 1e9 < 60);
            $mail['ready'][] = (hrtime(true) - $start) / 1e6;
        }
    }
    printf("  %-5s: ответ %s\n", $mode, stats($mail[$mode]));
    if ($mode === 'async') {
        printf("         письмо записано через: %s\n", stats($mail['ready']));
    }
}

// ---------- 2. Отчёт по приёмам ----------
echo "\n2. Отчёт по всем приёмам в CSV\n";
$report = ['sync' => [], 'async' => [], 'ready' => []];
for ($i = 0; $i < $n; $i++) {
    [$ms, $code, $json] = request('POST', $base . '/api/reports', $admin, [], ['X-Queue-Mode: sync']);
    $report['sync'][] = $ms;
    printf("  sync  заявка %d: ответ %6.0f мс (%s, строк %s)\n", $json['id'] ?? 0, $ms, $json['status'] ?? $code, $json['rows_count'] ?? '?');
}
for ($i = 0; $i < $n; $i++) {
    $start = hrtime(true);
    [$ms, $code, $json] = request('POST', $base . '/api/reports', $admin, []);
    $status = $json['status'] ?? 'failed';
    while (!in_array($status, ['done', 'failed'], true) && (hrtime(true) - $start) / 1e9 < 300) {
        usleep(100_000);
        $status = request('GET', $base . '/api/reports/' . $json['id'], $admin)[2]['status'] ?? 'failed';
    }
    $ready = (hrtime(true) - $start) / 1e6;
    $report['async'][] = $ms;
    $report['ready'][] = $ready;
    printf("  async заявка %d: ответ %6.0f мс, файл готов через %6.0f мс (%s)\n", $json['id'] ?? 0, $ms, $ready, $status);
}

$avg = fn(array $a): float => array_sum($a) / count($a);
echo "\n| Операция | Без очереди (sync): ответ, мс | С очередью (async): ответ, мс | С очередью: результат готов через, мс | Ответ быстрее |\n";
echo "|----------|------------------------------:|------------------------------:|--------------------------------------:|--------------:|\n";
printf("| Перенос приёма + письмо владельцу | %.0f | %.0f | %.0f | ×%.0f |\n",
    $avg($mail['sync']), $avg($mail['async']), $avg($mail['ready']), $avg($mail['sync']) / max(0.1, $avg($mail['async'])));
printf("| Отчёт по приёмам в CSV (%d строк) | %.0f | %.0f | %.0f | ×%.0f |\n",
    $appointments, $avg($report['sync']), $avg($report['async']), $avg($report['ready']), $avg($report['sync']) / max(0.1, $avg($report['async'])));

file_put_contents(BASE_PATH . '/storage/bench-async.json', json_encode(['appointments' => $appointments, 'n' => $n] + compact('mail', 'report'), JSON_PRETTY_PRINT));
echo "\nСырые замеры: storage/bench-async.json\n";
