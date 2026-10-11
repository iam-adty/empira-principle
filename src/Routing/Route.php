<?php

declare(strict_types=1);

namespace IamAdty\Empira\Routing;

use IamAdty\Empira\Attributes\Verb;

/**
 * One route: a method, a path pattern, and whatever handles it.
 *
 * Patterns are written the way you would write them by hand —
 * `/todos/{id}`, `/todos/{id}/comments/{comment?}`, `/files/{name:[^/]+}` —
 * and compiled to a regex once, lazily, the first time the route is matched.
 *
 * Parameter types are taken from the defaults: a default of `3` makes `{id}`
 * arrive as an `int`, a default of `'x'` leaves it a string. That keeps a
 * controller's signature honest without a cast at every use.
 */
final class Route
{
    private ?string $regex = null;

    /** @var list<string> */
    private array $parameters = [];

    /** @var array<string, Verb> */
    public readonly array $verbs;

    /**
     * @param string                  $method     an HTTP method, or `*` for any
     * @param callable|array|string   $handler    a closure, a container class-string,
     *                                            or `[Controller::class, 'method']`
     * @param array<string, mixed>    $defaults   default parameters, and the source of parameter types
     * @param list<class-string|callable> $middleware
     * @param int|null                $priority   orders discovered routes ahead of the specificity sort
     */
    public function __construct(
        public readonly string $method,
        public readonly string $pattern,
        public readonly mixed $handler,
        public readonly ?string $name = null,
        public readonly array $defaults = [],
        public readonly array $middleware = [],
        public readonly ?int $priority = null,
        public readonly bool $csrf = true,
    ) {
        $this->verbs = $method === '*' ? Verb::cases() : [Verb::from(strtoupper($method)) ?? Verb::Get];
    }

    /**
     * Does this route want its state-changing requests checked for CSRF?
     *
     * False is a deliberate, per-route statement that the request does not
     * rely on an ambient credential. It is read by the CSRF middleware itself
     * rather than by a route middleware, because that middleware runs outside
     * this one.
     */
    public function verifiesCsrf(): bool
    {
        return $this->csrf;
    }

    /**
     * How many segments of this pattern are literal text rather than parameters.
     */
    public function literalSegments(): int
    {
        $literals = 0;

        foreach (explode('/', trim($this->pattern, '/')) as $segment) {
            if ($segment !== '' && !str_contains($segment, '{')) {
                $literals++;
            }
        }

        return $literals;
    }

    /**
     * Match a path against the pattern.
     *
     * @return array<string, mixed>|null the parameters, or null when the path does not match
     */
    public function match(string $path): ?array
    {
        if (preg_match($this->regex(), $path, $captured) !== 1) {
            return null;
        }

        $parameters = [];

        foreach ($this->parameters as $name) {
            if (isset($captured[$name]) && $captured[$name] !== '') {
                $parameters[$name] = $captured[$name];
            }
        }

        $parameters = array_merge($this->defaults, $parameters);

        foreach ($this->parameters as $name) {
            if (isset($parameters[$name]) && is_string($parameters[$name])) {
                $parameters[$name] = $this->cast($parameters[$name], $this->defaults[$name] ?? null);
            }
        }

        return $parameters;
    }

    /**
     * Does this route accept the given HTTP method?
     */
    public function accepts(string $method): bool
    {
        return $this->method === '*' || $this->method === strtoupper($method);
    }

    public function regex(): string
    {
        return $this->regex ??= $this->compile();
    }

    /**
     * @return list<string>
     */
    public function parameters(): array
    {
        $this->regex();

        return $this->parameters;
    }

    /**
     * Build the path for this route, filling parameters from the pattern.
     *
     * Parameters with no value and no default are left out, which is only
     * allowed when the segment was written optional (`{id?}`).
     *
     * @param array<string, mixed> $parameters
     */
    public function buildPath(array $parameters): string
    {
        $path = '';

        foreach (explode('/', trim($this->pattern, '/')) as $segment) {
            if (preg_match('/^\{([A-Za-z_][A-Za-z0-9_]*)(\?)?(?::([^}]+))?\}$/', $segment, $m) !== 1) {
                $path .= '/' . $segment;
                continue;
            }

            $name = $m[1];

            if (!array_key_exists($name, $parameters)) {
                if (($m[2] ?? '') === '?') {
                    continue;
                }

                if (!array_key_exists($name, $this->defaults)) {
                    throw new \InvalidArgumentException(sprintf(
                        'Route "%s" needs a value for "%s".',
                        $this->name ?? $this->pattern,
                        $name
                    ));
                }

                $parameters[$name] = $this->defaults[$name];
            }

            $path .= '/' . rawurlencode((string) $parameters[$name]);
        }

        return $path === '' ? '/' : $path;
    }

    /**
     * Turn `/todos/{id}` into a regex, segment by segment.
     *
     * Working per segment rather than per character keeps optional parameters
     * honest: `{id?}` becomes `(?:/(?P<id>[^/]+))?`, so the slash in front of it
     * disappears along with the value.
     */
    private function compile(): string
    {
        $regex = '';

        foreach (explode('/', trim($this->pattern, '/')) as $segment) {
            if (preg_match('/^\{([A-Za-z_][A-Za-z0-9_]*)(\?)?(?::([^}]+))?\}$/', $segment, $m) === 1) {
                $name = $m[1];
                $constraint = ($m[3] ?? '') !== '' ? $m[3] : '[^/]+';
                $group = '(?P<' . $name . '>' . $constraint . ')';

                $this->parameters[] = $name;

                $regex .= ($m[2] ?? '') === '?' ? '(?:/' . $group . ')?' : '/' . $group;

                continue;
            }

            $regex .= '/' . preg_quote($segment, '#');
        }

        return '#^' . ($regex === '' ? '' : $regex) . '/?$#';
    }

    /**
     * Coerce a captured segment to the type of its default.
     */
    private function cast(string $value, mixed $default): mixed
    {
        if (is_int($default)) {
            return (int) $value;
        }

        if (is_float($default)) {
            return (float) $value;
        }

        if (is_bool($default)) {
            return filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? $default;
        }

        return $value;
    }
}