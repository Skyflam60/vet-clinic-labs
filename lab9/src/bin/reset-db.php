<?php
// bin/reset-db.php — возвращает базу данных к исходному состоянию: выполняет sql/schema.sql и sql/seed.sql.
// Запуск:  php bin/reset-db.php
// Тесты и эксперименты меняют данные; после них базу удобно вернуть одной командой.
// Внимание: все таблицы базы ветеринарной клиники (owners, animals, doctors, appointments, ..., users, auth_tokens)
// удаляются и создаются заново.

declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));
define('APP_PATH', BASE_PATH . '/app');
require APP_PATH . '/autoload.php';
require APP_PATH . '/helpers.php';

$pdo = App\Core\Database::connection();
foreach (['schema', 'seed'] as $name) {
    $pdo->exec(file_get_contents(BASE_PATH . '/sql/' . $name . '.sql'));   // exec выполняет весь файл целиком
    echo 'выполнен sql/' . $name . '.sql' . PHP_EOL;
}
