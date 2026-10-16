<?php

declare(strict_types=1);

namespace Empira\Http;

use Closure;
use Empira\Container\Container;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Runs a request through a stack of middleware.
 *
 * Middleware is onion-shaped: each one runs before the handler and again after
 * it on the way back out, which is what lets a layer measure how long a request
 * took or add a header to the response it did not produce itself.
 *
 * Entries may be a class-string (resolved from the container, so dependencies
 * are autowired) or an already-built instance.
 *
 * ## The destination is a closure, the layers are PSR-15
 *
 * {@see send()} keeps accepting `Closure(Request): Response`, because that is how
 * every route handler in every application here is written, and it wraps the
 * destination in a {@see ClosureHandler} on the way in. Nothing about a route has
 * to change to be driven by PSR-15 middleware.
 */
final class Pipeline
{
    /**
     * @param list<class-string<MiddlewareInterface>|MiddlewareInterface> $middleware
     */
    public function __construct(
        private readonly Container $container,
        private array $middleware = [],
    ) {
    }

    /**
     * @param list<class-string<MiddlewareInterface>|MiddlewareInterface> ...$layers
     */
    public function push(string|MiddlewareInterface ...$layers): self
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
        $next = new ClosureHandler($destination);

        // Walk backwards, wrapping each layer around the previous, so the
        // first-registered middleware ends up outermost.
        foreach (array_reverse($this->resolve()) as $middleware) {
            $current = $next;

            $next = new class($middleware, $current) implements RequestHandlerInterface {
                public function __construct(
                    private readonly MiddlewareInterface $middleware,
                    private readonly RequestHandlerInterface $next,
                ) {
                }

                public function handle(ServerRequestInterface $request): ResponseInterface
                {
                    return $this->middleware->process($request, $this->next);
                }
            };
        }

        return $next->handle($request);
    }

    /**
     * @return list<MiddlewareInterface>
     */
    private function resolve(): array
    {
        $resolved = [];

        foreach ($this->middleware as $middleware) {
            $resolved[] = $middleware instanceof MiddlewareInterface
                ? $middleware
                : $this->container->get($middleware);
        }

        return $resolved;
    }
}