<?php

declare(strict_types=1);

namespace Empira\Tests;

use Empira\Application;
use Empira\Http\Request;
use Empira\Http\Response;
use Empira\Security\Csrf;
use Empira\State\NullState;
use Empira\Testing\TestClient;
use Empira\Testing\TestResponse;
use Empira\Tests\Fixtures\CounterFixture;
use Empira\Tests\Fixtures\VerbsFixture;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\TestCase;

/**
 * The test client, and the response value object it hands back.
 */
final class TestClientTest extends TestCase
{
    private const SECRET = 'a-secret-that-is-long-enough';

    protected function setUp(): void
    {
        // Csrf keeps its token in the session superglobal, which is a global
        // for as long as the tests share a process.
        $_SESSION = [];
    }

    private function app(): Application
    {
        return new Application(self::SECRET, new NullState());
    }

    private function guardedApp(): Application
    {
        return new Application(self::SECRET, new NullState(), '/empira', new Csrf());
    }

    /**
     * An app whose routes can see the method, headers and body they were sent.
     */
    private function echoingApp(): Application
    {
        $app = $this->app();

        $app->routes()->any('/echo', function (Request $request): Response {
            return Response::json([
                'method' => $request->method,
                'body' => $request->body,
                'flavor' => $request->header('x-flavor'),
                'token' => $request->csrfToken(),
                'htmx' => $request->isHtmx(),
            ]);
        });

        return $app;
    }

    private function actionUrl(string $html, string $verb, string $action): string
    {
        preg_match('/hx-' . strtolower($verb) . '="([^"]*' . preg_quote($action, '/') . ')"/', $html, $matches);

        $this->assertNotEmpty($matches, sprintf('No %s action URL found for "%s".', strtoupper($verb), $action));

        return html_entity_decode($matches[1], ENT_QUOTES);
    }

    /**
     * @param callable(): void $assertion
     */
    private function assertFails(callable $assertion, string $expectedMessage): void
    {
        try {
            $assertion();
        } catch (AssertionFailedError $e) {
            $this->assertStringContainsString($expectedMessage, $e->getMessage());

            return;
        }

        $this->fail('The assertion should have failed.');
    }

    // ---------------------------------------------------------------- sending

    public function testItReturnsTheStatusAndBody(): void
    {
        $app = $this->app();
        $app->routes()->get('/hello', fn(): string => 'hi there');

        $response = (new TestClient($app))->get('/hello')->request();

        $this->assertSame(200, $response->status());
        $this->assertSame('hi there', $response->body());
    }

    public function testItSendsTheBodyWithAPost(): void
    {
        $app = $this->app();
        $app->routes()->post('/todos', fn(Request $request): string => 'made ' . $request->string('title'));

        $response = (new TestClient($app))->post('/todos', ['title' => 'milk'])->request();

        $this->assertSame(200, $response->status());
        $this->assertSame('made milk', $response->body());
    }

    public function testPutPatchAndDeleteAllReachTheHandler(): void
    {
        $client = new TestClient($this->echoingApp());

        foreach (['put', 'patch', 'delete'] as $verb) {
            $decoded = $this->decode($client->{$verb}('/echo', ['title' => 'milk'])->request());

            $this->assertSame(strtoupper($verb), $decoded['method']);
            $this->assertSame(['title' => 'milk'], $decoded['body']);
        }
    }

    public function testAQueryStringBelongsInTheUri(): void
    {
        $app = $this->app();
        $app->routes()->get('/search', fn(Request $request): string => $request->string('q'));

        $this->assertSame('milk', (new TestClient($app))->get('/search?q=milk')->request()->body());
    }

    public function testItRefusesToSendWithoutAPendingRequest(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('No request is pending');

        (new TestClient($this->app()))->request();
    }

    public function testTheApplicationIsAvailableForAnythingTheClientDoesNotWrap(): void
    {
        $app = $this->app();
        $client = new TestClient($app);

        $this->assertSame($app, $client->app());
    }

    // ---------------------------------------------------------------- headers

