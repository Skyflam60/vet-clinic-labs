<?php
// bin/redis-check.php — проверка подключения приложения к Redis.
// Запуск:  php bin/redis-check.php      Код завершения: 0 — Redis отвечает, 1 — нет.

declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));
define('APP_PATH', BASE_PATH . '/app');
require APP_PATH . '/autoload.php';
require APP_PATH . '/helpers.php';

if (!extension_loaded('redis')) {
    echo "Не установлено расширение PHP redis (phpredis): pecl install redis\n";
    exit(1);
}
$redis = App\Core\Cache::redis();
if ($redis === null) {
    $c = config('cache');
    echo "Redis недоступен ({$c['host']}:{$c['port']}) или кеш выключен (CACHE_ENABLED=0).\n";
    echo "Проверьте: docker compose ps, docker compose up -d, переменные REDIS_HOST и REDIS_PORT.\n";
    exit(1);
}
$info = $redis->info('server');
echo 'Redis отвечает: ', $redis->ping() ? 'PONG' : '?', ', версия ', $info['redis_version'] ?? '?', "\n";
