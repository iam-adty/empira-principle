<?php

declare(strict_types=1);

namespace Empira\Routing;

/**
 * A route that matched, plus the parameters it captured.
 */
final class RouteMatch
{
    /**
     * @param array<string, mixed> $parameters
     */
    public function __construct(
        public readonly Route $route,
        public readonly array $parameters,
    ) {
    }

    public function name(): ?string
    {
        return $this->route->name;
    }

    /**
     * The handler, with any string handler resolved by the container.
     */
    public function handler(): mixed
    {
        return $this->route->handler;
    }
}