<?php
// Запускать только с отдельным учебным сервером ЛР №7.
$base = $argv[1] ?? 'http://127.0.0.1:8000';
$results = [];
$log = '';
function request(string $method, string $path, $body = null): array {
    global $base, $log;
    $options = ['method' => $method, 'ignore_errors' => true, 'timeout' => 10,
        'header' => "Content-Type: application/json\r\n"];
    if ($body !== null) $options['content'] = is_string($body) ? $body : json_encode($body, JSON_UNESCAPED_UNICODE);
    $text = @file_get_contents($base . $path, false, stream_context_create(['http' => $options]));
    $headers = $http_response_header ?? [];
    if (!preg_match('/^HTTP\/\S+ (\d+)/', $headers[0] ?? '', $match)) throw new RuntimeException('Сервер недоступен');
    $code = (int) $match[1];
    $text = $text === false ? '' : $text;
    $log .= $method . ' ' . $path . "\n" . ($options['content'] ?? '') . "\n" . implode("\n", $headers) . "\n" . $text . "\n\n";
    echo $method . ' ' . $path . ' -> ' . $code . "\n";
    $data = $code === 204 ? null : json_decode($text, true, 512, JSON_THROW_ON_ERROR);
    return ['code' => $code, 'data' => $data, 'headers' => $headers, 'text' => $text];
}
function check(string $label, int $expected, array $response, bool $extra = true): void {
    global $results;
    $ok = $response['code'] === $expected && $extra;
    $results[] = [$label, $expected, $response['code'], $ok ? 'Выполнено' : 'Ошибка'];
    if (!$ok) throw new RuntimeException($label);
}
function hasHeader(array $response, string $prefix): bool {
    foreach ($response['headers'] as $line) if (stripos($line, $prefix) === 0) return true;
    return false;
}
$backup = [];
foreach (['appointments', 'animals', 'owners'] as $name) {
    $backup[$name] = file_get_contents(__DIR__ . '/../src/storage/' . $name . '.json');
    if ($backup[$name] === false) throw new RuntimeException('Не удалось сохранить копию данных');
}
$exitCode = 0;
try {
    foreach (['owners', 'animals', 'appointments'] as $resource) {
        $r = request('GET', '/api/' . $resource);
        check('Список ' . $resource, 200, $r, array_is_list($r['data']) && hasHeader($r, 'Content-Type: application/json'));
    }
    $r = request('POST', '/api/owners', ['name' => 'Тестовый владелец', 'phone' => '+79000000009']);
    check('Создание владельца', 201, $r, hasHeader($r, 'Location: /api/owners/'));
    $owner = $r['data']['id'];
    check('Просмотр владельца', 200, request('GET', '/api/owners/' . $owner));
    $r = request('PATCH', '/api/owners/' . $owner, ['phone' => '+79000000008', 'id' => 999]);
    check('Изменение владельца', 200, $r, $r['data']['id'] === $owner && $r['data']['phone'] === '+79000000008');
    $r = request('POST', '/api/animals', ['name' => 'Тестовый питомец', 'owner_id' => $owner]);
    check('Создание животного', 201, $r, hasHeader($r, 'Location: /api/animals/'));
    $animal = $r['data']['id'];
    check('Просмотр животного', 200, request('GET', '/api/animals/' . $animal));
    $r = request('PUT', '/api/animals/' . $animal, ['name' => 'Новая кличка']);
    check('Изменение животного', 200, $r, $r['data']['name'] === 'Новая кличка' && $r['data']['owner_id'] === $owner);
    $r = request('POST', '/api/appointments', ['animal_id' => $animal, 'title' => 'Тестовый осмотр', 'doctor' => 'Иванова', 'date' => '2026-11-10T12:00']);
    check('Создание приёма', 201, $r, hasHeader($r, 'Location: /api/appointments/'));
    $visit = $r['data']['id'];
    check('Просмотр приёма', 200, request('GET', '/api/appointments/' . $visit));
    $r = request('PATCH', '/api/appointments/' . $visit, ['status' => 'done']);
    check('Изменение приёма', 200, $r, $r['data']['status'] === 'done');
    $r = request('GET', '/api/appointments?status=done&animal_id=' . $animal . '&q=' . rawurlencode('Тестовый'));
    check('Фильтр и поиск', 200, $r, count($r['data']) === 1);
    $r = request('GET', '/api/animals/' . $animal . '/appointments');
    check('Приёмы животного', 200, $r, count($r['data']) === 1);
    check('Неверный JSON', 400, request('POST', '/api/owners', 'abc'));
    $r = request('POST', '/api/owners', ['name' => 'Да']);
    check('Ошибка полей', 422, $r, isset($r['data']['errors']['name']));
    check('Нет записи', 404, request('GET', '/api/appointments/999999'));
    $r = request('DELETE', '/api/owners');
    check('Неверный метод', 405, $r, hasHeader($r, 'Allow: GET, POST'));
    check('Животное с приёмами', 409, request('DELETE', '/api/animals/' . $animal));
    check('Владелец с животными', 409, request('DELETE', '/api/owners/' . $owner));
    check('Старый адрес', 404, request('GET', '/api/tasks'));
    foreach (['appointments' => $visit, 'animals' => $animal, 'owners' => $owner] as $resource => $id) {
        $r = request('DELETE', '/api/' . $resource . '/' . $id);
        check('Удаление ' . $resource, 204, $r, $r['text'] === '');
    }
} catch (Throwable $error) {
    echo 'Ошибка проверки: ' . $error->getMessage() . "\n";
    $exitCode = 1;
} finally {
    foreach ($backup as $name => $text) {
        if (file_put_contents(__DIR__ . '/../src/storage/' . $name . '.json', $text, LOCK_EX) === false) $exitCode = 1;
    }
    $report = "# Проверка API\n\nПроверка выполнена командой php tests/check.php. PHP " . PHP_VERSION . ".\n\n| Запрос | Ожидаемый код | Полученный код | Результат |\n|---|---|---|---|\n";
    foreach ($results as $row) $report .= '| ' . implode(' | ', $row) . " |\n";
    $report .= "\nИтог: " . ($exitCode === 0 ? 'все проверки выполнены.' : 'есть ошибка, проверка остановлена.') . "\n";
    if (file_put_contents(__DIR__ . '/../docs/testing.md', $report) === false) $exitCode = 1;
    if (file_put_contents(__DIR__ . '/../docs/requests.txt', $log) === false) $exitCode = 1;
}
exit($exitCode);
