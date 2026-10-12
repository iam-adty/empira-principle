<?php

declare(strict_types=1);

namespace Empira\Routing;

/**
 * The parameters a route matched, for the request in flight.
 *
 * A handler can ask for this by type and the container supplies it, which is
 * how one handler shape covers routes with different parameters:
 *
 *     static fn(Request $request, RouteParams $params) => $app->view(Page::class, $params->all());
 *
 * {@see Application::view()} merges these beneath any explicit data, and calls
 * a layout's `mount()` with them by name — so a signature typed `int $id` gets
 * what the pattern captured, without a `defaults` workaround.
 */
final class RouteParams
{
    /**
     * @param array<string, mixed> $parameters
     */
    public function __construct(private readonly array $parameters = [])
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->parameters;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->parameters[$key] ?? $default;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->parameters);
    }

    public function int(string $key, int $default = 0): int
    {
        $value = $this->parameters[$key] ?? $default;

        return is_numeric($value) ? (int) $value : $default;
    }

    public function string(string $key, string $default = ''): string
    {
        $value = $this->parameters[$key] ?? $default;

        return is_scalar($value) ? (string) $value : $default;
    }

    /**
     * Merged beneath `$data`, so explicit values win over path parameters.
     *
     * @param  array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function merge(array $data): array
    {
        return array_merge($this->parameters, $data);
    }
}