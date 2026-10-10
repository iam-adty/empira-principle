<?php

declare(strict_types=1);

namespace IamAdty\Empira\Tests;

use IamAdty\Empira\Application;
use IamAdty\Empira\Container\Container;
use IamAdty\Empira\Http\Middleware;
use IamAdty\Empira\Http\Request;
use IamAdty\Empira\Http\Response;
use IamAdty\Empira\Routing\Controller;
use IamAdty\Empira\Routing\HttpException;
use IamAdty\Empira\Routing\RouteCollection;
use IamAdty\Empira\Routing\UrlGenerator;
use IamAdty\Empira\State\NullState;
use PHPUnit\Framework\TestCase;

/**
 * Named routes, path parameters, middleware and reverse routing.
 */
final class RoutingTest extends TestCase
{
    private const SECRET = 'a-secret-that-is-long-enough';

    private function app(): Application
    {
        return new Application(self::SECRET, new NullState());
    }

    /**
     * @param callable(RouteCollection): void $define
     */
    private function routed(callable $define, ?Request $request = null): Response
    {
        $app = $this->app();
        $define($app->routes());

        return $app->handle($request ?? new Request('GET', '/'));
    }

    // ---------------------------------------------------------------- matching

    public function testAClosureRouteIsMatchedAndCalled(): void
    {
        $response = $this->routed(
            fn(RouteCollection $r) => $r->get('/hello', fn(): string => 'hi there'),
            new Request('GET', '/hello')
        );

        $this->assertSame(200, $response->status());
        $this->assertSame('hi there', $response->body());
    }

    public function testTheFirstMatchingRouteWins(): void
    {
        $response = $this->routed(function (RouteCollection $r): void {
            $r->get('/todos/new', fn(): string => 'new');
            $r->get('/todos/{id}', fn(): string => 'show');
        }, new Request('GET', '/todos/new'));

        $this->assertSame('new', $response->body());
    }

    public function testPathParametersReachTheHandler(): void
    {
        $response = $this->routed(
            fn(RouteCollection $r) => $r->get('/todos/{id}', fn(int $id): string => 'todo ' . $id),
            new Request('GET', '/todos/7')
        );

        $this->assertSame('todo 7', $response->body());
    }

    public function testParameterTypesComeFromDefaults(): void
    {
        $seen = null;

        $this->routed(function (RouteCollection $r) use (&$seen): void {
            $r->get('/items/{id}/{slug}', function (mixed $id, mixed $slug) use (&$seen): string {
                $seen = [$id, $slug];

                return 'ok';
            }, defaults: ['id' => 0]);
        }, new Request('GET', '/items/42/hello'));

        $this->assertSame([42, 'hello'], $seen, 'id should arrive as int from its int default');
    }

    public function testOptionalParametersMayBeOmitted(): void
    {
        $response = $this->routed(function (RouteCollection $r): void {
            $r->get('/posts/{page?}', fn(?string $page): string => 'page=' . ($page ?? 'default'));
        }, new Request('GET', '/posts'));

        $this->assertSame('page=default', $response->body());

        $response = $this->routed(function (RouteCollection $r): void {
            $r->get('/posts/{page?}', fn(?string $page): string => 'page=' . ($page ?? 'default'));
        }, new Request('GET', '/posts/3'));

        $this->assertSame('page=3', $response->body());
    }

    public function testConstraintsAreEnforced(): void
    {
        $response = $this->routed(function (RouteCollection $r): void {
            $r->get('/n/{id:[0-9]+}', fn(): string => 'numeric');
            $r->get('/n/{other}', fn(): string => 'fallback');
        }, new Request('GET', '/n/123'));

        $this->assertSame('numeric', $response->body());

        $response = $this->routed(function (RouteCollection $r): void {
            $r->get('/n/{id:[0-9]+}', fn(): string => 'numeric');
            $r->get('/n/{other}', fn(): string => 'fallback');
        }, new Request('GET', '/n/abc'));

        $this->assertSame('fallback', $response->body());
    }

    public function testATrailingSlashIsOptional(): void
    {
        $response = $this->routed(
            fn(RouteCollection $r) => $r->get('/about', fn(): string => 'about'),
            new Request('GET', '/about/')
        );

        $this->assertSame('about', $response->body());
    }

    public function testTheRootPathIsRoutable(): void
    {
        $response = $this->routed(
            fn(RouteCollection $r) => $r->get('/', fn(): string => 'home'),
            new Request('GET', '/')
        );

        $this->assertSame('home', $response->body());
    }

    public function testUnmatchedPathsFallThroughTo404(): void
    {
        $response = $this->routed(
            fn(RouteCollection $r) => $r->get('/known', fn(): string => 'yes'),
            new Request('GET', '/unknown')
        );

        $this->assertSame(404, $response->status());
    }

    public function testTheWrongMethodIs405WithAnAllowHeader(): void
    {
        $response = $this->routed(function (RouteCollection $r): void {
            $r->get('/items', fn(): string => 'list');
            $r->post('/items', fn(): string => 'create');
        }, new Request('DELETE', '/items'));

        $this->assertSame(405, $response->status());

        $allow = explode(', ', $response->headers()['Allow']);
        sort($allow);

        $this->assertSame(['GET', 'POST'], $allow);
    }

