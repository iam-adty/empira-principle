<?php

declare(strict_types=1);

namespace IamAdty\Empira\Http;

/**
 * An inbound HTTP request with the htmx request headers surfaced.
 */
final class Request
{
    /** @var array<string, string> lower-cased header name => value */
    private array $headers;

    /**
     * @param array<string, string> $headers
     * @param array<string, mixed>  $query
     * @param array<string, mixed>  $body
     * @param array<string, string> $cookies
     * @param array<string, mixed>  $files
     */
    public function __construct(
        public readonly string $method,
        public readonly string $uri,
        public readonly array $query = [],
        public readonly array $body = [],
        array $headers = [],
        public readonly array $cookies = [],
        public readonly array $files = [],
    ) {
        $normalised = [];

        foreach ($headers as $name => $value) {
            $normalised[strtolower((string) $name)] = (string) $value;
        }

        $this->headers = $normalised;
    }

    /**
     * Build from PHP superglobals.
     */
    public static function capture(): self
    {
        $headers = [];

        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $name = strtolower(str_replace('_', '-', substr($key, 5)));
                $headers[$name] = (string) $value;
            }
        }

        foreach (['CONTENT_TYPE' => 'content-type', 'CONTENT_LENGTH' => 'content-length'] as $key => $name) {
            if (isset($_SERVER[$key])) {
                $headers[$name] = (string) $_SERVER[$key];
            }
        }

        $body = $_POST;

        if ($body === [] && str_contains($headers['content-type'] ?? '', 'application/json')) {
            $decoded = json_decode((string) file_get_contents('php://input'), true);
            $body = is_array($decoded) ? $decoded : [];
        }

        return new self(
            method: strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')),
            uri: (string) ($_SERVER['REQUEST_URI'] ?? '/'),
            query: $_GET,
            body: $body,
            headers: $headers,
            cookies: $_COOKIE,
            files: $_FILES,
        );
    }

    public function path(): string
    {
        $path = parse_url($this->uri, PHP_URL_PATH);

        return is_string($path) && $path !== '' ? $path : '/';
    }

    public function fullUrl(): string
    {
        if (preg_match('#^https?://#i', $this->uri) === 1) {
            return $this->uri;
        }

        $host = $this->header('host', 'localhost');

        return 'http://' . $host . $this->uri;
    }

    public function isMethod(string $method): bool
    {
        return strtoupper($this->method) === strtoupper($method);
    }

    /**
     * Body and query together; the body wins where both carry the key.
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return array_merge($this->query, $this->body);
    }

    /**
     * A dotted read across the merged input, so `request->get('user.name')`
     * works for nested form data.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $current = $this->all();

        foreach (explode('.', $key) as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                return $default;
            }

            $current = $current[$segment];
        }

        return $current;
    }

    /**
     * Uploaded files, as `$_FILES`-shaped arrays.
     *
     * @return array<string, mixed>
     */
    public function files(): array
    {
        return $this->files;
    }

    public function hasFile(string $key): bool
    {
        $file = $this->files[$key] ?? null;

        return is_array($file) && ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
    }

    public function header(string $name, ?string $default = null): ?string
    {
        return $this->headers[strtolower($name)] ?? $default;
    }

    /**
     * @return array<string, string>
     */
    public function headers(): array
    {
        return $this->headers;
    }

    /**
     * Read from the body, falling back to the query string.
     */
    public function input(string $key, mixed $default = null): mixed
    {
        return $this->body[$key] ?? $this->query[$key] ?? $default;
    }

    public function string(string $key, string $default = ''): string
    {
        $value = $this->input($key, $default);

        return is_scalar($value) ? (string) $value : $default;
    }

    public function int(string $key, int $default = 0): int
    {
        $value = $this->input($key, $default);

        return is_numeric($value) ? (int) $value : $default;
    }

    public function bool(string $key, bool $default = false): bool
    {
        $value = $this->input($key, $default);

        return is_bool($value) ? $value : filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? $default;
    }

    // ------------------------------------------------------------ htmx request headers

    /** `HX-Request` — the request came from htmx. */
    public function isHtmx(): bool
    {
        return ($this->header('hx-request') ?? '') === 'true';
    }

    /** `HX-Boosted` — the request came from an `hx-boost`ed link or form. */
    public function isBoosted(): bool
    {
        return ($this->header('hx-boosted') ?? '') === 'true';
    }

    /** `HX-History-Restore-Request` — htmx missed its history cache and is refetching. */
    public function isHistoryRestore(): bool
    {
        return ($this->header('hx-history-restore-request') ?? '') === 'true';
    }

    /** `HX-Current-URL` — the browser URL at request time. */
    public function currentUrl(): ?string
    {
        return $this->header('hx-current-url');
    }

    /** `HX-Prompt` — the user's answer to an `hx-prompt`. */
    public function prompt(): ?string
    {
        return $this->header('hx-prompt');
    }

    /** `HX-Target` — the id of the target element. */
    public function target(): ?string
    {
        return $this->header('hx-target');
    }

    /** `HX-Trigger` — the id of the element that fired the request. */
    public function trigger(): ?string
    {
        return $this->header('hx-trigger');
    }

    /** `HX-Trigger-Name` — the `name` of the element that fired the request. */
    public function triggerName(): ?string
    {
        return $this->header('hx-trigger-name');
    }

    /** The CSRF token htmx forwards from the page. */
    public function csrfToken(): ?string
    {
        return $this->header('x-csrf-token') ?? $this->string('_csrf');
    }
}