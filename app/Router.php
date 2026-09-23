<?php

declare(strict_types=1);

/**
 * Minimal front-controller router (Phase 1).
 *
 * - static route table, registered in router.php
 * - placeholder patterns: /articles/{id}
 * - 405 when the path is known but the method is not
 * - 404 (custom handler) for unknown paths
 * - no framework, no external dependencies
 *
 * dispatch() never exits: it is the last statement of index.php, and
 * staying exit-free keeps it fully testable.
 */
final class Router
{
    /** @var list<array{method:string, regex:string, params:list<string>, handler:callable}> */
    private array $routes = [];

    /** @var (callable|null) */
    private $notFoundHandler = null;

    /**
     * Register a GET route.
     */
    public function get(string $pattern, callable $handler): void
    {
        $this->add('GET', $pattern, $handler);
    }

    /**
     * Register a route for an explicit HTTP method.
     * Placeholder syntax: /articles/{id}
     */
    public function add(string $method, string $pattern, callable $handler): void
    {
        $params = [];
        $regex = (string) preg_replace_callback(
            '/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/',
            static function (array $m) use (&$params): string {
                $params[] = $m[1];
                return '([^/]+)';
            },
            $pattern
        );

        $this->routes[] = [
            'method' => strtoupper($method),
            'regex' => '#^' . $regex . '$#',
            'params' => $params,
            'handler' => $handler,
        ];
    }

    /**
     * Register the 404 handler (must output the 404 page).
     */
    public function notFound(callable $handler): void
    {
        $this->notFoundHandler = $handler;
    }

    /**
     * Match a request to a registered route.
     * Returns route info or null when the path is unknown (method ignored).
     *
     * @return array{method:string, params:array<string,string>}|null
     */
    public function match(string $method, string $path): ?array
    {
        foreach ($this->routes as $route) {
            if (!preg_match($route['regex'], $path, $matches)) {
                continue;
            }
            array_shift($matches);

            return [
                'method' => $route['method'],
                'params' => $route['params'] === [] ? [] : array_combine($route['params'], $matches),
            ];
        }

        return null;
    }

    /**
     * Dispatch the request to its handler.
     */
    public function dispatch(string $method, string $path): void
    {
        $method = strtoupper($method);
        $pathMatched = false;
        $allowedMethods = [];

        foreach ($this->routes as $route) {
            if (preg_match($route['regex'], $path)) {
                $allowedMethods[$route['method']] = true;
            }

            if (!preg_match($route['regex'], $path, $matches)) {
                continue;
            }
            $pathMatched = true;

            if ($route['method'] !== $method) {
                continue;
            }

            array_shift($matches);
            $params = $route['params'] === [] ? [] : array_combine($route['params'], $matches);
            ($route['handler'])($params);
            return;
        }

        if ($pathMatched) {
            http_response_code(405);
            header('Allow: ' . implode(', ', array_keys($allowedMethods)));
            echo 'Method Not Allowed';
            return;
        }

        if ($this->notFoundHandler !== null) {
            ($this->notFoundHandler)();
            return;
        }

        http_response_code(404);
        echo 'Not Found';
    }
}
