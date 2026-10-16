<?php

declare(strict_types=1);

namespace Empira\Testing;

use Empira\Application;
use Empira\Http\Request;
use Empira\Security\Csrf;

/**
 * Drives an {@see Application} the way a browser would, so a test can ask a
 * question in one line instead of assembling a `Request` by hand.
 *
 *     $client = new TestClient($app);
 *
 *     $client->get('/todos')->request()->assertOk()->assertSee('Buy milk');
 *
 *     $page = $client->get('/todos')->request();
 *     $url  = $this->actionUrl($page->body(), 'complete');
 *     $client->post($url, ['id' => 7])->request()->assertOk();
 *
 * The verb methods stage a request and return `$this`; `request()` sends it and
 * hands back a {@see TestResponse}. Nothing is buffered between calls, so the
 * order of the lines above is the order the application sees.
 *
 * ## Query strings
 *
 * A URI is written the way a browser would write it, and the client splits the
 * query out for you:
 *
 *     $client->get('/todos?q=milk&page=2');
 *
 * The full URI is kept on the request, so routing, redirects and anything that
 * logs `Request::uri()` all see what the browser really sent.
 *
 * ## Headers
 *
 * `withHeader()` applies to the next request that is sent and is cleared
 * afterwards, so one `TestClient` can drive a whole scenario without leaking
 * state between requests. Per-request headers may also be passed to the verb
 * method directly.
 *
 * ## CSRF
 *
 * When the application was built with a {@see Csrf} guard, a valid token is
 * attached to every outgoing request automatically, so a test can post an
 * action without restating the header the real page forwards. A token the
 * caller supplies themselves is never overwritten — that is how you test that
 * a *bad* token is rejected.
 *
 * The token is read from the guard's own `hx-headers` payload, so the header
 * name always matches the one the guard was configured with rather than a
 * name assumed here.
 *
 * @see \Empira\Testing\TestResponse
 */
final class TestClient
{
    private string $method = '';

    private string $uri = '';

    /** @var array<string, mixed> */
    private array $body = [];

    /** @var array<string, string> lower-cased header name => value */
    private array $headers = [];

    public function __construct(private readonly Application $app)
    {
    }

    /**
     * @param array<string, string> $headers
     */
    public function get(string $uri, array $headers = []): self
    {
        return $this->pending('GET', $uri, [], $headers);
    }

    /**
     * @param array<string, mixed>  $body
     * @param array<string, string> $headers
     */
    public function post(string $uri, array $body = [], array $headers = []): self
    {
        return $this->pending('POST', $uri, $body, $headers);
    }

    /**
     * @param array<string, mixed>  $body
     * @param array<string, string> $headers
     */
    public function put(string $uri, array $body = [], array $headers = []): self
    {
        return $this->pending('PUT', $uri, $body, $headers);
    }

    /**
     * @param array<string, mixed>  $body
     * @param array<string, string> $headers
     */
    public function patch(string $uri, array $body = [], array $headers = []): self
    {
        return $this->pending('PATCH', $uri, $body, $headers);
    }

    /**
     * @param array<string, mixed>  $body
     * @param array<string, string> $headers
     */
    public function delete(string $uri, array $body = [], array $headers = []): self
    {
        return $this->pending('DELETE', $uri, $body, $headers);
    }

    /**
     * Mark the next request as an ordinary page load rather than an htmx swap.
     */
    public function withHeader(string $name, string $value): self
    {
        $this->headers[strtolower($name)] = $value;

        return $this;
    }

    /**
     * Send `HX-Request: true`, so the request looks like one htmx issued.
     */
    public function asHtmx(): self
    {
        return $this->withHeader('HX-Request', 'true');
    }

    /**
     * Send the pending request.
     *
     * @throws \LogicException when no verb method has been called yet
     */
    public function request(): TestResponse
    {
        if ($this->method === '') {
            throw new \LogicException(
                'No request is pending: call get(), post(), put(), patch() or delete() first.'
            );
        }

        $request = new Request(
            method: $this->method,
            uri: $this->uri,
            query: $this->query(),
            body: $this->body,
            headers: $this->headers,
        );

        $response = $this->app->handle($request);

        $this->forget();

        return new TestResponse($response);
    }

    /**
     * The underlying application, for anything this client does not wrap.
     */
    public function app(): Application
    {
        return $this->app;
    }

    /**
     * @param array<string, mixed>  $body
     * @param array<string, string> $headers
     */
    private function pending(string $method, string $uri, array $body, array $headers): self
    {
        $this->method = $method;
        $this->uri = $uri;
        $this->body = $body;

        foreach ($headers as $name => $value) {
            $this->headers[strtolower((string) $name)] = (string) $value;
        }

        $this->withCsrfToken();

        return $this;
    }

    /**
     * Attach a token the guard will accept, unless the caller brought one.
     *
     * Every verb gets one: `Component::beforeAction()` verifies before it
     * dispatches, whatever the action's own verb happens to be.
     */
    private function withCsrfToken(): void
    {
        $csrf = $this->app->csrf();

        if ($csrf === null) {
            return;
        }

        [$name, $token] = $this->csrfPair($csrf);

        if ($this->hasCsrfToken($name)) {
            return;
        }

        $this->headers[$name] = $token;
    }

    /**
     * The guard's own header name paired with a token it will accept.
     *
     * Reading the name back out of `headersAttribute()` rather than assuming
     * `X-CSRF-Token` means a guard configured with a different header still
     * gets the header it actually checks.
     *
     * @return array{string, string}
     */
    private function csrfPair(Csrf $csrf): array
    {
        $decoded = json_decode($csrf->headersAttribute(), true);

        if (is_array($decoded) && $decoded !== []) {
            $name = (string) array_key_first($decoded);

            return [strtolower($name), (string) $decoded[$name]];
        }

        return ['x-csrf-token', $csrf->token()];
    }

    /**
     * Has the caller already spoken for the token, header or body field?
     */
    private function hasCsrfToken(string $name): bool
    {
        return isset($this->headers[$name]) || array_key_exists('_csrf', $this->body);
    }

    /**
     * The query parameters of the staged URI.
     *
     * `Request::capture()` fills this from `$_GET`; a test has no superglobals,
     * so the client parses it out of the URI instead. That is what makes
     * `get('/todos?q=milk')` behave like the request a browser makes.
     *
     * @return array<string, mixed>
     */
    private function query(): array
    {
        $query = parse_url($this->uri, PHP_URL_QUERY);

        if (!is_string($query) || $query === '') {
            return [];
        }

        parse_str($query, $parsed);

        return $parsed;
    }

    /**
     * Drop everything staged for the request that has just been sent.
     */
    private function forget(): void
    {
        $this->method = '';
        $this->uri = '';
        $this->body = [];
        $this->headers = [];
    }
}