    public function testAnyMatchesEveryMethod(): void
    {
        foreach (['GET', 'POST', 'PUT', 'DELETE'] as $method) {
            $response = $this->routed(
                fn(RouteCollection $r) => $r->any('/any', fn(): string => $method),
                new Request($method, '/any')
            );

            $this->assertSame(200, $response->status(), $method);
        }
    }

    // ---------------------------------------------------------------- groups

    public function testGroupsShareAPrefix(): void
    {
        $response = $this->routed(function (RouteCollection $r): void {
            $r->group('/api', function (RouteCollection $r): void {
                $r->get('/users', fn(): string => 'users');
                $r->get('/posts', fn(): string => 'posts');
            });
        }, new Request('GET', '/api/users'));

        $this->assertSame('users', $response->body());
    }

    public function testGroupPrefixesNestAndDoNotLeak(): void
    {
        $routes = new RouteCollection();

        $routes->group('/a', function (RouteCollection $r): void {
            $r->group('/b', fn(RouteCollection $r) => $r->get('/c', fn(): string => 'deep'));
            $r->get('/shallow', fn(): string => 'shallow');
        });

        $this->assertNotNull($routes->match('GET', '/a/b/c'));
        $this->assertNotNull($routes->match('GET', '/a/shallow'));
        $this->assertNull($routes->match('GET', '/b/c'), 'the inner prefix must not escape its group');
        $this->assertNull($routes->match('GET', '/shallow'));
    }

    public function testGroupDefaultsTypeEverySharedParameter(): void
    {
        $seen = null;

        $this->routed(function (RouteCollection $r) use (&$seen): void {
            $r->group('/users/{id}', function (RouteCollection $r) use (&$seen): void {
                $r->get('/posts', function (mixed $id) use (&$seen): string {
                    $seen = $id;

                    return 'ok';
                });
            }, defaults: ['id' => 0]);
        }, new Request('GET', '/users/9/posts'));

        $this->assertSame(9, $seen);
    }

    // ---------------------------------------------------------------- controllers

    public function testAControllerMethodIsResolvedAndCalled(): void
    {
        $response = $this->routed(
            fn(RouteCollection $r) => $r->get('/greet/{name}', [GreetingController::class, 'greet'], 'greet'),
            new Request('GET', '/greet/ada')
        );

        $this->assertSame('hello ada', $response->body());
    }

    public function testAnInvokableControllerIsResolvedFromItsClassName(): void
    {
        $response = $this->routed(
            fn(RouteCollection $r) => $r->get('/ping', PingController::class),
            new Request('GET', '/ping')
        );

        $this->assertSame('pong', $response->body());
    }

    public function testAControllerCanReturnAResponse(): void
    {
        $response = $this->routed(
            fn(RouteCollection $r) => $r->get('/json', fn(): Response => Response::json(['a' => 1])),
            new Request('GET', '/json')
        );

        $this->assertSame('{"a":1}', $response->body());
        $this->assertSame('application/json', $response->headers()['Content-Type']);
    }

    public function testAControllerCanThrowAnHttpException(): void
    {
        $response = $this->routed(
            fn(RouteCollection $r) => $r->get('/gone', fn(): string => throw HttpException::notFound('nothing here')),
            new Request('GET', '/gone')
        );

        $this->assertSame(404, $response->status());
        $this->assertSame('nothing here', $response->body());
    }

    public function testHandlerDependenciesAreAutowired(): void
    {
        $response = $this->routed(
            fn(RouteCollection $r) => $r->get('/dep', fn(Container $container): string => $container::class),
            new Request('GET', '/dep')
        );

        $this->assertSame(Container::class, $response->body());
    }

    public function testTheContainerCanBuildAUriFromARouteName(): void
    {
        $app = $this->app();

        $app->routes()->get('/target/{id}', fn(): string => '', 'target');
        $app->routes()->get('/link', fn(UrlGenerator $urls): string => $urls->to('target', ['id' => 5]));

        $this->assertSame('/target/5', $app->handle(new Request('GET', '/link'))->body());
    }

    // ---------------------------------------------------------------- middleware

    public function testMiddlewareWrapsTheHandler(): void
    {
        $order = [];

        $app = $this->app();
        $app->routes()->get('/m', function () use (&$order): string {
            $order[] = 'handler';

            return 'done';
        });

        $app->use(new RecordingMiddleware('first', $order), new RecordingMiddleware('second', $order));

        $app->handle(new Request('GET', '/m'));

        // Onion: first is outermost, so it unwinds last.
        $this->assertSame(
            ['first:in', 'second:in', 'handler', 'second:out', 'first:out'],
            $order
        );
    }

    public function testMiddlewareCanShortCircuit(): void
    {
        $app = $this->app();
        $app->routes()->get('/m', fn(): string => 'never');
        $app->use(new class () implements Middleware {
            public function handle(Request $request, \Closure $next): Response
            {
                return Response::html('blocked')->setStatus(401);
            }
        });

        $response = $app->handle(new Request('GET', '/m'));

        $this->assertSame(401, $response->status());
        $this->assertSame('blocked', $response->body());
    }

