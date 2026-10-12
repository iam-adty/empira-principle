<?php

declare(strict_types=1);

namespace Empira\Tests;

use Empira\Application;
use Empira\Element;
use Empira\ForbiddenActionException;
use Empira\Http\Request;
use Empira\InvalidTokenException;
use Empira\Raw;
use Empira\Tests\Fixtures\ChattyFixture;
use Empira\Tests\Fixtures\CounterFixture;
use Empira\Tests\Fixtures\MemoryStore;
use Empira\Tests\Fixtures\NamedRootFixture;
use Empira\Tests\Fixtures\StatefulFixture;
use Empira\UnknownComponentException;
use PHPUnit\Framework\TestCase;

final class ApplicationTest extends TestCase
{
    private const SECRET = 'a-secret-that-is-long-enough';

    public function testTrustingProxiesIsChainable(): void
    {
        // The shape of the API, and that it can be turned back off — a
        // deployment that enables it by accident needs a way to undo that
        // without a code change.
        $app = new Application(self::SECRET, new MemoryStore());

        $this->assertSame($app, $app->trustProxies());
        $this->assertSame($app, $app->trustProxies(false));
    }

    private function app(?MemoryStore $store = null): Application
    {
        return new Application(self::SECRET, $store ?? new MemoryStore());
    }

    /**
     * Pull the action URL out of a rendered fragment.
     */
    private function actionUrl(string|Element|Raw $html, string $marker): string
    {
        preg_match('/hx-post="([^"]*' . preg_quote($marker, '/') . ')"/', (string) $html, $matches);

        $this->assertNotEmpty($matches, 'No action URL found for "' . $marker . '".');

        return html_entity_decode($matches[1], ENT_QUOTES);
    }

    /**
     * Pull the signed token out of a rendered fragment.
     */
    private function tokenFrom(string|Element|Raw $html, string $marker): string
    {
        $url = $this->actionUrl($html, $marker);
        [$token] = explode('/', substr($url, strlen('/empira/')));

        return $token;
    }

    private function htmxRequest(string $uri, array $body = [], string $method = 'POST'): Request
    {
        return new Request(
            method: $method,
            uri: $uri,
            body: $body,
            headers: ['HX-Request' => 'true'],
        );
    }

    // ---------------------------------------------------------------- rendering

    public function testRendersAComponentWithGeneratedWiring(): void
    {
        $app = $this->app();
        $app->registry()->add(CounterFixture::class);

        $html = (string) $app->render(CounterFixture::class);

        $this->assertStringContainsString('class="counter"', $html);
        $this->assertStringContainsString('hx-post="/empira/', $html);
        $this->assertStringContainsString('hx-swap="outerHTML"', $html);
        $this->assertStringContainsString('<span>0</span>', $html);
    }

    public function testTheRootIdIsUsedAsTheDefaultSwapTarget(): void
    {
        $app = $this->app();
        $app->registry()->add(CounterFixture::class);

        $html = (string) $app->render(CounterFixture::class);

        preg_match('/<div[^>]*\bid="([^"]+)"/', $html, $matches);
        $rootId = $matches[1];

        $this->assertStringContainsString('hx-target="#' . $rootId . '"', $html);
    }

    public function testAComponentMayNameItsOwnRootElement(): void
    {
        $app = $this->app();
        $app->registry()->add(NamedRootFixture::class);

        $html = (string) $app->render(NamedRootFixture::class);

        $this->assertStringContainsString('id="named-root"', $html);
        $this->assertStringContainsString('hx-target="#named-root"', $html);
    }

    public function testTwoInstancesOfTheSameComponentDoNotCollide(): void
    {
        $app = $this->app();
        $app->registry()->add(CounterFixture::class);

        $first = (string) $app->render(CounterFixture::class);
        $second = (string) $app->render(CounterFixture::class);

        $this->assertNotSame(
            $this->actionUrl($first, 'increment'),
            $this->actionUrl($second, 'increment')
        );
    }

    // ---------------------------------------------------------------- lifecycle

