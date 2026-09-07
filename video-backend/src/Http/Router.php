<?php

declare(strict_types=1);

namespace Drumeo\Video\Http;

final class Router
{
    /** @var list<array{method:string,pattern:string,handler:callable}> */
    private array $routes = [];

    public function get(string $pattern, callable $handler): void
    {
        $this->map('GET', $pattern, $handler);
    }

    public function post(string $pattern, callable $handler): void
    {
        $this->map('POST', $pattern, $handler);
    }

    public function delete(string $pattern, callable $handler): void
    {
        $this->map('DELETE', $pattern, $handler);
    }

    public function map(string $method, string $pattern, callable $handler): void
    {
        $this->routes[] = ['method' => strtoupper($method), 'pattern' => $pattern, 'handler' => $handler];
    }

    public function dispatch(Request $request): Response
    {
        if ($request->method === 'OPTIONS') {
            return Response::json(204, [], [
                'Access-Control-Allow-Origin' => '*',
                'Access-Control-Allow-Headers' => 'Authorization, Content-Type',
                'Access-Control-Allow-Methods' => 'GET, POST, DELETE, OPTIONS',
            ]);
        }

        foreach ($this->routes as $route) {
            if ($route['method'] !== $request->method) {
                continue;
            }
            $params = $this->match($route['pattern'], $request->path);
            if ($params === null) {
                continue;
            }
            $result = ($route['handler'])($request, $params);
            if ($result instanceof Response) {
                return $result;
            }
            throw new \RuntimeException('Route handler must return Response');
        }

        return Response::notFound('unknown endpoint');
    }

    /** @return array<string,string>|null */
    private function match(string $pattern, string $path): ?array
    {
        $regex = preg_replace('#\{([a-zA-Z_][a-zA-Z0-9_]*)\}#', '(?P<$1>[^/]+)', $pattern);
        $regex = '#^' . $regex . '$#';
        if (!preg_match($regex, $path, $m)) {
            return null;
        }
        $params = [];
        foreach ($m as $k => $v) {
            if (is_string($k)) {
                $params[$k] = urldecode($v);
            }
        }
        return $params;
    }
}
