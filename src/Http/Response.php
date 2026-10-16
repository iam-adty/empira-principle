<?php

declare(strict_types=1);

namespace Empira\Http;

use Empira\Html;
use Nyholm\Psr7\Stream;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;

/**
 * An outbound HTTP response with fluent `HX-*` header helpers.
 *
 * These are the response-side counterparts to htmx's request attributes: they
 * tell the client to redirect, retarget, push history, fire a JS event and so
 * on once a swap completes.
 *
 *     return response()->pushUrl('/posts/1')
 *                      ->retarget('#flash')
 *                      ->triggerAfterSwap('saved');
 *
 * Trigger headers accumulate and are merged into a single JSON header when
 * sent, which is what htmx expects.
 *
 * ## Immutable, as PSR-7 requires
 *
 * Every `with*()` returns a **new** response and leaves the one you called it
 * on untouched. That was not always true here: `withHeader()` used to mutate in
 * place under a PSR-shaped name, which is the one thing PSR-7 forbids and the
 * one thing a shared response in a middleware stack cannot survive.
 *
 * The cost is that a discarded return value now does nothing:
 *
 *     $response->withHeader('X-A', 'b');          // no effect
 *     $response = $response->withHeader('X-A', 'b');   // what you meant
 *
 * There is no mutating counterpart to fall back on. `setBody()` and
 * `setStatus()` are gone rather than kept as aliases, because one immutable way
 * and one mutable way is how a codebase ends up with responses that half the
 * pipeline believes are shared.
 *
 * ## Two header shapes, deliberately
 *
 * `getHeaders()` is PSR's `array<string, string[]>`, because a header may
 * legitimately repeat. `headers()` is this package's `array<string, string>`,
 * because that is what the emitter and htmx want. They are not
 * interchangeable, and `headers()` is derived from `getHeaders()` rather than
 * kept separately, so they cannot drift.
 */
final class Response implements ResponseInterface
{
    /** @var array<string, string[]> header name => values, as written */
    private array $headers = [];

    /** @var array<string, array<string, mixed>> queued HX-Trigger payloads */
    private array $triggers = [];

    /** Path streamed by {@see stream()} instead of an in-memory body. */
    private ?string $file = null;

    private array $triggerAfterSwap = [];

    private array $triggerAfterSettle = [];

    /** Memoised PSR stream, so repeated `getBody()` does not reopen the file. */
    private ?StreamInterface $stream = null;

    private string $protocolVersion = '1.1';

    public function __construct(
        private string $body = '',
        private int $status = 200,
        private string $reasonPhrase = '',
    ) {
    }

    /**
     * An HTML response.
     *
     * The status defaults to 200; pass one explicitly when you need 201, 304 or
     * any other code, because a second argument that is not declared here is
     * discarded by PHP rather than reported.
     */
    public static function html(string $html, int $status = 200): self
    {
        return (new self())->withStatus($status)->withHtml($html);
    }

    /**
     * A real HTTP redirect: a `Location` header and a 3xx status.
     *
     * This is not the same as {@see redirect()}, which asks htmx to navigate
     * client-side and leaves the browser's URL alone.
     */
    public static function redirectTo(string $url, int $status = 302): self
    {
        return (new self('', $status))->withHeader('Location', $url);
    }

    public static function json(mixed $data, int $status = 200): self
    {
        return (new self())->withStatus($status)->withJson($data);
    }

    public static function noContent(int $status = 204): self
    {
        return new self('', $status);
    }

    /** The response htmx should use to halt polling. */
    public static function stopPolling(): self
    {
        return new self('', 286);
    }

    // ------------------------------------------------------------------- PSR-7

    public function getStatusCode(): int
    {
        return $this->status;
    }

    public function withStatus(int $code, string $reasonPhrase = ''): static
    {
        $new = clone $this;
        $new->status = $code;
        $new->reasonPhrase = $reasonPhrase;
        $new->stream = null;

        return $new;
    }

