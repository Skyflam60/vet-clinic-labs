<?php
// bin/benchmark.php — сравнение скорости API без кеша и с кешем.
//
// Запуск (сервер и Redis должны работать; база заполнена: php bin/load-bigdata.php):
//     php bin/benchmark.php                       # 40 запросов на сценарий, нагрузка 8 запросов одновременно
//     php bin/benchmark.php --n=100 --c=16 --d=5  # больше запросов, больше параллельных, нагрузка 5 секунд
//     php bin/benchmark.php --url=http://localhost:8080
//
// Для каждого сценария измеряются:
//   1. БЕЗ КЕША: N запросов подряд с заголовком «X-Cache-Mode: off» (кеш обходится, каждый запрос идёт в базу);
//   2. С КЕШЕМ: кеш очищается, затем N запросов подряд: первый — «холодный» (промах, данные берутся из базы),
//      остальные — «тёплые» (попадания, данные из Redis);
//   3. ПРОПУСКНАЯ СПОСОБНОСТЬ: в течение D секунд сервер получает запросы по C штук одновременно (без кеша и с кешем);
//      считается, сколько запросов в секунду он успел обработать.
// Результат печатается таблицей в формате Markdown: её можно вставить в отчёт.

declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));
define('APP_PATH', BASE_PATH . '/app');
require APP_PATH . '/autoload.php';
require APP_PATH . '/helpers.php';

// ---------------------------------------------------------------------
// Настройки
// ---------------------------------------------------------------------
$options = getopt('', ['url::', 'n::', 'c::', 'd::']);
$base = rtrim((string) ($options['url'] ?? 'http://localhost:8000'), '/');
$n = max(5, (int) ($options['n'] ?? 40));                  // запросов на сценарий (последовательно)
$c = max(1, (int) ($options['c'] ?? 8));                   // сколько запросов выполнять одновременно
$d = max(1, (int) ($options['d'] ?? 3));                   // сколько секунд длится нагрузка при измерении пропускной способности

// Сценарии: что именно измеряем. Каждый — название и адрес. Обоснование выбора — в docs/caching.md.
$scenarios = [
    ['Справочник врачей',                      '/api/doctors'],
    ['Справочник животных (5 000 с владельцами)', '/api/animals'],
    ['Карточка приёма',                        '/api/appointments/250000'],
    ['Список приёмов (последние 100)',         '/api/appointments?sort=-id'],
    ['Поиск по причине обращения',             '/api/appointments?q=' . rawurlencode('осмотр №4999')],
    ['Запланированные приёмы врача 10',        '/api/appointments?doctor_id=10&status=scheduled'],
    ['Приёмы по дате, 50 последних',           '/api/appointments?sort=-date&limit=50'],
    ['Отчёт: загрузка и выручка врачей',       '/api/reports/doctors'],
];
// Свои сценарии можно добавить так:
// $scenarios[] = ['Название сценария', '/api/адрес?параметры'];

// ---------------------------------------------------------------------
// Инструменты (ГОТОВО)
// ---------------------------------------------------------------------

// Один запрос. Возвращает ['ms' => время в миллисекундах, 'status' => код ответа, 'cache' => значение X-Cache, 'total' => X-Total-Count]
function request(string $url, ?string $token, bool $bypass = false): array
{
    $headers = ['Accept: application/json'];
    if ($token !== null) {
        $headers[] = 'Authorization: Bearer ' . $token;
    }
    if ($bypass) {
        $headers[] = 'X-Cache-Mode: off';                  // не использовать кеш в этом запросе
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $headers, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 60]);
    $start = hrtime(true);
    $response = curl_exec($ch);
    $ms = (hrtime(true) - $start) / 1e6;
    if ($response === false) {
        fwrite(STDERR, "Не удалось подключиться к $url. Запущен ли сервер?\n");
        exit(2);
    }
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $head = substr($response, 0, curl_getinfo($ch, CURLINFO_HEADER_SIZE));
    curl_close($ch);
    preg_match('/^x-cache:\s*(\S+)/mi', $head, $m);
    preg_match('/^x-total-count:\s*(\d+)/mi', $head, $t);
    return ['ms' => $ms, 'status' => $status, 'cache' => $m[1] ?? '-', 'total' => isset($t[1]) ? (int) $t[1] : null,
            'body' => substr($response, strlen($head))];
}

// N запросов подряд, возвращает список времён в мс и список значений X-Cache
function sequential(string $url, ?string $token, int $count, bool $bypass): array
{
    $times = $caches = [];
    for ($i = 0; $i < $count; $i++) {
        $r = request($url, $token, $bypass);
        if ($r['status'] !== 200) {
            fwrite(STDERR, "Запрос $url вернул код {$r['status']}\n" . substr($r['body'], 0, 300) . "\n");
            exit(2);
        }
        $times[] = $r['ms'];
        $caches[] = $r['cache'];
    }
    return [$times, $caches];
}

