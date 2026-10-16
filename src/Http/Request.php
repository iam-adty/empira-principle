<?php

declare(strict_types=1);

namespace Empira\Http;

use Empira\Html;
use Nyholm\Psr7\Stream;
use Nyholm\Psr7\UploadedFile;
use Nyholm\Psr7\Uri;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Http\Message\UriInterface;

/**
 * An inbound HTTP request with the htmx request headers surfaced.
 *
 * This is a PSR-7 `ServerRequestInterface`, so any PSR-7 or PSR-15 consumer can
 * be handed one directly. The htmx conveniences below are additions: PSR-7 knows
 * nothing about `HX-Request`, and that is the point of this package rather than
 * of the standard.
 *
 * ## The constructor properties are no longer `readonly`
 *
 * They were. PSR-7 requires `withMethod()`, `withUri()`, `withQueryParams()` and
 * the rest to return a **modified copy**, and PHP will not let a `readonly`
 * property be assigned on a clone — there is no `clone with`. So the language
 * no longer enforces the immutability here; the `with*` methods do, by cloning
 * rather than assigning.
 *
 * That is a real loss and it is stated here rather than buried: anything holding
 * a request can now assign `$request->method`. The alternative was rebuilding
 * each `with*` by re-running the nine-argument constructor, which puts the
 * maintenance burden of "a new field was added" on fifteen methods instead of
 * one. The clone is the smaller ongoing risk.
 */
final class Request implements ServerRequestInterface
{
    /** @var array<string, string> lower-cased header name => value */
    private array $headers;

    /** @var array<string, mixed> PSR-7 request attributes */
    private array $attributes = [];

    /** @var UploadedFileInterface[]|null built on first ask, never before */
    private ?array $uploadedFiles = null;

    private ?UriInterface $uriObject = null;

    private ?StreamInterface $bodyStream = null;

    private string $protocolVersion = '1.1';

    private ?string $requestTarget = null;

    /** @var array<string, mixed> */
    private array $serverParams;

