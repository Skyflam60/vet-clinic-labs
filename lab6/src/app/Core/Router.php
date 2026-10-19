<?php
namespace App\Core;
class Router {
    private array $routes = [];
    public function get(string $path, array $handler): void {
        $this->add('GET', $path, $handler);
    }
    public function post(string $path, array $handler): void {
        $this->add('POST', $path, $handler);
    }
    public function add(string $method, string $path, array $handler): void {
        $this->routes[] = [$method, $path, $handler];
    }
    public function dispatch(string $method, string $path): void {
        foreach ($this->routes as [$routeMethod, $pattern, $handler]) {
            if ($method !== $routeMethod) continue;
            $pattern = str_replace('{id}', '([0-9]+)', $pattern);
            if (!preg_match('~^' . $pattern . '$~', $path, $matches)) continue;
            array_shift($matches);
            [$class, $action] = $handler;
            $controller = new $class();
            $controller->$action(...$matches);
            return;
        }
        notFound();
    }
}
