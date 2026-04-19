<?php
// src/Router.php
class Router {
    private array $routes = [];

    public function add(string $method, string $path, callable $handler): void {
        $this->routes[] = compact('method', 'path', 'handler');
    }

    public function dispatch(string $method, string $uri): void {
        $uri = strtok($uri, '?');
        foreach ($this->routes as $route) {
            $pattern = '#^' . preg_replace('#\{(\w+)\}#', '(?P<$1>[^/]+)', $route['path']) . '$#';
            if ($route['method'] === $method && preg_match($pattern, $uri, $m)) {
                $params = array_filter($m, 'is_string', ARRAY_FILTER_USE_KEY);
                ($route['handler'])($params);
                return;
            }
        }
        http_response_code(404);
        require __DIR__ . '/../views/404.php';
    }
}
