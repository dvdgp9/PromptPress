<?php

namespace Core;

/**
 * Router minimalista.
 * Soporta:
 *   $router->get('/pages/{id}', [Controller::class, 'show']);
 *   $router->post('/api/x', $closure);
 *   $router->group('/admin', fn($r) => { $r->get(...); }, [AuthMiddleware]);
 */
final class Router
{
    /** @var array<int, array{method:string, regex:string, params:array, handler:mixed, middlewares:array}> */
    private array $routes = [];

    /** @var string current group prefix */
    private string $groupPrefix = '';

    /** @var array current group middlewares (callables) */
    private array $groupMiddlewares = [];

    public function get(string $path, mixed $handler, array $middlewares = []): void    { $this->add('GET',    $path, $handler, $middlewares); }
    public function post(string $path, mixed $handler, array $middlewares = []): void   { $this->add('POST',   $path, $handler, $middlewares); }
    public function put(string $path, mixed $handler, array $middlewares = []): void    { $this->add('PUT',    $path, $handler, $middlewares); }
    public function patch(string $path, mixed $handler, array $middlewares = []): void  { $this->add('PATCH',  $path, $handler, $middlewares); }
    public function delete(string $path, mixed $handler, array $middlewares = []): void { $this->add('DELETE', $path, $handler, $middlewares); }
    public function options(string $path, mixed $handler, array $middlewares = []): void { $this->add('OPTIONS', $path, $handler, $middlewares); }

    public function group(string $prefix, callable $callback, array $middlewares = []): void
    {
        $previousPrefix      = $this->groupPrefix;
        $previousMiddlewares = $this->groupMiddlewares;
        $this->groupPrefix       = $previousPrefix . '/' . trim($prefix, '/');
        $this->groupMiddlewares  = array_merge($previousMiddlewares, $middlewares);
        $callback($this);
        $this->groupPrefix       = $previousPrefix;
        $this->groupMiddlewares  = $previousMiddlewares;
    }

    private function add(string $method, string $path, mixed $handler, array $middlewares): void
    {
        $fullPath = '/' . trim($this->groupPrefix . '/' . trim($path, '/'), '/');
        if ($fullPath === '/') {
            $fullPath = '/';
        }
        // Convertir {param} y {param:path} a regex named group.
        //   {name}        → un solo segmento  ([^/]+)
        //   {name:path}   → multi-segmento    (.+)   — útil para slugs anidados (T7.4)
        $params = [];
        $regex = preg_replace_callback(
            '#\{([a-zA-Z_][a-zA-Z0-9_]*)(?::(path))?\}#',
            function ($m) use (&$params) {
                $params[] = $m[1];
                $body = (($m[2] ?? '') === 'path') ? '.+' : '[^/]+';
                return '(?P<' . $m[1] . '>' . $body . ')';
            },
            $fullPath
        );
        $regex = '#^' . $regex . '$#';

        $this->routes[] = [
            'method'      => $method,
            'regex'       => $regex,
            'params'      => $params,
            'handler'     => $handler,
            'middlewares' => array_merge($this->groupMiddlewares, $middlewares),
        ];
    }

    public function dispatch(string $method, string $path): void
    {
        // Normalize: strip trailing slash (except root /)
        if ($path !== '/' && str_ends_with($path, '/')) {
            $path = rtrim($path, '/');
        }
        $methodAllowed = false;
        foreach ($this->routes as $route) {
            if (!preg_match($route['regex'], $path, $matches)) {
                continue;
            }
            if ($route['method'] !== $method) {
                $methodAllowed = true;
                continue;
            }
            // Extraer params
            $params = [];
            foreach ($route['params'] as $name) {
                $params[$name] = $matches[$name] ?? null;
            }
            // Ejecutar middlewares
            foreach ($route['middlewares'] as $mw) {
                $result = is_callable($mw) ? $mw() : null;
                if ($result === false) {
                    return; // middleware ya respondió
                }
            }
            $this->invoke($route['handler'], $params);
            return;
        }

        if ($methodAllowed) {
            http_response_code(405);
            header('Content-Type: text/html; charset=UTF-8');
            echo '<h1>405 — Método no permitido</h1>';
            return;
        }
        Response::notFound();
    }

    private function invoke(mixed $handler, array $params): void
    {
        if (is_callable($handler)) {
            $handler($params);
            return;
        }
        if (is_array($handler) && count($handler) === 2) {
            [$class, $method] = $handler;
            if (is_string($class)) {
                $instance = new $class();
                $instance->$method($params);
                return;
            }
        }
        throw new \RuntimeException('Invalid route handler');
    }
}
