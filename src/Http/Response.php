<?php

declare(strict_types=1);

namespace IamAdty\Empira\Http;

use IamAdty\Empira\Html;

/**
 * An outbound HTTP response with fluent `HX-*` header helpers.
 *
 * These are the response-side counterparts to htmx's request attributes: they
 * tell the client to redirect, retarget, push history, fire a JS event and so
 * on once a swap completes.
 *
 *     return response()->pushUrl('/posts/1')
                     ->retarget('#flash')
                     ->triggerAfterSwap('saved');
 *
 * Trigger headers accumulate and are merged into a single JSON header when
 * sent, which is what htmx expects.
 */
final class Response
{
    /** @var array<string, string[]> */
    private array $headers = [];

    /** @var array<string, array<string, mixed>> queued HX-Trigger payloads */
    private array $triggers = [];

    /** Path streamed by {@see stream()} instead of an in-memory body. */
    private ?string $file = null;

    private array $triggerAfterSwap = [];
    private array $triggerAfterSettle = [];

    public function __construct(
        private string $body = '',
        private int $status = 200,
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
        return (new self('', $status))->setBody($html);
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
        return (new self('', $status))
            ->withHeader('Content-Type', 'application/json')
            ->setBody(Html::json($data));
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

    public function body(): string
    {
        return $this->body;
    }

    public function setBody(string $body): self
    {
        $this->body = $body;

        return $this;
    }

    public function status(): int
    {
        return $this->status;
    }

    public function setStatus(int $status): self
    {
        $this->status = $status;

        return $this;
    }

    public function withHeader(string $name, string $value): self
    {
        $this->headers[$name] = [self::sanitiseHeaderValue($value)];

        return $this;
    }

    /**
     * Strip CR, LF and NUL so a value can never smuggle an extra header.
     */
    private static function sanitiseHeaderValue(string $value): string
    {
        return trim((string) preg_replace('/[\r\n\0]+/', '', $value));
    }

    /**
     * All headers, with queued `HX-Trigger*` headers merged into JSON.
     *
     * @return array<string, string>
     */
    public function headers(): array
    {
        $headers = [];

        foreach ($this->headers as $name => $values) {
            $headers[$name] = implode(', ', $values);
        }

        foreach (['Trigger' => $this->triggers, 'Trigger-After-Swap' => $this->triggerAfterSwap, 'Trigger-After-Settle' => $this->triggerAfterSettle] as $suffix => $events) {
            if ($events !== []) {
                $headers['HX-' . $suffix] = Html::json($events);
            }
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
        $this->triggers[$event] = $detail === [] ? null : $detail;

        return $this;
    }

    /** Fire a client-side event after the swap settles. */
    public function triggerAfterSwap(string $event, array $detail = []): self
    {
        $this->triggerAfterSwap[$event] = $detail === [] ? null : $detail;

        return $this;
    }

    /** Fire a client-side event after the settle step completes. */
    public function triggerAfterSettle(string $event, array $detail = []): self
    {
        $this->triggerAfterSettle[$event] = $detail === [] ? null : $detail;

        return $this;
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

        return $this->withHeader('Set-Cookie', implode('; ', array_map(
            static fn(string $part): string => self::sanitiseHeaderValue($part),
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
        $this->file = $path;

        return $this;
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

    /**
     * Emit body and headers. This terminates the script.
     *
     * A streamed file is read in chunks rather than loaded whole, so
     * downloading a large file costs no memory.
     */
    public function send(): never
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

            exit;
        }

        echo $this->body;

        exit;
    }
}