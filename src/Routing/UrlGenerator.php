<?php

declare(strict_types=1);

namespace IamAdty\Empira\Routing;

/**
 * Builds URLs from route names.
 *
 * Reversing routes by name rather than by hand keeps a URL from being wrong
 * after a pattern changes, and keeps parameters out of string concatenation:
 *
 *     $url->to('todos.show', ['id' => 7]);   // '/todos/7'
 *     $url->to('todos.index', ['q' => 'hi']); // '/todos?q=hi'
 *
 * Parameters that are not in the pattern become the query string, which is what
 * makes building a filtered list a one-liner.
 */
final class UrlGenerator
{
    public function __construct(
        private readonly RouteCollection $routes,
        private readonly string $baseUrl = '',
    ) {
    }

    public function is(string $name): bool
    {
        return $this->routes->has($name);
    }

    /**
     * Build the URL for a named route.
     *
     * @param array<string, mixed> $parameters
     *
     * @throws \InvalidArgumentException when the name is unknown or a parameter is missing
     */
    public function to(string $name, array $parameters = []): string
    {
        $route = $this->routes->route($name);
        $path = $route->buildPath($parameters);

        foreach ($route->parameters() as $parameter) {
            unset($parameters[$parameter]);
        }

        $query = $parameters === [] ? '' : '?' . http_build_query($parameters);

        return $this->baseUrl . $path . $query;
    }
}