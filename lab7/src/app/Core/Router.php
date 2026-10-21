<?php
namespace App\Core;
class Router {
    private array $routes = [];
    public function add(string $method, string $path, array $handler): void {
        $this->routes[] = [$method, $path, $handler];
    }
    public function get(string $path, array $handler): void { $this->add('GET', $path, $handler); }
    public function post(string $path, array $handler): void { $this->add('POST', $path, $handler); }
    public function put(string $path, array $handler): void { $this->add('PUT', $path, $handler); }
    public function patch(string $path, array $handler): void { $this->add('PATCH', $path, $handler); }
    public function delete(string $path, array $handler): void { $this->add('DELETE', $path, $handler); }
    public function dispatch(string $method, string $path): void {
        $allowed = [];
        foreach ($this->routes as [$verb, $pattern, $handler]) {
            $pattern = str_replace('{id}', '([0-9]+)', $pattern);
            if (!preg_match('~^' . $pattern . '$~', $path, $matches)) continue;
            $allowed[] = $verb;
            if ($verb !== $method) continue;
            array_shift($matches);
            [$class, $action] = $handler;
            (new $class())->$action(...$matches);
            return;
        }
        if ($allowed) {
            header('Allow: ' . implode(', ', array_unique($allowed)));
            json_error('Метод не поддерживается', 405);
        }
        json_error('Адрес не найден', 404);
    }
}
