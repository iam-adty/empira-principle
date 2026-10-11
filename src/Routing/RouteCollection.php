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

    /** @var list<Route> routes found by discovery, not yet committed in order */
    private array $discovered = [];

    private string $prefix = '';

    /** @var array<string, mixed> */
    private array $groupDefaults = [];

    /** @var list<class-string|callable> */
    private array $groupMiddleware = [];

    public function get(string $pattern, mixed $handler, ?string $name = null, array $defaults = [], bool $csrf = true): Route
    {
        return $this->add('GET', $pattern, $handler, $name, $defaults, csrf: $csrf);
    }

    public function post(string $pattern, mixed $handler, ?string $name = null, array $defaults = [], bool $csrf = true): Route
    {
        return $this->add('POST', $pattern, $handler, $name, $defaults, csrf: $csrf);
    }

    public function put(string $pattern, mixed $handler, ?string $name = null, array $defaults = [], bool $csrf = true): Route
    {
        return $this->add('PUT', $pattern, $handler, $name, $defaults, csrf: $csrf);
    }

    public function patch(string $pattern, mixed $handler, ?string $name = null, array $defaults = [], bool $csrf = true): Route
    {
        return $this->add('PATCH', $pattern, $handler, $name, $defaults, csrf: $csrf);
    }

    public function delete(string $pattern, mixed $handler, ?string $name = null, array $defaults = [], bool $csrf = true): Route
    {
        return $this->add('DELETE', $pattern, $handler, $name, $defaults, csrf: $csrf);
    }

    /**
     * Register a route for every HTTP method.
     *
     * @param array<string, mixed> $defaults
     */
    public function any(string $pattern, mixed $handler, ?string $name = null, array $defaults = [], bool $csrf = true): Route
    {
        return $this->add('*', $pattern, $handler, $name, $defaults, csrf: $csrf);
    }

    /**
     * Register a route for an explicit method (or `*` for any).
     *
     * @param array<string, mixed> $defaults
     */
    public function add(
        string $method,
        string $pattern,
        mixed $handler,
        ?string $name = null,
        array $defaults = [],
        bool $csrf = true,
    ): Route {
        return $this->register(new Route(
            method: strtoupper($method),
            pattern: $this->prefix . '/' . ltrim($pattern, '/'),
            handler: $handler,
            name: $name,
            defaults: array_merge($this->groupDefaults, $defaults),
            middleware: $this->groupMiddleware,
            csrf: $csrf,
        ));
    }

    /**
     * Add a route that came from a {@see \IamAdty\Empira\Attributes\Route}.
     *
     * Discovered routes carry a specificity order rather than a declaration
     * order, because a filesystem scan gives no meaningful sequence.
     *
     * @param class-string         $class
     * @param class-string         $derivedName the name to use when the attribute omits one
     */
    public function addDiscovered(
        \IamAdty\Empira\Attributes\Route $attribute,
        string $class,
        ?string $method,
        string $derivedName,
    ): void {
        $handler = $method === null ? $class : [$class, $method];

        $verbs = $attribute->verbs();

        // `methods: ['GET', 'POST']` is one declaration that answers two
        // verbs, so it becomes two routes sharing one name.
        foreach ($verbs === [] ? ['GET'] : $verbs as $verb) {
            $this->discovered[] = new Route(
                method: $verb,
                pattern: $attribute->pattern,
                handler: $handler,
                name: $attribute->name ?? $derivedName,
                middleware: $attribute->middleware,
                priority: $attribute->priority,
                csrf: $attribute->csrf,
            );
        }
    }

    /**
     * Move discovered routes into the table, in specificity order.
     *
     * Called once at the end of discovery rather than per route, so a whole
     * directory is ordered against itself instead of arriving interleaved with
     * whatever was already registered.
     */
    public function commitDiscovered(): void
    {
        if ($this->discovered === []) {
            return;
        }

        $sorted = $this->discovered;

        usort($sorted, self::compareSpecificity(...));

        foreach ($sorted as $route) {
            $this->register($route);
        }

        $this->discovered = [];
    }

    /**
     * Order routes so a specific path always beats a general one.
     *
     * `/todos/new` must be matched before `/todos/{id}` or the dynamic route
     * swallows it. That used to depend on declaration order, which is fine for
     * a hand-written table and useless for a directory scan — a file rename
     * would silently produce a 404.
     *
     * The comparator is deliberately narrow:
     *
     * 1. explicit `priority:` descending
     * 2. **fewest `{param}` segments first**
     * 3. most literal segments first
     * 4. longer pattern first
     * 5. otherwise equal — leave alone
     *
     * Step 5 is what keeps this safe to switch on: two static routes of equal
     * length still match in the order they were written, so an existing
     * hand-written route table behaves exactly as it did before.
     */
    public static function compareSpecificity(Route $a, Route $b): int
    {
        // 1. an explicit priority always wins.
        if (($a->priority ?? 0) !== ($b->priority ?? 0)) {
            return ($b->priority ?? 0) <=> ($a->priority ?? 0);
        }

        $paramsA = count($a->parameters());
        $paramsB = count($b->parameters());

        // 2. fewer parameters is more specific.
        if ($paramsA !== $paramsB) {
            return $paramsA <=> $paramsB;
        }

        $literalA = $a->literalSegments();
        $literalB = $b->literalSegments();

        // 3. more literal segments is more specific.
        if ($literalA !== $literalB) {
            return $literalB <=> $literalA;
        }

        // 4. a longer pattern is more specific.
        $lengthA = strlen($a->pattern);
        $lengthB = strlen($b->pattern);

        // 5. genuinely equal — say so, or usort is free to reorder.
        return $lengthB <=> $lengthA;
    }

    /**
     * Insert a route, honouring name uniqueness.
     */
    private function register(Route $route): Route
    {
        $name = $route->name;

        if ($name !== null) {
            if (isset($this->named[$name])) {
                // One URL can answer several verbs — `methods: ['GET', 'POST']`
                // is a single declaration that becomes one route per verb, and
                // they should all be reachable by that name. Two routes for the
                // *same* verb is still a genuine collision.
                if ($this->named[$name]->method === $route->method) {
                    throw new \InvalidArgumentException(sprintf('Route name "%s" is already used.', $name));
                }
            } else {
                $this->named[$name] = $route;
            }
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