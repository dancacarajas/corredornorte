<?php

declare(strict_types=1);

namespace App\Helpers;

final class Router
{
    /** @var list<array{method:string,pattern:string,regex:string,handler:callable|array{0:class-string,1:string}}> */
    private array $routes = [];

    public function get(string $path, callable|array $handler): void
    {
        $this->add('GET', $path, $handler);
    }

    public function post(string $path, callable|array $handler): void
    {
        $this->add('POST', $path, $handler);
    }

    public function dispatch(string $method, string $uri): void
    {
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';
        $path = $this->normalize($path);

        foreach ($this->routes as $route) {
            if ($route['method'] !== $method) {
                continue;
            }
            if (!preg_match($route['regex'], $path, $matches)) {
                continue;
            }

            $params = [];
            foreach ($matches as $key => $value) {
                if (!is_int($key)) {
                    $params[$key] = $value;
                }
            }

            $handler = $route['handler'];
            if (is_array($handler)) {
                [$class, $action] = $handler;
                $controller = new $class();
                $controller->{$action}(...array_values($params));
                return;
            }

            $handler(...array_values($params));
            return;
        }

        http_response_code(404);
        View::render('ui/not-found', [
            'pageTitle' => 'Página não encontrada',
            'activeMenu' => '',
        ]);
    }

    private function add(string $method, string $path, callable|array $handler): void
    {
        $normalized = $this->normalize($path);
        $regex = preg_replace('#\{([a-zA-Z_][a-zA-Z0-9_]*)\}#', '(?P<$1>[^/]+)', $normalized);
        $regex = '#^' . $regex . '$#';

        $this->routes[] = [
            'method' => $method,
            'pattern' => $normalized,
            'regex' => $regex,
            'handler' => $handler,
        ];
    }

    private function normalize(string $path): string
    {
        $path = '/' . trim($path, '/');
        return $path === '/' ? '/' : rtrim($path, '/');
    }
}