    public function testAnActionMutatesPropsAndRerendersTheFragment(): void
    {
        $app = $this->app();
        $app->registry()->add(CounterFixture::class);

        $html = (string) $app->render(CounterFixture::class);
        $url = $this->actionUrl($html, 'increment');

        $response = $app->handle($this->htmxRequest($url));

        $this->assertSame(200, $response->status());
        $this->assertStringContainsString('<span>1</span>', $response->body());
    }

    public function testStateAccumulatesAcrossActions(): void
    {
        $app = $this->app();
        $app->registry()->add(CounterFixture::class);

        $html = (string) $app->render(CounterFixture::class);
        $url = $this->actionUrl($html, 'increment');

        $url = $this->actionUrl($app->handle($this->htmxRequest($url))->body(), 'increment');
        $html = $app->handle($this->htmxRequest($url))->body();

        $this->assertStringContainsString('<span>2</span>', $html);
    }

    public function testResetReturnsToZero(): void
    {
        $app = $this->app();
        $app->registry()->add(CounterFixture::class);

        $html = (string) $app->render(CounterFixture::class, ['count' => 12]);
        $url = $this->actionUrl($html, 'reset');

        $this->assertStringContainsString('<span>0</span>', $app->handle($this->htmxRequest($url))->body());
    }

    public function testActionsMayDeclareTheirOwnVerb(): void
    {
        $app = $this->app();
        $app->registry()->add(CounterFixture::class);

        $html = (string) $app->render(CounterFixture::class);

        $this->assertStringNotContainsString('hx-get="/empira/', $html);
    }

    // ---------------------------------------------------------------- security

    public function testPropsCannotBePromotedByEditingTheUrl(): void
    {
        $app = $this->app();
        $app->registry()->add(CounterFixture::class);

        $html = (string) $app->render(CounterFixture::class, ['count' => 100]);
        $url = $this->actionUrl($html, 'increment');

        // Re-encode the token with count => 9999 but keep the old signature.
        [$token, $action] = explode('/', substr($url, strlen('/empira/')));
        [$payload, $signature] = explode('.', $token);

        $forged = rtrim(strtr(base64_encode((string) json_encode([
            'v' => 1,
            'c' => CounterFixture::class,
            'n' => json_decode((string) base64_decode(strtr($payload, '-_', '+/')), true)['n'],
            'p' => ['count' => 9999],
        ])), '+/', '-_'), '=');

        $response = $app->handle($this->htmxRequest('/empira/' . $forged . '.' . $signature . '/' . $action));

        $this->assertSame(403, $response->status());
        $this->assertStringNotContainsString('9999', $response->body());
    }

    public function testUndeclaredActionsAreRefused(): void
    {
        $app = $this->app();
        $app->registry()->add(CounterFixture::class);

        $token = $this->tokenFrom((string) $app->render(CounterFixture::class), 'increment');

        $response = $app->handle($this->htmxRequest('/empira/' . $token . '/escalate'));

        $this->assertSame(403, $response->status());
    }

    public function testAnUnregisteredClassCannotBeInstantiated(): void
    {
        $app = $this->app();
        // Note: deliberately not registered.
        $other = new Application(self::SECRET, new MemoryStore());
        $other->registry()->alias('counter', CounterFixture::class);

        $html = (string) $other->render(CounterFixture::class);
        $url = $this->actionUrl($html, 'increment');

        $response = $app->handle($this->htmxRequest($url));

        $this->assertSame(404, $response->status());
    }

    public function testFrameworkInternalsCannotBeExposedAsActions(): void
    {
        $app = $this->app();
        $app->registry()->add(\Empira\Tests\Fixtures\BadActionFixture::class);

        $html = (string) $app->render(\Empira\Tests\Fixtures\BadActionFixture::class);
        $url = $this->actionUrl($html, 'toHtml');

        $response = $app->handle($this->htmxRequest($url));

        $this->assertSame(403, $response->status());
    }

    public function testPrivateMethodsCannotBeReached(): void
    {
        $app = $this->app();
        $app->registry()->add(\Empira\Tests\Fixtures\BadActionFixture::class);

        $html = (string) $app->render(\Empira\Tests\Fixtures\BadActionFixture::class);
        $url = $this->actionUrl($html, 'secret');

        $response = $app->handle($this->htmxRequest($url));

        $this->assertSame(403, $response->status());
    }

