<?php
// bin/load-bigdata.php — добавляет в базу 500 000 приёмов для измерений (sql/bigdata.sql) и очищает кеш.
// Запуск:  php bin/load-bigdata.php
// Вернуть небольшую базу:  php bin/reset-db.php

declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));
define('APP_PATH', BASE_PATH . '/app');
require APP_PATH . '/autoload.php';
require APP_PATH . '/helpers.php';

$pdo = App\Core\Database::connection();
$start = microtime(true);
$pdo->exec(file_get_contents(BASE_PATH . '/sql/bigdata.sql'));
$total = (int) $pdo->query('SELECT COUNT(*) FROM appointments')->fetchColumn();
$animals = (int) $pdo->query('SELECT COUNT(*) FROM animals')->fetchColumn();
printf("загружено за %.1f с, приёмов в базе: %d, животных: %d\n", microtime(true) - $start, $total, $animals);

App\Core\Cache::flushApp();
echo "кеш очищен\n";
