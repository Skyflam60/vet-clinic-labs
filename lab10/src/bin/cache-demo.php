<?php
// bin/cache-demo.php — проверка методов Cache без приложения: set, get, remember.
// Запуск:  php bin/cache-demo.php

declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));
define('APP_PATH', BASE_PATH . '/app');
require APP_PATH . '/autoload.php';
require APP_PATH . '/helpers.php';

use App\Core\Cache;

Cache::forget('demo:value', 'demo:slow');

echo "--- set и get (задание 2.1)\n";
echo 'get до записи:    ', json_encode(Cache::get('demo:value'), JSON_UNESCAPED_UNICODE), "\n";   // ключа ещё нет: null
Cache::set('demo:value', ['title' => 'Привет', 'items' => [1, 2, 3]], 30);   // положили на 30 секунд
echo 'get после записи: ', json_encode(Cache::get('demo:value'), JSON_UNESCAPED_UNICODE), "\n";   // массив вернулся таким же

echo "--- remember (задание 2.2): «медленная» функция выполняется 1 секунду\n";
$slow = function (): array {
    sleep(1);
    return ['answer' => 42];
};
foreach ([1, 2, 3] as $n) {
    $start = microtime(true);
    $value = Cache::remember('demo:slow', 30, $slow);
    printf("вызов %d: %.3f с, статус %s, значение %s\n", $n, microtime(true) - $start, Cache::status(), json_encode($value));
}
echo "Проверьте в Redis: docker compose exec redis redis-cli TTL vetclinic:demo:value\n";