    public function getReasonPhrase(): string
    {
        return $this->reasonPhrase;
    }

    /**
     * @return array<string, string[]>
     */
    public function getHeaders(): array
    {
        $headers = $this->headers;

        foreach (['Trigger' => $this->triggers, 'Trigger-After-Swap' => $this->triggerAfterSwap, 'Trigger-After-Settle' => $this->triggerAfterSettle] as $suffix => $events) {
            if ($events !== []) {
                $headers['HX-' . $suffix] = [Html::json($events)];
            }
        }

        return $headers;
    }

    public function hasHeader(string $name): bool
    {
        foreach (array_keys($this->getHeaders()) as $header) {
            if (strcasecmp($header, $name) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return string[]
     */
    public function getHeader(string $name): array
    {
        foreach ($this->getHeaders() as $header => $values) {
            if (strcasecmp($header, $name) === 0) {
                return $values;
            }
        }

        return [];
    }

    public function getHeaderLine(string $name): string
    {
        return implode(', ', $this->getHeader($name));
    }

    public function withHeader(string $name, $value): static
    {
        $new = clone $this;
        $new->headers = $this->headersWithout($name);
        $new->headers[$name] = self::normaliseHeaderValue($value);

        return $new;
    }

    /**
     * @param string|string[] $value
     */
    public function withAddedHeader(string $name, $value): static
    {
        $new = clone $this;
        $existing = $new->getHeader($name);

        $new->headers = $this->headersWithout($name);
        $new->headers[$name] = [...$existing, ...self::normaliseHeaderValue($value)];

        return $new;
    }

    public function withoutHeader(string $name): static
    {
        $new = clone $this;
        $new->headers = $this->headersWithout($name);

        return $new;
    }

    public function getBody(): StreamInterface
    {
        if ($this->stream !== null) {
            return $this->stream;
        }

        // A streamed file answers with itself. It is opened here rather than in
        // `stream()` so that setting up a download costs nothing until something
        // actually reads it — which `emit()` deliberately does not, because it
        // reads `$this->file` in chunks.
        return $this->stream = $this->file !== null
            ? Stream::create(fopen($this->file, 'rb') ?: '')
            : Stream::create($this->body);
    }

    public function withBody(StreamInterface $body): static
    {
        $new = clone $this;
        $new->stream = $body;
        $new->file = null;
        $new->body = '';

        return $new;
    }

    /**
     * Set a string body.
     *
     * Sugar for `withBody(Stream::create($html))`, which is what PSR-7 requires
     * and what {@see withBody()} does. It exists because the alternative reads as
     * `withBody(Stream::create($html))` at every call site in a framework whose
     * bodies are strings — and a stream import leaking into twenty call sites
     * makes the one place that genuinely deals in streams harder to see.
     */
    public function withHtml(string $html): static
    {
        return $this->withBody(Stream::create($html));
    }

    /**
     * Encode `$data` as JSON and set it, with the content type to match.
     */
    public function withJson(mixed $data): static
    {
        return $this->withHeader('Content-Type', 'application/json')
            ->withBody(Stream::create(Html::json($data)));
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

    // ------------------------------------------------------------ empira's own

    /**
     * The body as a string, for this package's own convenience.
     *
     * Empty for a streamed response, whose body is a file rather than a string
     * in memory — {@see getBody()} is the method that answers either, and
     * `emit()` is what a front controller should be calling anyway.
     */
    public function body(): string
    {
        return $this->file === null ? (string) $this->getBody() : '';
    }

    public function status(): int
    {
        return $this->status;
    }

    /**
     * Strip CR, LF and NUL so a value can never smuggle an extra header.
     */
    private static function normaliseHeaderValue(string|array $value): array
    {
        return array_map(
            static fn (string $item): string => trim((string) preg_replace('/[\r\n\0]+/', '', $item)),
            is_array($value) ? array_values($value) : [$value],
        );
    }

    /**
     * Every stored header except `$name`, compared case-insensitively.
     *
     * Header names are case-insensitive in HTTP but case-preserving in practice,
     * so `Content-Type` and `content-type` are one header. Setting one must not
     * leave the other behind, which is what a plain `unset($headers[$name])`
     * would do.
     *
     * @return array<string, string[]>
     */
    private function headersWithout(string $name): array
    {
        $kept = [];

        foreach ($this->headers as $header => $values) {
            if (strcasecmp($header, $name) !== 0) {
                $kept[$header] = $values;
            }
        }

        return $kept;
    }

    /**
     * All headers as flat strings, for the emitter and for htmx.
     *
     * @return array<string, string>
     */
    public function headers(): array
    {
        $headers = [];

        foreach ($this->getHeaders() as $name => $values) {
            $headers[$name] = implode(', ', $values);
        }

        return $headers;
    }

    // ------------------------------------------------------------ htmx response headers

    /** Add the browser URL to the history stack (pass `false` to opt out). */
    public function pushUrl(string|bool $url = true): self
    {
        return $this->withHeader('HX-Push-Url', $url === false ? 'false' : $url);
    }

    /** Replace the current history entry (pass `false` to opt out). */
    public function replaceUrl(string|bool $url = true): self
    {
        return $this->withHeader('HX-Replace-Url', $url === false ? 'false' : $url);
    }

    /** Client-side redirect. */
    public function redirect(string $url): self
    {
        return $this->withHeader('HX-Redirect', $url);
    }

    /** Client-side navigation without a full reload; accepts a location object. */
    public function location(string|array $location): self
    {
        return $this->withHeader('HX-Location', is_array($location) ? Html::json($location) : $location);
    }

    /** Override the swap strategy for this response. */
    public function reswap(string $strategy): self
    {
        return $this->withHeader('HX-Reswap', $strategy);
    }

    /** Redirect this response's swap to a different element. */
    public function retarget(string $selector): self
    {
        return $this->withHeader('HX-Retarget', $selector);
    }

    /** Swap in only the matching part of the response body. */
    public function reselect(string $selector): self
    {
        return $this->withHeader('HX-Reselect', $selector);
    }

    /** Trigger a full client-side page refresh. */
    public function refresh(bool $refresh = true): self
    {
        return $this->withHeader('HX-Refresh', $refresh ? 'true' : 'false');
    }

    /** Fire a client-side event. Pass `['evt' => 'detail']` for a payload. */
    public function trigger(string $event, array $detail = []): self
    {
        $new = clone $this;
        $new->triggers[$event] = $detail === [] ? null : $detail;

        return $new;
    }

    /** Fire a client-side event after the swap settles. */
    public function triggerAfterSwap(string $event, array $detail = []): self
    {
        $new = clone $this;
        $new->triggerAfterSwap[$event] = $detail === [] ? null : $detail;

        return $new;
    }

    /** Fire a client-side event after the settle step completes. */
    public function triggerAfterSettle(string $event, array $detail = []): self
    {
        $new = clone $this;
        $new->triggerAfterSettle[$event] = $detail === [] ? null : $detail;

        return $new;
    }

    /**
     * Set a cookie.
     *
     * @param array{path?: string, domain?: string, expires?: int, secure?: bool, httponly?: bool, samesite?: string} $options
     */
    public function cookie(string $name, string $value, array $options = []): self
    {
        $parts = [rawurlencode($name) . '=' . rawurlencode($value)];

        $parts[] = 'Path=' . ($options['path'] ?? '/');
        $parts[] = 'SameSite=' . ($options['samesite'] ?? 'Lax');

        if (isset($options['domain'])) {
            $parts[] = 'Domain=' . $options['domain'];
        }

        if (isset($options['expires'])) {
            $parts[] = 'Expires=' . gmdate('D, d M Y H:i:s \G\M\T', $options['expires']);
            $parts[] = 'Max-Age=' . max(0, $options['expires'] - time());
        }

        if ($options['secure'] ?? false) {
            $parts[] = 'Secure';
        }

        if ($options['httponly'] ?? true) {
            $parts[] = 'HttpOnly';
        }

        // Appended rather than replaced, so two cookies in one response — a
        // session and a flash, say — do not leave the second clobbering the first.
        return $this->withAddedHeader('Set-Cookie', implode('; ', array_map(
            static fn (string $part): string => trim((string) preg_replace('/[\r\n\0]+/', '', $part)),
            $parts
        )));
    }

    /**
     * Expire a cookie.
     *
     * @param array{path?: string, domain?: string} $options
     */
    public function forgetCookie(string $name, array $options = []): self
    {
        return $this->cookie($name, '', array_merge($options, ['expires' => time() - 3600]));
    }

    /**
     * Send a file as a download.
     *
     * The path is read lazily and never echoed into the body, so a large file
     * costs no memory.
     */
    public function download(string $path, ?string $name = null): self
    {
        $name ??= basename($path);

        return (new self('', 200))
            ->withHeader('Content-Type', self::contentType($path))
            ->withHeader('Content-Disposition', sprintf('attachment; filename="%s"', self::sanitiseHeaderValue($name)))
            ->withHeader('Content-Length', (string) (filesize($path) ?: 0))
            ->stream($path);
    }

    /**
     * Stream a file from disk as the response body.
     */
    public function stream(string $path): self
    {
        $new = clone $this;
        $new->file = $path;
        $new->stream = null;

        return $new;
    }

    /**
     * Cache the response in the browser for `$seconds`.
     */
    public function cache(int $seconds, bool $public = false): self
    {
        return $this
            ->withHeader('Cache-Control', sprintf('%s, max-age=%d', $public ? 'public' : 'private', $seconds))
            ->withHeader('Expires', gmdate('D, d M Y H:i:s \G\M\T', time() + $seconds));
    }

    /**
     * Refuse to cache this response.
     */
    public function noStore(): self
    {
        return $this->withHeader('Cache-Control', 'no-store, max-age=0');
    }

    private static function contentType(string $path): string
    {
        return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'html', 'htm' => 'text/html; charset=utf-8',
            'txt', 'csv' => 'text/plain; charset=utf-8',
            'json' => 'application/json',
            'xml' => 'application/xml',
            'css' => 'text/css',
            'js' => 'text/javascript',
            'svg' => 'image/svg+xml',
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'pdf' => 'application/pdf',
            default => 'application/octet-stream',
        };
    }

    private static function sanitiseHeaderValue(string $value): string
    {
        return trim((string) preg_replace('/[\r\n\0]+/', '', $value));
    }

    /**
     * Write this response to the client without terminating the process.
     *
     * A classic runtime ends here: `send()` emits and exits, because there is
     * one request per process. A worker must not — an `exit` would take the
     * worker down with it, and FrankenPHP would restart it, giving exactly one
     * request per boot and none of the reason to run a worker. That is the
     * whole difference between the two, and it is why this method exists.
     */
    public function emit(): void
    {
        if (!headers_sent()) {
            http_response_code($this->status);

            foreach ($this->headers() as $name => $value) {
                header($name . ': ' . $value, true);
            }
        }

        if ($this->file !== null) {
            $handle = @fopen($this->file, 'rb');

            if ($handle !== false) {
                while (!feof($handle)) {
                    echo fread($handle, 8192);
                }

                fclose($handle);
            }

            return;
        }

        echo (string) $this->getBody();
    }

    /**
     * Emit the response and end the script.
     *
     * Declared `never` so a classic front controller can call it as its last
     * statement without the analyser wondering what runs after the `exit`.
     */
    public function send(): never
    {
        $this->emit();

        exit;
    }
}