    public function testMiddlewareCanModifyTheResponse(): void
    {
        $app = $this->app();
        $app->routes()->get('/m', fn(): Response => Response::html('body'));
        $app->use(new class () implements Middleware {
            public function handle(Request $request, \Closure $next): Response
            {
                return $next($request)->withHeader('X-Middleware', 'yes');
            }
        });

        $response = $app->handle(new Request('GET', '/m'));

        $this->assertSame('yes', $response->headers()['X-Middleware']);
    }

    public function testGroupMiddlewareAppliesToThatGroupOnly(): void
    {
        $app = $this->app();
        $app->routes()->group('/open', fn(RouteCollection $r) => $r->get('', fn(): string => 'open'));
        $app->routes()->group('/closed', fn(RouteCollection $r) => $r->get('', fn(): string => 'closed'), middleware: [
            new class () implements Middleware {
                public function handle(Request $request, \Closure $next): Response
                {
                    return Response::html('denied')->setStatus(403);
                }
            },
        ]);

        $this->assertSame('open', $app->handle(new Request('GET', '/open'))->body());
        $this->assertSame(403, $app->handle(new Request('GET', '/closed'))->status());
    }

    // ---------------------------------------------------------------- url generator

    public function testUrlsAreBuiltFromNames(): void
    {
        $app = $this->app();
        $app->routes()->get('/todos/{id}', fn(): string => '', 'todos.show');

        $this->assertSame('/todos/7', $app->url()->to('todos.show', ['id' => 7]));
    }

    public function testExtraParametersBecomeAQueryString(): void
    {
        $app = $this->app();
        $app->routes()->get('/search', fn(): string => '', 'search');

        $this->assertSame('/search?q=hi&page=2', $app->url()->to('search', ['q' => 'hi', 'page' => 2]));
    }

    public function testOptionalParametersAreOmittedFromTheUrl(): void
    {
        $app = $this->app();
        $app->routes()->get('/posts/{page?}', fn(): string => '', 'posts');

        $this->assertSame('/posts', $app->url()->to('posts'));
        $this->assertSame('/posts/3', $app->url()->to('posts', ['page' => 3]));
    }

    public function testMissingParametersAreReportedClearly(): void
    {
        $app = $this->app();
        $app->routes()->get('/todos/{id}', fn(): string => '', 'todos.show');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('needs a value for "id"');

        $app->url()->to('todos.show');
    }

    public function testAnUnknownRouteNameIsReportedClearly(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('No route named "nope"');

        $this->app()->url()->to('nope');
    }

    public function testDuplicateRouteNamesAreRefused(): void
    {
        $routes = new RouteCollection();
        $routes->get('/a', fn(): string => '', 'dup');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('already used');

        $routes->get('/b', fn(): string => '', 'dup');
    }

    public function testUrlValuesAreUrlEncoded(): void
    {
        $app = $this->app();
        $app->routes()->get('/tags/{tag}', fn(): string => '', 'tags');

        $this->assertSame('/tags/a%2Fb%20c', $app->url()->to('tags', ['tag' => 'a/b c']));
    }

    // ---------------------------------------------------------------- coexistence

    public function testComponentTrafficStillTakesPrecedenceOverRoutes(): void
    {
        $app = $this->app();
        $app->registry()->alias('counter', Fixtures\CounterFixture::class);
        $app->routes()->get('/empira/anything', fn(): string => 'should not run');

        $html = (string) $app->render(Fixtures\CounterFixture::class)->render();
        preg_match('/hx-post="([^"]*increment)"/', $html, $matches);
        $url = html_entity_decode($matches[1], ENT_QUOTES);

        $response = $app->handle(new Request('POST', $url, headers: ['HX-Request' => 'true']));

        $this->assertSame(200, $response->status());
        $this->assertStringNotContainsString('should not run', $response->body());
    }

    public function testTheFallbackStillRunsWhenNoRouteMatches(): void
    {
        $app = $this->app();
        $app->routes()->get('/known', fn(): string => 'known');
        $app->fallback(fn(Request $request): string => 'fallback for ' . $request->path());

        $this->assertSame('fallback for /other', $app->handle(new Request('GET', '/other'))->body());
    }
}

// ------------------------------------------------------------------ fixtures

final class RecordingMiddleware implements Middleware
{
    /**
     * @param list<string> $log
     */
    public function __construct(
        private readonly string $label,
        private array &$log,
    ) {
    }

    public function handle(Request $request, \Closure $next): Response
    {
        $this->log[] = $this->label . ':in';

        $response = $next($request);

        $this->log[] = $this->label . ':out';

        return $response;
    }
}

final class GreetingController extends Controller
{
    public function greet(string $name): Response
    {
        return Response::html('hello ' . $name);
    }
}

final class PingController extends Controller
{
    public function __invoke(): Response
    {
        return Response::html('pong');
    }
}