<?php
// app/Core/Router.php — маршрутизатор: по методу и адресу запроса выбирает контроллер.

declare(strict_types=1);

namespace App\Core;

final class Router
{
    // Таблица маршрутов. Каждый маршрут — массив из трёх значений:
    //   метод (GET/POST/PUT/PATCH/DELETE), шаблон адреса (/tasks/{id}) и обработчик ([Класс::class, 'метод'])
    private array $routes = [];

    // Регистрация маршрутов: $router->get('/tasks', [TaskController::class, 'index']);
    public function get(string $path, array $handler): void
    {
        $this->add('GET', $path, $handler);
    }

    public function post(string $path, array $handler): void
    {
        $this->add('POST', $path, $handler);
    }

    // Для REST API: обновление и удаление
    public function put(string $path, array $handler): void
    {
        $this->add('PUT', $path, $handler);
    }

    public function patch(string $path, array $handler): void
    {
        $this->add('PATCH', $path, $handler);
    }

    public function delete(string $path, array $handler): void
    {
        $this->add('DELETE', $path, $handler);
    }

    private function add(string $method, string $path, array $handler): void
    {
        $this->routes[] = ['method' => $method, 'path' => $path, 'handler' => $handler];
    }

    // Находит подходящий маршрут и передаёт управление контроллеру
    public function dispatch(string $method, string $uri): void
    {
        // Из /tasks/5?status=done берём только путь /tasks/5; завершающий «/» отбрасываем
        $path = rtrim(parse_url($uri, PHP_URL_PATH) ?: '/', '/') ?: '/';

        $allowed = [];                                     // методы, которые есть у этого адреса

        // Маршруты проверяются по порядку: срабатывает первый подходящий
        foreach ($this->routes as $route) {
            $params = $this->match($route['path'], $path);
            if ($params === null) {
                continue;                                  // адрес не подходит: пробуем следующий
            }
            if ($route['method'] !== $method) {
                $allowed[] = $route['method'];             // адрес есть, но для другого метода: запоминаем
                continue;
            }

            [$class, $action] = $route['handler'];         // например, TaskController::class и 'show'
            $controller = new $class();                    // создаём объект контроллера
            $controller->$action(...$params);              // вызываем метод, передав параметры из адреса
            return;
        }

        // Ни один маршрут не подошёл
        if ($allowed && is_api_request()) {
            // Адрес существует, но не для этого метода (например, DELETE /api/users): код 405
            header('Allow: ' . implode(', ', array_unique($allowed)));
            json_error('Метод ' . $method . ' для этого адреса не поддерживается', 405);
        }
        if (is_api_request()) {
            json_error('Адрес ' . $path . ' не найден', 404);
        }
        not_found_page($path);                             // страница не найдена (для браузера)
    }

    // Сравнивает шаблон маршрута с адресом.
    //   match('/tasks/{id}', '/tasks/5')  вернёт ['id' => '5']
    //   match('/tasks', '/tasks')         вернёт []   (маршрут подошёл, параметров нет)
    //   match('/tasks', '/projects')      вернёт null (не подошёл)
    private function match(string $pattern, string $path): ?array
    {
        // {id} превращаем в кусочек регулярного выражения «любые символы, кроме /»
        $regex = '#^' . preg_replace('#\{(\w+)\}#', '(?P<$1>[^/]+)', $pattern) . '$#';
        if (!preg_match($regex, $path, $matches)) {
            return null;
        }
        return array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);   // оставляем только именованные части
    }
}
