<?php

declare(strict_types=1);

namespace IamAdty\Empira\Config;

/**
 * Read-only configuration with dot-notation access.
 *
 * Values are addressed as `app.secret` rather than nested array walks, and the
 * typed getters coerce where it is unambiguous — config files are full of
 * stringly-typed data, so `config->int('db.port', 3306)` should not need a
 * cast at every call site.
 *
 * The object is immutable: `with()` returns a new instance, so a config can be
 * safely captured by a component and extended per request.
 *
 *     $config = Config::fromEnvFile($basePath . '/.env');
 *
 *     $config->get('app.debug', false);   // mixed, raw
 *     $config->bool('app.debug');          // coerced
 *     $config->string('app.url', '/');     // coerced
 */
final class Config
{
    /**
     * @param array<string, mixed> $items
     */
    public function __construct(private readonly array $items = [])
    {
    }

    /**
     * Build config from an env file's raw key/value pairs.
     *
     * Keys containing dots are expanded into nested arrays, so `db.host` in the
     * file becomes the `db` → `host` path.
     */
    public static function fromEnv(array $env): self
    {
        $items = [];

        foreach ($env as $key => $value) {
            self::write($items, explode('.', (string) $key), $value);
        }

        return new self($items);
    }

    /**
     * @return array<string, string>
     */
    public static function env(string $path): array
    {
        return Env::fromFile($path);
    }

    public static function fromEnvFile(string $path): self
    {
        return self::fromEnv(self::env($path));
    }

    /**
     * @param list<string> $segments
     * @param mixed        $value
     */
    private static function write(array &$items, array $segments, mixed $value): void
    {
        $segment = array_shift($segments);

        if ($segment === null) {
            return;
        }

        if ($segments === []) {
            $items[$segment] = $value;

            return;
        }

        if (!isset($items[$segment]) || !is_array($items[$segment])) {
            $items[$segment] = [];
        }

        self::write($items[$segment], $segments, $value);
    }

    public function has(string $key): bool
    {
        return $this->get($key, $this) !== $this;
    }

    /**
     * Fetch a value by dot-notation path, or `$default` when it is absent.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $current = $this->items;

        foreach (explode('.', $key) as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                return $default;
            }

            $current = $current[$segment];
        }

        return $current;
    }

    public function string(string $key, string $default = ''): string
    {
        $value = $this->get($key);

        return is_scalar($value) ? (string) $value : $default;
    }

    public function int(string $key, int $default = 0): int
    {
        $value = $this->get($key);

        return is_numeric($value) ? (int) $value : $default;
    }

    public function float(string $key, float $default = 0.0): float
    {
        $value = $this->get($key);

        return is_numeric($value) ? (float) $value : $default;
    }

    public function bool(string $key, bool $default = false): bool
    {
        $value = $this->get($key);

        if (is_bool($value)) {
            return $value;
        }

        return is_scalar($value)
            ? (filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? $default)
            : $default;
    }

    /**
     * @return array<mixed>
     */
    public function array(string $key, array $default = []): array
    {
        $value = $this->get($key);

        return is_array($value) ? $value : $default;
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->items;
    }

    /**
     * Return a copy with one value replaced or added.
     */
    public function with(string $key, mixed $value): self
    {
        $items = $this->items;

        self::write($items, explode('.', $key), $value);

        return new self($items);
    }
}