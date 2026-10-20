<?php

declare(strict_types=1);

namespace Sabadgardan\Http;

/**
 * Minimal router: ``$router->get('/stock/{symbol}', [Controller::class, 'show'], auth: true)``.
 * Handlers receive the Request (with ``params`` filled) and return a Response.
 */
final class Router
{
    /** @var list<array{method: string, regex: string, names: list<string>, handler: callable, auth: bool}> */
    private array $routes = [];

    public function get(string $pattern, callable $handler, bool $auth = true): void
    {
        $this->add('GET', $pattern, $handler, $auth);
    }

    public function post(string $pattern, callable $handler, bool $auth = true): void
    {
        $this->add('POST', $pattern, $handler, $auth);
    }

    public function add(string $method, string $pattern, callable $handler, bool $auth): void
    {
        preg_match_all('/\{(\w+)\}/', $pattern, $m);
        $regex = '#^' . preg_replace('/\\\\\{(\w+)\\\\\}/', '([^/]+)', preg_quote($pattern, '#')) . '$#u';
        $this->routes[] = ['method' => $method, 'regex' => $regex, 'names' => $m[1], 'handler' => $handler,
                           'auth' => $auth];
    }

    /** @return array{handler: callable, auth: bool}|null|false null = no path match, false = method not allowed */
    public function match(Request $request): array|null|false
    {
        $pathMatched = false;
        foreach ($this->routes as $route) {
            if (!preg_match($route['regex'], $request->path, $m)) {
                continue;
            }
            $pathMatched = true;
            if ($route['method'] !== $request->method) {
                continue;
            }
            $request->params = array_combine($route['names'], array_slice($m, 1)) ?: [];
            return ['handler' => $route['handler'], 'auth' => $route['auth']];
        }
        return $pathMatched ? false : null;
    }
}
