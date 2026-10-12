<?php

declare(strict_types=1);

namespace Empira\Http;

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
        public readonly ?string $remoteAddress = null,
        public readonly bool $trustProxy = false,
    ) {
        $normalised = [];

        foreach ($headers as $name => $value) {
            $normalised[strtolower((string) $name)] = (string) $value;
        }

        $this->headers = $normalised;
    }

    /**
 * Build from PHP superglobals.
 *
 * `$trustProxy` is the deployment's answer to "is anything in front of me",
 * and it belongs here rather than being a parameter of {@see ip()} so that
 * every caller gets the same answer. A method argument would mean remembering
 * it at each call site, and the failure mode of forgetting is a log full of
 * plausible wrong addresses.
 */
public static function capture(bool $trustProxy = false): self
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
            remoteAddress: isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : null,
            trustProxy: $trustProxy,
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
     * Who this request appears to come from, or null when that cannot be said.
     *
     * The socket address by default, and the socket address **only** by default.
     * `X-Forwarded-For` and its relatives are set by whoever sent the request,
     * so trusting them without a proxy in front means anyone can put any
     * address they like into your logs — which defeats the purpose of logging
     * an address at all. Behind a proxy it is the reverse problem: every
     * request arrives from the proxy, and a log full of one repeated address is
     * worse than none because it looks like data.
     *
     * Both are wrong by default and right by configuration, which is why
     * {@see \Empira\Application::trustProxies()} exists.
     *
     * Everything is validated. A malformed header, or a `REMOTE_ADDR` that is a
     * unix socket path rather than an address, returns null instead of
     * something that would be written into a log and believed later.
     */
    public function ip(): ?string
    {
        if ($this->trustProxy) {
            // A forwarded chain is oldest-first, so the client is at the head.
            $chain = $this->header('x-forwarded-for');

            $candidate = is_string($chain) && trim($chain) !== ''
                ? trim(explode(',', $chain)[0])
                : ($this->header('x-real-ip') ?? $this->header('cf-connecting-ip'));

            if (is_string($candidate) && self::isAddress($candidate)) {
                return $candidate;
            }
        }

        return $this->isAddress($this->remoteAddress ?? '') ? $this->remoteAddress : null;
    }

    private static function isAddress(string $value): bool
    {
        return $value !== '' && filter_var($value, FILTER_VALIDATE_IP) !== false;
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

    // ------------------------------------------------------------ content negotiation

    /**
     * Does this client prefer `$type` over the `$betterThan` alternatives?
     *
     * This is a real `Accept` parse, not a substring test. Quality values are
     * honoured, and an explicit match always beats a wildcard — which is the
     * rule that makes `application/json, text/plain, *&#47;*` resolve to JSON
     * even though the wildcard is present.
     *
     * **A missing or empty header prefers nothing**, and a wildcard accepts
     * everything equally. Both resolve to false here, which is deliberate and
     * load-bearing: **htmx never sends an `Accept` header at all** (it signals
     * intent with `xhr.overrideMimeType('text/html')`), so what the server
     * actually receives is the browser's XHR default, `*&#47;*`. Treating that
     * as a vote for JSON would break every fragment swap in every application,
     * so JSON has to be asked for explicitly.
     *
     * @param array<string> $betterThan types that lose to this one
     */
    public function accepts(string $type, array $betterThan = []): bool
    {
        $wantedQuality = $this->preferenceFor($type);

        if ($wantedQuality === null) {
            return false;
        }

        foreach ($betterThan as $alternative) {
            $alternativeQuality = $this->preferenceFor($alternative);

            if ($alternativeQuality !== null && $alternativeQuality > $wantedQuality) {
                return false;
            }
        }

        return $wantedQuality > 0.0;
    }

    /**
     * The best quality this client assigned to `$type`, or null when the client
     * expressed no preference about it at all.
     *
     * A wildcard counts only if no explicit entry named the type — the HTTP
     * precedence rule, and the reason a wildcard cannot masquerade as a
     * preference for JSON over an explicit `text/html`.
     *
     * A **missing or empty header is deliberately null, not 1.0.** A client
     * that says nothing has not expressed a preference, and reading that as
     * "JSON, please" would break every htmx fragment in every application.
     */
    private function preferenceFor(string $type): ?float
    {
        $header = $this->header('accept');

        if ($header === null || trim($header) === '') {
            return null;
        }

        $type = strtolower($type);
        $exact = null;

        foreach (explode(',', $header) as $part) {
            $segments = explode(';', $part);
            $name = strtolower(trim($segments[0]));

            if ($name === '') {
                continue;
            }

            $quality = 1.0;

            foreach (array_slice($segments, 1) as $parameter) {
                $parameter = trim($parameter);

                if (stripos($parameter, 'q=') === 0) {
                    $quality = (float) substr($parameter, 2);
                }
            }

            if ($name === $type) {
                $exact = max($exact ?? 0.0, $quality);
            }
        }

        // A wildcard is deliberately ignored. `*&#47;*` is what htmx requests
        // arrive with, and it carries no preference at all — treating it as one
        // would hand every fragment swap a JSON body. A client that wants JSON
        // has to name it.
        return $exact;
    }
}