    public function testAHeaderCanBeSetForTheNextRequest(): void
    {
        $client = new TestClient($this->echoingApp());

        $client->withHeader('X-Flavor', 'vanilla');

        $this->assertSame('vanilla', $this->decode($client->get('/echo')->request())['flavor']);
    }

    public function testAHeaderAppliesToOneRequestOnly(): void
    {
        $client = new TestClient($this->echoingApp());
        $client->withHeader('X-Flavor', 'vanilla');

        $client->get('/echo')->request();

        $this->assertNull($this->decode($client->get('/echo')->request())['flavor']);
    }

    public function testHeadersCanBePassedToAVerbMethod(): void
    {
        $client = new TestClient($this->echoingApp());

        $response = $client->get('/echo', ['X-Flavor' => 'strawberry'])->request();

        $this->assertSame('strawberry', $this->decode($response)['flavor']);
    }

    public function testAsHtmxMarksTheRequest(): void
    {
        $client = new TestClient($this->echoingApp());

        $this->assertTrue($this->decode($client->asHtmx()->get('/echo')->request())['htmx']);
        $this->assertFalse($this->decode($client->get('/echo')->request())['htmx']);
    }

    // ---------------------------------------------------------------- component actions

    public function testItDrivesAComponentAction(): void
    {
        $app = $this->app();
        $app->registry()->alias('counter', CounterFixture::class);

        $url = $this->actionUrl((string) $app->render(CounterFixture::class), 'post', 'increment');

        (new TestClient($app))->post($url)->request()
            ->assertOk()
            ->assertSee('<span>1</span>');
    }

    // ---------------------------------------------------------------- response accessors

    public function testItReadsASingleHeaderAndAllOfThem(): void
    {
        $app = $this->app();
        $app->routes()->get('/chatty', fn(): Response => Response::html('ok')->pushUrl('/next')->trigger('saved'));

        $response = (new TestClient($app))->get('/chatty')->request();

        $this->assertSame('/next', $response->header('HX-Push-Url'));
        $this->assertSame('/next', $response->header('hx-push-url'), 'header lookup is case-insensitive');
        $this->assertNull($response->header('HX-Missing'));
        $this->assertSame('/next', $response->headers()['HX-Push-Url']);
    }

    public function testTheUnderlyingResponseIsAvailable(): void
    {
        $app = $this->app();
        $app->routes()->get('/x', fn(): Response => Response::html('ok')->setStatus(201));

        $response = (new TestClient($app))->get('/x')->request();

        $this->assertSame(201, $response->response()->status());
        $this->assertSame('ok', $response->response()->body());
    }

    // ---------------------------------------------------------------- assertions that pass

    public function testTheAssertionsChainAndReturnTheResponse(): void
    {
        $app = $this->app();
        $app->routes()->get('/todos/7', fn(): Response => Response::html('Buy milk')->withHeader('X-Source', 'db'));

        $response = (new TestClient($app))->get('/todos/7')->request();

        $this->assertSame($response, $response->assertOk());
        $this->assertSame($response, $response
            ->assertStatus(200)
            ->assertSee('Buy milk')
            ->assertDontSee('Buy bread')
            ->assertHeader('X-Source', 'db'));
    }

    public function testAssertNotFoundPassesOnA404(): void
    {
        $response = (new TestClient($this->app()))->get('/nope')->request();

        $response->assertNotFound();
    }

    public function testAssertForbiddenPassesOnA403(): void
    {
        // A garbage token is an invalid token, which the application answers 403.
        $response = (new TestClient($this->app()))->post('/empira/garbage/increment')->request();

        $response->assertForbidden();
    }

    // ---------------------------------------------------------------- assertions that fail

    public function testAssertStatusFailsOnAMismatch(): void
    {
        $response = (new TestClient($this->app()))->get('/nope')->request();

        $this->assertFails(fn() => $response->assertStatus(200), 'Expected status 200, got 404');
    }

    public function testAssertOkFailsOnANonOkResponse(): void
    {
        $response = (new TestClient($this->app()))->get('/nope')->request();

        $this->assertFails(fn() => $response->assertOk(), 'Expected status 200, got 404');
    }

