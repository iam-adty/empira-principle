<?php

declare(strict_types=1);

namespace IamAdty\Empira\Tests;

use IamAdty\Empira\Application;
use IamAdty\Empira\Http\Format;
use IamAdty\Empira\Http\Middleware\VerifyCsrfToken;
use IamAdty\Empira\Http\Request;
use IamAdty\Empira\Http\Response;
use IamAdty\Empira\Security\Csrf;
use IamAdty\Empira\State\NullState;
use IamAdty\Empira\Validation\ValidationException;
use IamAdty\Empira\Validation\Validator;
use PHPUnit\Framework\TestCase;

/**
 * Content negotiation and the error envelope.
 *
 * The rows that matter most are the first two: htmx sends **no** `Accept`
 * header at all, and a browser's XHR sends `*&#47;*`. Both must resolve to HTML,
 * or every fragment in every application breaks.
 */
final class NegotiationTest extends TestCase
{
    private const SECRET = 'a-secret-that-is-long-enough';

    private function app(?Csrf $csrf = null): Application
    {
        return new Application(self::SECRET, new NullState(), csrf: $csrf);
    }

    private function request(?string $accept, string $path = '/boom', string $method = 'GET'): Request
    {
        return new Request($method, $path, headers: $accept === null ? [] : ['Accept' => $accept]);
    }