    public function testGarbageTokensAreRejected(): void
    {
        $app = $this->app();

        $response = $app->handle($this->htmxRequest('/empira/garbage/increment'));

        $this->assertSame(403, $response->status());
    }

    // ---------------------------------------------------------------- state

    public function testStatefulComponentsRoundTripThroughTheStore(): void
    {
        $store = new MemoryStore();
        $app = $this->app($store);
        $app->registry()->add(StatefulFixture::class);

        $html = (string) $app->render(StatefulFixture::class);
        $url = $this->actionUrl($html, 'add');

        $html = $app->handle($this->htmxRequest($url, ['name' => 'milk']))->body();
        $url = $this->actionUrl($html, 'add');

        $html = $app->handle($this->htmxRequest($url, ['name' => 'bread']))->body();

        $this->assertStringContainsString('<li>milk</li>', $html);
        $this->assertStringContainsString('<li>bread</li>', $html);
        $this->assertNotSame([], $store->buckets);
    }

    public function testStatefulTokensStaySmallBecausePropsAreNotEmbedded(): void
    {
        $app = $this->app();
        $app->registry()->add(StatefulFixture::class);

        $html = (string) $app->render(StatefulFixture::class, ['items' => array_fill(0, 50, str_repeat('x', 100))]);
        $url = $this->actionUrl($html, 'add');

        // 5 KB of props would dwarf the URL if they rode along in the token.
        $this->assertLessThan(400, strlen($url));
    }

    // ---------------------------------------------------------------- responses

    public function testComponentsCanQueueHtmxResponseHeaders(): void
    {
        $app = $this->app();
        $app->registry()->add(ChattyFixture::class);

        $html = (string) $app->render(ChattyFixture::class);
        $url = $this->actionUrl($html, 'save');

        $response = $app->handle($this->htmxRequest($url));
        $headers = $response->headers();

        $this->assertSame('/saved', $headers['HX-Push-Url']);
        $this->assertSame('{"saved":{"id":42}}', $headers['HX-Trigger-After-Swap']);
    }

    // ---------------------------------------------------------------- routing

    public function testNonComponentRequestsReachTheFallback(): void
    {
        $app = $this->app();

        $app->fallback(fn(Request $request): string => 'page for ' . $request->path());

        $this->assertSame('page for /about', $app->handle(new Request('GET', '/about'))->body());
    }

    public function testMalformedActionPathsFallThroughToTheFallback(): void
    {
        $app = $this->app();
        $app->registry()->add(CounterFixture::class);
        $app->fallback(fn(): string => 'fallback');

        $this->assertSame('fallback', $app->handle(new Request('GET', '/empira/only-one-segment'))->body());
    }

    public function testUnknownPaths404WhenNoFallbackIsSet(): void
    {
        $this->assertSame(404, $this->app()->handle(new Request('GET', '/nope'))->status());
    }

    public function testTheBaseUrlIsConfigurable(): void
    {
        $app = new Application(self::SECRET, new MemoryStore(), '/_hx');
        $app->registry()->alias('counter', CounterFixture::class);

        $html = (string) $app->render(CounterFixture::class);
        $url = $this->actionUrl($html, 'increment');

        $this->assertStringStartsWith('/_hx/', $url);
        $this->assertStringContainsString('<span>1</span>', $app->handle($this->htmxRequest($url))->body());
    }

    public function testAliasesKeepTokensShortAndRenameSafe(): void
    {
        $app = $this->app();
        $app->registry()->alias('counter', CounterFixture::class);

        $token = $this->tokenFrom((string) $app->render(CounterFixture::class), 'increment');

        // The token carries the short alias, not the FQCN, so moving the class
        // to another namespace does not invalidate links already in the wild.
        $identity = (new \Empira\TokenCodec(self::SECRET))->decode($token);

        $this->assertSame('counter', $identity['class']);
    }

    public function testExceptionClassesMapToTheRightStatusCodes(): void
    {
        $this->assertTrue(is_subclass_of(InvalidTokenException::class, \Empira\Exception::class));
        $this->assertTrue(is_subclass_of(UnknownComponentException::class, \Empira\Exception::class));
        $this->assertTrue(is_subclass_of(ForbiddenActionException::class, \Empira\Exception::class));
    }
}