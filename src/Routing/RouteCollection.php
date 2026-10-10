<?php

declare(strict_types=1);

namespace IamAdty\Empira\Routing;

/**
 * The application's route table.
 *
 * Routes are matched in the order they were declared, so a specific pattern
 * declared before a general one wins — put `/todos/new` above `/todos/{id}`
 * and it just works, the way you would expect from a hand-written front
 * controller.
 *
 *     $routes->get('/todos/{id}', [TodoController::class, 'show'], 'todos.show');
 *
 * Groups share a prefix, parameter defaults and middleware:
 *
 *     $routes->group('/todos/{id}', function ($r) {
 *         $r->get('', [TodoController::class, 'show']);
 *         $r->post('/comments', [CommentController::class, 'store']);
 *     }, defaults: ['id' => 0]);
 */
final class RouteCollection
{
    /** @var list<Route> */
    private array $routes = [];

    /** @var array<string, Route> */
    private array $named = [];

    private string $prefix = '';

    /** @var array<string, mixed> */
    private array $groupDefaults = [];

    /** @var list<class-string|callable> */
    private array $groupMiddleware = [];

    public function get(string $pattern, mixed $handler, ?string $name = null, array $defaults = []): Route
    {
        return $this->add('GET', $pattern, $handler, $name, $defaults);
    }

    public function post(string $pattern, mixed $handler, ?string $name = null, array $defaults = []): Route
    {
        return $this->add('POST', $pattern, $handler, $name, $defaults);
    }

    public function put(string $pattern, mixed $handler, ?string $name = null, array $defaults = []): Route
    {
        return $this->add('PUT', $pattern, $handler, $name, $defaults);
    }

    public function patch(string $pattern, mixed $handler, ?string $name = null, array $defaults = []): Route
    {
        return $this->add('PATCH', $pattern, $handler, $name, $defaults);
    }

    public function delete(string $pattern, mixed $handler, ?string $name = null, array $defaults = []): Route
    {
        return $this->add('DELETE', $pattern, $handler, $name, $defaults);
    }

    /**
     * Register a route for every HTTP method.
     *
     * @param array<string, mixed> $defaults
     */
    public function any(string $pattern, mixed $handler, ?string $name = null, array $defaults = []): Route
    {
        return $this->add('*', $pattern, $handler, $name, $defaults);
    }

    /**
     * Register a route for an explicit method (or `*` for any).
     *
     * @param array<string, mixed> $defaults
     */
    public function add(string $method, string $pattern, mixed $handler, ?string $name = null, array $defaults = []): Route
    {
        $route = new Route(
            method: strtoupper($method),
            pattern: $this->prefix . '/' . ltrim($pattern, '/'),
            handler: $handler,
            name: $name,
            defaults: array_merge($this->groupDefaults, $defaults),
            middleware: $this->groupMiddleware,
        );

        if ($name !== null) {
            if (isset($this->named[$name])) {
                throw new \InvalidArgumentException(sprintf('Route name "%s" is already used.', $name));
            }

            $this->named[$name] = $route;
        }

        $this->routes[] = $route;

        return $route;
    }

    /**
     * Run a callback with a shared prefix, defaults and middleware in effect.
     *
     * The callback receives the collection and any extra defaults, so it can
     * pass them on: `$routes->group('/api', function ($r, $defaults) { ... },
     * defaults: ['version' => 'v1'])`.
     *
     * @param array<string, mixed>        $defaults
     * @param list<class-string|callable> $middleware
     */
    public function group(string $prefix, callable $routes, array $defaults = [], array $middleware = []): self
    {
        $previous = [$this->prefix, $this->groupDefaults, $this->groupMiddleware];

        $this->prefix = rtrim($this->prefix . '/' . trim($prefix, '/'), '/');
        $this->groupDefaults = array_merge($this->groupDefaults, $defaults);
        $this->groupMiddleware = array_merge($this->groupMiddleware, $middleware);

        try {
            $routes($this, $defaults);
        } finally {
            [$this->prefix, $this->groupDefaults, $this->groupMiddleware] = $previous;
        }

        return $this;
    }

    /**
     * Middleware applied to every route declared until the group closes.
     *
     * @param class-string|callable ...$middleware
     */
    public function middleware(string ...$middleware): self
    {
        $this->groupMiddleware = array_merge($this->groupMiddleware, $middleware);

        return $this;
    }

    /**
     * Match a method and path.
     *
     * Returns null when nothing matched. When a path matches but the method
     * does not, {@see allowedMethods()} will tell you what was expected.
     */
    public function match(string $method, string $path): ?RouteMatch
    {
        foreach ($this->routes as $route) {
            if (!$route->accepts($method)) {
                continue;
            }

            $parameters = $route->match($path);

            if ($parameters !== null) {
                return new RouteMatch($route, $parameters);
            }
        }

        return null;
    }

    /**
     * Every method that would match this path, for a 405 `Allow` header.
     *
     * @return list<string>
     */
    public function allowedMethods(string $path): array
    {
        $allowed = [];

        foreach ($this->routes as $route) {
            if ($route->match($path) === null) {
                continue;
            }

            foreach ($route->verbs as $verb) {
                $allowed[] = $verb->value;
            }
        }

        return array_values(array_unique($allowed));
    }

    public function has(string $name): bool
    {
        return isset($this->named[$name]);
    }

    /**
     * @throws \InvalidArgumentException when no route carries that name
     */
    public function route(string $name): Route
    {
        return $this->named[$name] ?? throw new \InvalidArgumentException(sprintf('No route named "%s".', $name));
    }

    /**
     * @return list<Route>
     */
    public function all(): array
    {
        return $this->routes;
    }
}