    /**
     * An application with one route that fails validation, plus one known-good route.
     */
    private function failing(): Application
    {
        $app = $this->app();

        $app->routes()
            ->get('/boom', static fn() => throw new ValidationException(Validator::make([], ['title' => 'required'])));

        $app->routes()
            ->get('/known', static fn(): string => 'ok');

        return $app;
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(Response $response): array
    {
        $decoded = json_decode($response->body(), true);

        $this->assertIsArray($decoded, 'body should be JSON: ' . $response->body());

        return $decoded;
    }

    // ---------------------------------------------------------------- the accept matrix

    /**
     * @dataProvider acceptMatrix
     */
    public function testTheAcceptMatrix(?string $accept, bool $expectsJson): void
    {
        $response = $this->failing()->handle($this->request($accept));

        $this->assertSame($expectsJson, str_starts_with($response->body(), '{'), 'body: ' . $response->body());
    }

    /**
     * @return iterable<string, array{0: string|null, 1: bool}>
     */
    public static function acceptMatrix(): iterable
    {
        yield 'htmx sends no Accept at all' => [null, false];
        yield 'browser XHR default */*' => ['*/*', false];
        yield 'curl default' => ['*/*', false];
        yield 'empty header' => ['', false];
        yield 'browser navigation' => ['text/html,application/xhtml+xml,*/*;q=0.8', false];
        yield 'explicit json' => ['application/json', true];
        yield 'axios' => ['application/json, text/plain, */*', true];
        yield 'json explicitly refused' => ['application/json;q=0', false];
        yield 'html outranks json on quality' => ['text/html, application/json;q=0.9', false];
        yield 'substring lookalike' => ['application/x-javascript', false];
        yield 'tie at q=1.0 favours json' => ['text/html,application/json;q=1.0', true];
    }

    public function testAcceptsReportsPreferenceDirectly(): void
    {
        $request = new Request('GET', '/', headers: ['Accept' => 'application/json, text/plain, */*']);

        $this->assertTrue($request->accepts('application/json', ['text/html']));
        $this->assertFalse($request->accepts('text/html', ['application/json']));
    }

    public function testAMissingAcceptExpressesNoPreference(): void
    {
        $this->assertFalse(new Request('GET', '/')->accepts('application/json', ['text/html']));
    }

    // ---------------------------------------------------------------- the envelope

    public function testAValidationFailureIs422RatherThanAFatal(): void
    {
        // The bug this suite exists for: it used to fall through to 500 and be
        // rethrown, escaping handle() as an uncaught exception.
        $response = $this->failing()->handle($this->request(null));

        $this->assertSame(422, $response->status());
    }

    public function testTheJsonEnvelopeCarriesStatusMessageAndErrors(): void
    {
        $response = $this->failing()->handle($this->request('application/json'));

        $this->assertSame(422, $response->status());
        $this->assertSame('application/json', $response->headers()['Content-Type']);

        $error = $this->decode($response)['error'];

        $this->assertSame(422, $error['status']);
        $this->assertNotSame('', $error['message']);
        $this->assertArrayHasKey('title', $error['errors']);
        $this->assertNotEmpty($error['errors']['title']);
    }

    public function testNotFoundUsesTheSameEnvelope(): void
    {
        $response = $this->failing()->handle($this->request('application/json', '/nope'));

        $this->assertSame(404, $response->status());
        $this->assertSame(404, $this->decode($response)['error']['status']);
    }

    public function testAnErrorWithoutValidationOmitsTheErrorsKey(): void
    {
        $response = $this->failing()->handle($this->request('application/json', '/nope'));

        $this->assertArrayNotHasKey('errors', $this->decode($response)['error']);
    }

    public function testMethodNotAllowedKeepsItsAllowHeaderInJson(): void
    {
        $app = $this->app();
        $app->routes()->get('/only-get', static fn(): string => 'x');

        $response = $app->handle($this->request('application/json', '/only-get', 'DELETE'));

        $this->assertSame(405, $response->status());
        $this->assertSame('GET', $response->headers()['Allow']);
        $this->assertSame(405, $this->decode($response)['error']['status']);
    }

    public function testEveryFrameworkFailureSharesOneEnvelope(): void
    {
        $app = $this->app();
        $app->registry()->alias('counter', Fixtures\CounterFixture::class);

        $html = (string) $app->render(Fixtures\CounterFixture::class)->render();
        preg_match('/hx-post="([^"]*increment)"/', $html, $matches);
        $url = html_entity_decode($matches[1], ENT_QUOTES);

        // 403 from a forged token — the guard doing its job.
        $forged = $app->handle(new Request('POST', '/empira/AAAA.BBBB/increment', headers: ['Accept' => 'application/json']));

        $this->assertSame(403, $forged->status());
        $this->assertSame(403, $this->decode($forged)['error']['status']);

        // And a real one still succeeds, untouched by negotiation.
        $real = $app->handle(new Request('POST', $url, headers: ['Accept' => 'application/json']));

        $this->assertSame(200, $real->status());
        $this->assertStringNotContainsString('{"error"', $real->body());
    }

    // ---------------------------------------------------------------- html is unchanged

    public function testHtmlErrorsArePlainTextExactlyAsBefore(): void
    {
        $response = $this->failing()->handle($this->request(null));

        $this->assertSame(422, $response->status());
        $this->assertSame('Validation failed for: title.', $response->body());
        $this->assertArrayNotHasKey('Content-Type', $response->headers());
    }

    public function testAnHtmlErrorViewStillRendersForBrowsers(): void
    {
        $app = $this->failing();
        $app->onHttpError(static fn(int $status, string $message): string => '<h1>' . $status . '</h1>');

        $html = $app->handle($this->request(null));
        $json = $app->handle($this->request('application/json'));

        $this->assertSame('<h1>404</h1>', $app->handle($this->request(null, '/nope'))->body());
        $this->assertStringContainsString('<h1>', $html->body());

        // The JSON client gets the envelope instead — the view is HTML-only.
        $this->assertStringStartsWith('{', $json->body());
    }

    public function testAJsonClientStillReceivesWhateverTheHandlerReturns(): void
    {
        $app = $this->app();
        $app->routes()->get('/html', static fn(): string => '<p>plain</p>');

        // Negotiation governs errors, never a handler's own success body.
        $response = $app->handle($this->request('application/json', '/html'));

        $this->assertSame(200, $response->status());
        $this->assertSame('<p>plain</p>', $response->body());
    }

    // ---------------------------------------------------------------- csrf

    public function testCsrfRefusalIsHtmlWhenHtmlWasNegotiated(): void
    {
        $app = $this->app(new Csrf());
        $app->routes()->post('/save', static fn(): string => 'saved');
        $app->use(VerifyCsrfToken::class);

        $response = $app->handle($this->request(null, '/save', 'POST'));

        $this->assertSame(419, $response->status());
        $this->assertSame('CSRF token mismatch.', $response->body());
    }

    public function testCsrfRefusalIsTheEnvelopeForAJsonClient(): void
    {
        $app = $this->app(new Csrf());
        $app->routes()->post('/save', static fn(): string => 'saved');
        $app->use(VerifyCsrfToken::class);

        $response = $app->handle($this->request('application/json', '/save', 'POST'));

        $this->assertSame(419, $response->status());
        $this->assertSame('application/json', $response->headers()['Content-Type']);
        $this->assertSame(419, $this->decode($response)['error']['status']);
    }

    public function testCsrfStillPassesAValidTokenThrough(): void
    {
        $app = $this->app(new Csrf());
        $app->routes()->post('/save', static fn(): string => 'saved');
        $app->use(VerifyCsrfToken::class);

        $response = $app->handle(new Request('POST', '/save', headers: [
            'Accept' => 'application/json',
            'X-CSRF-Token' => $app->csrf()->token(),
        ]));

        $this->assertSame(200, $response->status());
        $this->assertSame('saved', $response->body());
    }

    public function testCsrfNeverChecksASafeMethod(): void
    {
        $app = $this->app(new Csrf());
        $app->routes()->get('/open', static fn(): string => 'open');
        $app->use(VerifyCsrfToken::class);

        $this->assertSame(200, $app->handle($this->request(null, '/open'))->status());
    }

    // ---------------------------------------------------------------- format

    public function testTheNegotiatedFormatIsReadable(): void
    {
        $app = $this->failing();

        $app->handle($this->request('application/json', '/known'));

        $this->assertSame(Format::Json, $app->format());

        $app->handle($this->request(null, '/known'));

        $this->assertSame(Format::Html, $app->format());
    }

    public function testFormatDefaultsToHtmlOutsideARequest(): void
    {
        $this->assertSame(Format::Html, $this->app()->format());
    }

    public function testFormatIsResolvableFromTheContainer(): void
    {
        $app = $this->failing();

        $seen = null;
        $app->routes()->get('/probe', static function (Format $format) use (&$seen): string {
            $seen = $format;

            return 'ok';
        });

        $app->handle($this->request('application/json', '/probe'));

        $this->assertSame(Format::Json, $seen);
    }
}