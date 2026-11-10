<?php
// app/autoload.php — автозагрузка классов.
// Когда в коде встречается App\Controllers\TaskController, PHP вызывает эту функцию,
// а она находит и подключает файл app/Controllers/TaskController.php.
// Благодаря этому не нужно писать require для каждого класса.

spl_autoload_register(function (string $class): void {
    $prefix = 'App\\';
    if (!str_starts_with($class, $prefix)) {
        return;                                              // чужие классы не трогаем
    }
    $relative = substr($class, strlen($prefix));             // Controllers\TaskController
    $file = APP_PATH . '/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($file)) {
        require $file;
    }
});
