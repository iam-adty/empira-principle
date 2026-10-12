<?php

declare(strict_types=1);

namespace Empira\Http;

use Closure;
use Empira\Container\Container;

/**
 * Runs a request through a stack of middleware.
 *
 * Middleware is onion-shaped: each one runs before the handler and again after
 * it on the way back out, which is what lets a layer measure how long a request
 * took or add a header to the response it did not produce itself.
 *
 * Entries may be a class-string (resolved from the container, so dependencies
 * are autowired) or an already-built instance.
 */
final class Pipeline
{
    /**
     * @param list<class-string<Middleware>|Middleware> $middleware
     */
    public function __construct(
        private readonly Container $container,
        private array $middleware = [],
    ) {
    }

    /**
     * @param list<class-string<Middleware>|Middleware> ...$layers
     */
    public function push(string|Middleware ...$layers): self
    {
        foreach ($layers as $layer) {
            $this->middleware[] = $layer;
        }

        return $this;
    }

    /**
     * Send the request to `$destination` through every layer.
     *
     * @param Closure(Request): Response $destination
     */
    public function send(Request $request, Closure $destination): Response
    {
        $next = $destination;

        // Walk backwards, wrapping each layer around the previous, so the
        // first-registered middleware ends up outermost.
        foreach (array_reverse($this->resolve()) as $middleware) {
            $current = $next;

            $next = static fn(Request $request): Response => $middleware->handle($request, $current);
        }

        return $next($request);
    }

    /**
     * @return list<Middleware>
     */
    private function resolve(): array
    {
        $resolved = [];

        foreach ($this->middleware as $middleware) {
            $resolved[] = $middleware instanceof Middleware
                ? $middleware
                : $this->container->get($middleware);
        }

        return $resolved;
    }
}