// Нагрузка в течение $seconds секунд: всё время идут $concurrency одновременных запросов. Возвращает запросов в секунду.
function parallel(string $url, ?string $token, float $seconds, int $concurrency, bool $bypass): float
{
    $headers = ['Accept: application/json', 'Authorization: Bearer ' . $token] + ($bypass ? [2 => 'X-Cache-Mode: off'] : []);
    $multi = curl_multi_init();
    $add = function () use ($url, $headers, $multi): void {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $headers, CURLOPT_TIMEOUT => 120]);
        curl_multi_add_handle($multi, $ch);
    };
    $done = 0;
    $inFlight = 0;
    $start = hrtime(true);
    $deadline = $start + (int) ($seconds * 1e9);
    for ($i = 0; $i < $concurrency; $i++) {
        $add();
        $inFlight++;
    }
    while ($inFlight > 0) {
        curl_multi_exec($multi, $running);
        curl_multi_select($multi, 0.05);
        while ($info = curl_multi_info_read($multi)) {
            curl_multi_remove_handle($multi, $info['handle']);
            curl_close($info['handle']);
            $done++;
            $inFlight--;
            if (hrtime(true) < $deadline) {          // время ещё не вышло: сразу отправляем следующий запрос
                $add();
                $inFlight++;
            }
        }
    }
    $elapsed = (hrtime(true) - $start) / 1e9;
    curl_multi_close($multi);
    return $done / $elapsed;
}

function percentile(array $values, float $p): float
{
    sort($values);
    return $values[(int) min(count($values) - 1, floor($p * count($values)))];
}
function avg(array $values): float
{
    return array_sum($values) / max(1, count($values));
}
function fmt(float $ms): string
{
    return $ms >= 10 ? number_format($ms, 0, ',', '') : number_format($ms, 1, ',', '');
}

// ---------------------------------------------------------------------
// Измерения
// ---------------------------------------------------------------------

// Входим администратором и получаем токен
$ch = curl_init($base . '/api/auth/login');
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode(['email' => 'admin@vet.example', 'password' => '123456'])]);
$login = json_decode((string) curl_exec($ch), true);
curl_close($ch);
$token = $login['token'] ?? null;
if ($token === null) {
    fwrite(STDERR, "Не удалось войти. Запущен ли сервер ($base) и загружена ли база (php bin/reset-db.php)?\n");
    exit(2);
}

$check = request($base . '/api/appointments?limit=1', $token);
$total = $check['total'] ?? 0;
echo "Сервер: $base. Приёмов в базе: $total. Запросов на сценарий: $n, нагрузка: $c одновременно в течение $d с.\n";
if ($total < 100000) {
    echo "ВНИМАНИЕ: приёмов меньше 100 000, различия будут почти незаметны. Загрузите данные: php bin/load-bigdata.php\n";
}
if (App\Core\Cache::redis() === null) {
    echo "ВНИМАНИЕ: Redis недоступен для этого скрипта, очистить кеш перед измерением нельзя (php bin/redis-check.php).\n";
}
echo "\n";

$rows = [];
foreach ($scenarios as [$name, $path]) {
    $url = $base . $path;
    echo "измеряю: $name ...\n";

    // 0. Прогрев: несколько запросов, которые не учитываются (чтобы первый «холодный» запрос PHP не искажал среднее)
    sequential($url, $token, 3, true);

    // 1. Без кеша
    [$plain, ] = sequential($url, $token, $n, true);

    // 2. С кешем: сначала очистка, первый запрос — холодный
    App\Core\Cache::flushApp();
    [$cached, $caches] = sequential($url, $token, $n, false);
    $cold = $cached[0];
    $warm = array_slice($cached, 1);
    $hits = count(array_filter(array_slice($caches, 1), fn(string $s): bool => $s === 'HIT'));

    // 3. Пропускная способность (кеш тёплый)
    $rpsPlain = parallel($url, $token, $d, $c, true);
    $rpsCached = parallel($url, $token, $d, $c, false);

    $rows[] = [
        'name' => $name, 'plain_avg' => avg($plain), 'plain_p95' => percentile($plain, 0.95),
        'cold' => $cold, 'warm_avg' => avg($warm), 'warm_p95' => percentile($warm, 0.95),
        'speedup' => avg($plain) / max(0.001, avg($warm)), 'rps_plain' => $rpsPlain, 'rps_cached' => $rpsCached,
        'hits' => $hits, 'warm_count' => count($warm), 'first' => $caches[0],
    ];
}

echo "\n| Сценарий | Без кеша, среднее (p95), мс | С кешем: первый запрос, мс | С кешем, среднее (p95), мс | Ускорение | Запросов/с без кеша | Запросов/с с кешем |\n";
echo "|----------|----------------------------|----------------------------|----------------------------|----------:|--------------------:|-------------------:|\n";
foreach ($rows as $r) {
    printf("| %s | %s (%s) | %s | %s (%s) | ×%s | %s | %s |\n",
        $r['name'], fmt($r['plain_avg']), fmt($r['plain_p95']), fmt($r['cold']), fmt($r['warm_avg']), fmt($r['warm_p95']),
        number_format($r['speedup'], 1, ',', ''), number_format($r['rps_plain'], 0, ',', ''), number_format($r['rps_cached'], 0, ',', ''));
}
echo "\nПроверка кеша: ";
$bad = array_filter($rows, fn(array $r): bool => $r['hits'] < $r['warm_count'] || $r['first'] !== 'MISS');
if ($bad) {
    echo "у сценариев «" . implode('», «', array_column($bad, 'name')) . "» не все повторные запросы дали HIT (или первый не был MISS). Кеш подключён не полностью?\n";
} else {
    echo "у всех сценариев первый запрос — MISS, остальные — HIT.\n";
}