    /**
     * @param array<string, string> $headers
     * @param array<string, mixed>  $query
     * @param array<string, mixed>  $body
     * @param array<string, string> $cookies
     * @param array<string, mixed>  $files
     * @param array<string, mixed>  $serverParams
     */
    public function __construct(
        public string $method,
        public string $uri,
        public array $query = [],
        public array $body = [],
        array $headers = [],
        public array $cookies = [],
        public array $files = [],
        public ?string $remoteAddress = null,
        public bool $trustProxy = false,
        array $serverParams = [],
    ) {
        $normalised = [];

        foreach ($headers as $name => $value) {
            $normalised[strtolower((string) $name)] = (string) $value;
        }

        $this->headers = $normalised;
        $this->serverParams = $serverParams;
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

    // ------------------------------------------------------------------- PSR-7

    /**
     * Narrow a PSR request back to this framework's own.
     *
     * PSR-15 types `process()`'s parameter as `ServerRequestInterface`, and PHP
     * forbids narrowing a parameter — so the htmx conveniences (`isHtmx()`,
     * `input()`, `accepts()`) are out of scope inside a middleware. This gets
     * them back, in one line, for the layers that want them.
     *
     *     public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
     *     {
     *         $empira = Request::of($request);
     *
     *         return $empira->isHtmx() ? $handler->handle($request) : Response::html('…');
     *     }
     *
     * It **throws** rather than returning null. A middleware that quietly
     * degraded to the interface's methods would still work for everything except
     * the htmx case — which is exactly the case nobody would notice until a swap
     * stopped being one.
     */
    public static function of(ServerRequestInterface $request): self
    {
        if (!$request instanceof self) {
            throw new \InvalidArgumentException(sprintf(
                'Expected %s, got %s.',
                self::class,
                $request::class,
            ));
        }

        return $request;
    }

    public function getRequestTarget(): string
    {
        // PSR asks for the target in origin-form — path plus query — and a
        // request built from an absolute URL still has one. Falling back to the
        // path alone is what an origin-form request would return anyway.
        if ($this->requestTarget !== null) {
            return $this->requestTarget;
        }

        $query = $this->getUri()->getQuery();

        return $this->path() . ($query === '' ? '' : '?' . $query);
    }

    public function withRequestTarget(string $requestTarget): static
    {
        $new = clone $this;
        $new->requestTarget = $requestTarget;

        return $new;
    }

    public function getMethod(): string
    {
        return strtoupper($this->method);
    }

    public function withMethod(string $method): static
    {
        $new = clone $this;
        $new->method = strtoupper($method);

        return $new;
    }

    public function getUri(): UriInterface
    {
        // A bare path is a valid relative URI and PSR has no problem with one,
        // but `fullUrl()` is what this framework has always handed callers, and a
        // scheme of `http` with a host is what a consumer building an outgoing
        // request needs.
        return $this->uriObject ??= new Uri($this->fullUrl());
    }

    public function withUri(UriInterface $uri, bool $preserveHost = false): static
    {
        $new = clone $this;
        $new->uri = (string) $uri;
        $new->uriObject = $preserveHost ? $uri : null;

        return $new;
    }

    public function getProtocolVersion(): string
    {
        return $this->protocolVersion;
    }

    public function withProtocolVersion(string $version): static
    {
        $new = clone $this;
        $new->protocolVersion = $version;

        return $new;
    }

    /**
     * @return array<string, string[]>
     */
    public function getHeaders(): array
    {
        $headers = [];

        foreach ($this->headers as $name => $value) {
            $headers[$name] = [$value];
        }

        return $headers;
    }

    public function hasHeader(string $name): bool
    {
        return isset($this->headers[strtolower($name)]);
    }

    /**
     * @return string[]
     */
    public function getHeader(string $name): array
    {
        $value = $this->headers[strtolower($name)] ?? null;

        return $value === null ? [] : [$value];
    }

    public function getHeaderLine(string $name): string
    {
        return (string) ($this->headers[strtolower($name)] ?? '');
    }

    public function withHeader(string $name, $value): static
    {
        $new = clone $this;
        $new->headers[strtolower($name)] = (string) $value;

        return $new;
    }

    public function withAddedHeader(string $name, $value): static
    {
        $new = clone $this;

        // A request header carries one value here; the existing one is replaced
        // rather than appended, because a comma-joined duplicate is not what any
        // of the headers this framework reads expects to find.
        $new->headers[strtolower($name)] = (string) $value;

        return $new;
    }

    public function withoutHeader(string $name): static
    {
        $new = clone $this;
        unset($new->headers[strtolower($name)]);

        return $new;
    }

    /**
     * @return array<string, mixed>
     */
    public function getServerParams(): array
    {
        return $this->serverParams;
    }

    /**
     * @return array<string, string>
     */
    public function getCookieParams(): array
    {
        return $this->cookies;
    }

    public function withCookieParams(array $cookies): static
    {
        $new = clone $this;
        $new->cookies = $cookies;

        return $new;
    }

    /**
     * @return array<string, mixed>
     */
    public function getQueryParams(): array
    {
        return $this->query;
    }

    public function withQueryParams(array $query): static
    {
        $new = clone $this;
        $new->query = $query;

        return $new;
    }

    /**
     * Uploads as PSR objects, built from the `$_FILES`-shaped array on first ask.
     *
     * Both shapes exist and this is the boundary between them: {@see files()}
     * hands back what PHP produced, unchanged, and this is what a PSR consumer
     * gets. Building them lazily matters — constructing an `UploadedFile` opens
     * a stream, and a request with fifty uploads should not open fifty handles
     * for a consumer that never looks.
     *
     * @return array<string, UploadedFileInterface|array>
     */
    public function getUploadedFiles(): array
    {
        if ($this->uploadedFiles !== null) {
            return $this->uploadedFiles;
        }

        $files = [];

        foreach ($this->files as $key => $file) {
            $files[$key] = is_array($file) && isset($file['tmp_name'])
                ? new UploadedFile(
                    // Argument order is (stream, size, error, filename, type).
                    (string) $file['tmp_name'],
                    isset($file['size']) ? (int) $file['size'] : null,
                    isset($file['error']) ? (int) $file['error'] : UPLOAD_ERR_OK,
                    isset($file['name']) ? (string) $file['name'] : null,
                    isset($file['type']) ? (string) $file['type'] : null,
                )
                : $file;
        }

        return $this->uploadedFiles = $files;
    }

    public function withUploadedFiles(array $uploadedFiles): static
    {
        $new = clone $this;
        $new->files = $uploadedFiles;
        $new->uploadedFiles = null;

        return $new;
    }

    /**
     * @return array<string, mixed>
     */
    public function getParsedBody(): array
    {
        return $this->body;
    }

    public function withParsedBody($data): static
    {
        $new = clone $this;
        $new->body = is_array($data) ? $data : [];

        return $new;
    }

    public function getAttributes(): array
    {
        return $this->attributes;
    }

    public function getAttribute(string $name, $default = null): mixed
    {
        return array_key_exists($name, $this->attributes) ? $this->attributes[$name] : $default;
    }

    public function withAttribute(string $name, $value): static
    {
        $new = clone $this;
        $new->attributes[$name] = $value;

        return $new;
    }

    public function withoutAttribute(string $name): static
    {
        $new = clone $this;
        unset($new->attributes[$name]);

        return $new;
    }

    /**
     * The raw request body as a stream.
     *
     * Emptied for a request whose body was already parsed into {@see $body} by
     * {@see capture()}, because a framework that read `php://input` first has
     * consumed the stream and there is nothing left to hand back. A request
     * built with a body but no parsed array gets its JSON back.
     */
    public function getBody(): StreamInterface
    {
        return $this->bodyStream ?? Stream::create($this->rawBody());
    }

    /**
     * PSR requires this on every message, request included.
     *
     * The parsed array is left alone rather than cleared: a caller replacing the
     * raw body is setting what arrived on the wire, and {@see getParsedBody()} is
     * what the application reads. Throwing the parsed form away would mean one
     * PSR call silently broke every `input()` in the request.
     */
    public function withBody(StreamInterface $body): static
    {
        $new = clone $this;
        $new->bodyStream = $body;

        return $new;
    }

    /**
     * The raw body as PSR requires it.
     *
     * PSR separates the raw stream from the parsed body, and capture() can only
     * have one of them: reading `php://input` is what parses the JSON, and the
     * stream is gone afterwards. Where both exist the parsed array is the
     * useful one, so it is re-encoded rather than handed back empty.
     */
    private function rawBody(): string
    {
        if ($this->body !== []) {
            return Html::json($this->body);
        }

        return $this->serverParams['raw_body'] ?? '';
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