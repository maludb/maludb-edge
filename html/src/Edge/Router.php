<?php
declare(strict_types=1);

namespace MaluDbEdge;

final class Router
{
    private array $routes = [];

    public function get(string $path, callable $handler): void
    {
        $this->add('GET', $path, $handler);
    }

    public function post(string $path, callable $handler): void
    {
        $this->add('POST', $path, $handler);
    }

    public function patch(string $path, callable $handler): void
    {
        $this->add('PATCH', $path, $handler);
    }

    public function delete(string $path, callable $handler): void
    {
        $this->add('DELETE', $path, $handler);
    }

    private function add(string $method, string $path, callable $handler): void
    {
        $pattern = '';
        $offset = 0;
        $routeParams = [];
        preg_match_all('#\{([a-zA-Z_][a-zA-Z0-9_]*)\}#', $path, $matches, PREG_OFFSET_CAPTURE);
        foreach ($matches[0] as $index => [$placeholder, $position]) {
            $name = $matches[1][$index][0];
            if (isset($routeParams[$name])) {
                throw new \InvalidArgumentException('Duplicate route parameter: ' . $name);
            }
            $routeParams[$name] = true;

            $pattern .= preg_quote(substr($path, $offset, $position - $offset), '#');
            $pattern .= '(?P<' . $name . '>[^/]+)';
            $offset = $position + strlen($placeholder);
        }
        $pattern .= preg_quote(substr($path, $offset), '#');
        $this->routes[] = [$method, '#^' . $pattern . '$#', $handler];
    }

    public function dispatch(Request $request): Response
    {
        foreach ($this->routes as [$method, $pattern, $handler]) {
            if ($method !== $request->method) {
                continue;
            }
            if (preg_match($pattern, $request->path, $matches)) {
                $params = [];
                foreach ($matches as $key => $value) {
                    if (is_string($key)) {
                        $params[$key] = $value;
                    }
                }
                return $handler($request->withParams($params));
            }
        }
        return Response::error('not_found', 'Route not found', 404);
    }
}
