<?php
// bin/reset-db.php — возвращает базу данных к исходному состоянию: выполняет sql/schema.sql и sql/seed.sql,
// очищает кеш и очереди задач в Redis и удаляет файлы отчётов.
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

$removed = App\Core\Cache::flushApp();                  // иначе в кеше остались бы данные прежней базы
echo 'кеш очищен (удалено ключей: ' . $removed . ')' . PHP_EOL;

try {
    App\Core\Queue::flush();
    echo 'очереди задач очищены' . PHP_EOL;
} catch (Throwable $e) {
    echo 'очереди не очищены (Redis недоступен)' . PHP_EOL;
}
foreach (glob(BASE_PATH . '/storage/reports/*.csv') ?: [] as $file) {
    unlink($file);
}