    public function testAssertNotFoundFailsOnAFoundResponse(): void
    {
        $app = $this->app();
        $app->routes()->get('/here', fn(): string => 'here');

        $response = (new TestClient($app))->get('/here')->request();

        $this->assertFails(fn() => $response->assertNotFound(), 'Expected status 404, got 200');
    }

    public function testAssertForbiddenFailsOnAnAllowedResponse(): void
    {
        $app = $this->app();
        $app->routes()->get('/open', fn(): string => 'open');

        $response = (new TestClient($app))->get('/open')->request();

        $this->assertFails(fn() => $response->assertForbidden(), 'Expected status 403, got 200');
    }

    public function testAssertSeeFailsOnAMissingNeedle(): void
    {
        $app = $this->app();
        $app->routes()->get('/todos', fn(): string => '<ul><li>Buy milk</li></ul>');

        $response = (new TestClient($app))->get('/todos')->request();

        $this->assertFails(
            fn() => $response->assertSee('Buy bread'),
            'Expected the body to contain "Buy bread"'
        );
    }

    public function testAssertDontSeeFailsOnAPresentNeedle(): void
    {
        $app = $this->app();
        $app->routes()->get('/todos', fn(): string => '<ul><li>Buy milk</li></ul>');

        $response = (new TestClient($app))->get('/todos')->request();

        $this->assertFails(
            fn() => $response->assertDontSee('Buy milk'),
            'Expected the body not to contain "Buy milk"'
        );
    }

    public function testAssertHeaderFailsOnAWrongValue(): void
    {
        $app = $this->app();
        $app->routes()->get('/chatty', fn(): Response => Response::html('ok')->pushUrl('/next'));

        $response = (new TestClient($app))->get('/chatty')->request();

        $this->assertFails(
            fn() => $response->assertHeader('HX-Push-Url', '/elsewhere'),
            'Expected header "HX-Push-Url" to be "/elsewhere"'
        );
    }

    public function testAssertHeaderFailsOnAMissingHeader(): void
    {
        $app = $this->app();
        $app->routes()->get('/quiet', fn(): string => 'ok');

        $response = (new TestClient($app))->get('/quiet')->request();

        $this->assertFails(
            fn() => $response->assertHeader('X-Missing', 'yes'),
            'got NULL'
        );
    }

    // ---------------------------------------------------------------- csrf

    public function testItAttachesACsrfTokenToAnAction(): void
    {
        $app = $this->guardedApp();
        $app->registry()->alias('counter', CounterFixture::class);

        $url = $this->actionUrl((string) $app->render(CounterFixture::class), 'post', 'increment');

        (new TestClient($app))->post($url)->request()
            ->assertOk()
            ->assertSee('<span>1</span>');
    }

    public function testItAttachesACsrfTokenToEveryVerbNotJustPost(): void
    {
        $app = $this->guardedApp();
        $app->registry()->alias('verbs', VerbsFixture::class);

        // `peek` declares #[Get], and beforeAction() verifies before dispatch
        // whatever the action's own verb happens to be.
        $url = $this->actionUrl((string) $app->render(VerbsFixture::class), 'get', 'peek');

        (new TestClient($app))->get($url)->request()
            ->assertOk()
            ->assertSee('data-calls="1"');
    }

    public function testATokenTheCallerSetIsLeftAlone(): void
    {
        $app = $this->guardedApp();
        $app->registry()->alias('counter', CounterFixture::class);

        $url = $this->actionUrl((string) $app->render(CounterFixture::class), 'post', 'increment');

        (new TestClient($app))
            ->withHeader('X-CSRF-Token', 'not-the-token')
            ->post($url)
            ->request()
            ->assertForbidden()
            ->assertSee('CSRF');
    }

    public function testNoTokenIsAttachedWhenTheApplicationHasNoGuard(): void
    {
        $client = new TestClient($this->echoingApp());

        // Request::csrfToken() falls back to an empty string, not null.
        $this->assertSame('', $this->decode($client->post('/echo')->request())['token']);
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(TestResponse $response): array
    {
        return (array) json_decode($response->body(), true);
    }
}