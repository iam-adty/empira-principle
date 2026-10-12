<?php

declare(strict_types=1);

namespace Empira\Tests;

use Empira\Application;
use Empira\Attributes\Route;
use Empira\Http\Request;
use Empira\Routing\Controller;
use Empira\Routing\RouteCollection;
use Empira\Routing\RouteParams;
use Empira\State\NullState;
use Empira\View\Layout;
use Empira\View\Region;
use PHPUnit\Framework\TestCase;

/**
 * Attribute-declared routes: discovery, specificity ordering, and `mount()`.
 */
final class RouteDiscoveryTest extends TestCase
{
    private const SECRET = 'a-secret-that-is-long-enough';

    private string $directory;

    private static bool $loaderRegistered = false;

    /** Maps a fully-qualified class name to the file that declares it. */
    private static array $fixturePaths = [];

    protected function setUp(): void
    {
        $this->directory = Temp::dir('empira-discovery-');        self::$fixturePaths = [];
    }

    protected function tearDown(): void
    {
        Temp::remove($this->directory);
        self::$fixturePaths = [];
    }

    /**
     * Make the throwaway fixture classes autoloadable.
     *
     * Discovery deliberately relies on `class_exists()` — it maps a path to a
     * class name and asks the autoloader whether that is real — so these
     * classes have to be reachable. The loader is registered once and reads a
     * static map, because a closure capturing `$this` would be bound to a
     * different test instance by the time it is invoked.
     */
    private static function registerFixtureLoader(): void
    {
        if (self::$loaderRegistered) {
            return;
        }

        self::$loaderRegistered = true;

        spl_autoload_register(static function (string $class): void {
            $path = self::$fixturePaths[$class] ?? null;

            if ($path !== null && is_file($path)) {
                require_once $path;
            }
        });
    }

    private function app(): Application
    {
        return new Application(self::SECRET, new NullState());
    }

    /**
     * Write a throwaway class into the fixture directory.
     *
     * @param string $relative path under the fixture root, e.g. `HomeLayout.php`
     * @param string $class    the fully-qualified class the file declares
     */
    private function write(string $relative, string $class, string $body): void
    {
        $path = $this->directory . '/' . $relative;
        $dir = dirname($path);

        if (!is_dir($dir)) {
            mkdir($dir, 0o777, true);
        }

        $namespace = str_contains($class, '\\') ? substr($class, 0, strrpos($class, '\\')) : '';

        // Fixtures are written without imports so each one reads as a single
        // block; Region is the only type they all need.
        $body = "use Empira\View\\Region;\n\n" . $body;

        file_put_contents($path, sprintf(
            "<?php\n\ndeclare(strict_types=1);\n\nnamespace %s;\n\n%s\n",
            $namespace,
            $body
        ));

        self::$fixturePaths[$class] = $path;
        self::registerFixtureLoader();
    }


    // ---------------------------------------------------------------- discovery

    public function testAClassLevelRouteRendersThatLayout(): void
    {
        $this->write('HomeLayout.php', 'Empira\Fixtures\HomeLayout', <<<'PHP'
            use Empira\Attributes\Route;
            use Empira\View\Layout;

            #[Route('/', name: 'home')]
            final class HomeLayout extends Layout
            {
                protected function regions(): array
                {
                    return ['main' => fn(Region $r) => $r->element('div')->text('home page')];
                }
            }
            PHP);

        $app = $this->app()->discover($this->directory, 'Empira\Fixtures');

        $response = $app->handle(new Request('GET', '/'));

        $this->assertSame(200, $response->status());
        $this->assertStringContainsString('home page', $response->body());
        $this->assertStringContainsString('<html', $response->body());
    }

    public function testAMethodLevelRouteCallsThatMethod(): void
    {
        $this->write('PingController.php', 'Empira\Fixtures\PingController', <<<'PHP'
            use Empira\Attributes\Route;
            use Empira\Http\Response;
            use Empira\Routing\Controller;

            final class PingController extends Controller
            {
                #[Route('/ping', name: 'ping')]
                public function ping(): Response
                {
                    return Response::html('pong');
                }
            }
            PHP);

        $app = $this->app()->discover($this->directory, 'Empira\Fixtures');

        $this->assertSame('pong', $app->handle(new Request('GET', '/ping'))->body());
    }

    public function testARouteIsRepeatableForSeveralUrls(): void
    {
        $this->write('MultiLayout.php', 'Empira\Fixtures\MultiLayout', <<<'PHP'
            use Empira\Attributes\Route;
            use Empira\View\Layout;

            #[Route('/one', name: 'one')]
            #[Route('/two', name: 'two')]
            final class MultiLayout extends Layout
            {
                protected function regions(): array
                {
                    return ['main' => fn(Region $r) => $r->element('div')->text('multi')];
                }
            }
            PHP);

        $app = $this->app()->discover($this->directory, 'Empira\Fixtures');

        $this->assertStringContainsString('multi', $app->handle(new Request('GET', '/one'))->body());
        $this->assertStringContainsString('multi', $app->handle(new Request('GET', '/two'))->body());
    }

    public function testTheAttributeMethodIsHonoured(): void
    {
        $this->write('OnlyPost.php', 'Empira\Fixtures\OnlyPost', <<<'PHP'
            use Empira\Attributes\Route;
            use Empira\Http\Response;
            use Empira\Routing\Controller;

            final class OnlyPost extends Controller
            {
                #[Route('/only-post', methods: ['POST'], name: 'only.post')]
                public function store(): Response
                {
                    return Response::html('stored');
                }
            }
            PHP);

        $app = $this->app()->discover($this->directory, 'Empira\Fixtures');

        $this->assertSame('stored', $app->handle(new Request('POST', '/only-post'))->body());
        $this->assertSame(405, $app->handle(new Request('GET', '/only-post'))->status());
    }

    public function testTheFileSystemOrderDoesNotDecidePrecedence(): void
    {
        // Two files; the general one sorts first on disk. The specific route
        // must still win, or renaming a file would produce a silent 404.
        $this->write('ZTodosLayout.php', 'Empira\Fixtures\ZTodosLayout', <<<'PHP'
            use Empira\Attributes\Route;
            use Empira\Routing\RouteParams;
            use Empira\View\Layout;

            #[Route('/todos/{id}')]
            final class ZTodosLayout extends Layout
            {
                protected function regions(): array
                {
                    return ['main' => fn(Region $r) => $r->element('div')->text('general')];
                }
            }
            PHP);

        $this->write('ATodosNewLayout.php', 'Empira\Fixtures\ATodosNewLayout', <<<'PHP'
            use Empira\Attributes\Route;
            use Empira\View\Layout;

            #[Route('/todos/new')]
            final class ATodosNewLayout extends Layout
            {
                protected function regions(): array
                {
                    return ['main' => fn(Region $r) => $r->element('div')->text('specific')];
                }
            }
            PHP);

        $app = $this->app()->discover($this->directory, 'Empira\Fixtures');

        $this->assertStringContainsString('specific', $app->handle(new Request('GET', '/todos/new'))->body());
        $this->assertStringContainsString('general', $app->handle(new Request('GET', '/todos/7'))->body());
    }

    public function testAPriorityOverridesSpecificity(): void
    {
        $this->write('Pinned.php', 'Empira\Fixtures\Pinned', <<<'PHP'
            use Empira\Attributes\Route;
            use Empira\View\Layout;

            #[Route('/pinned/{id}', priority: 10)]
            final class Pinned extends Layout
            {
                protected function regions(): array
                {
                    return ['main' => fn(Region $r) => $r->element('div')->text('pinned')];
                }
            }
            PHP);

        $this->write('PinnedStatic.php', 'Empira\Fixtures\PinnedStatic', <<<'PHP'
            use Empira\Attributes\Route;
            use Empira\View\Layout;

            #[Route('/pinned/static')]
            final class PinnedStatic extends Layout
            {
                protected function regions(): array
                {
                    return ['main' => fn(Region $r) => $r->element('div')->text('static')];
                }
            }
            PHP);

        $app = $this->app()->discover($this->directory, 'Empira\Fixtures');

        // The general route was pinned ahead of the specific one, on purpose.
        $this->assertStringContainsString('pinned', $app->handle(new Request('GET', '/pinned/static'))->body());
    }

    public function testAFileWhoseClassDoesNotMatchItsPathIsSkipped(): void
    {
        $this->write('GoodLayout.php', 'Empira\Fixtures\GoodLayout', <<<'PHP'
            use Empira\Attributes\Route;
            use Empira\View\Layout;

            #[Route('/good')]
            final class GoodLayout extends Layout
            {
                protected function regions(): array
                {
                    return ['main' => fn(Region $r) => $r->element('div')->text('good')];
                }
            }
            PHP);

        // The path says Mismatched, the file says DoesNotExist. Discovery asks
        // for the class the path implies, which nothing can load.
        $this->write('Mismatched.php', 'Empira\Fixtures\DoesNotExist', <<<'PHP'
            use Empira\View\Layout;

            final class DoesNotExist extends Layout
            {
                protected function regions(): array
                {
                    return [];
                }
            }
            PHP);

        $app = $this->app()->discover($this->directory, 'Empira\Fixtures');

        $routes = $app->routes()->all();

        $this->assertCount(1, $routes, 'the mismatched file registers nothing');
        $this->assertSame('/good', $routes[0]->pattern);
    }

    // ---------------------------------------------------------------- mount()

    public function testMountReceivesRouteParametersByName(): void
    {
        $this->write('TodoLayout.php', 'Empira\Fixtures\TodoLayout', <<<'PHP'
            use Empira\Attributes\Route;
            use Empira\Routing\RouteParams;
            use Empira\View\Layout;

            #[Route('/todos/{id}')]
            final class TodoLayout extends Layout
            {
                public ?string $title = null;

                public function mount(int $id): void
                {
                    $this->title = 'todo #' . $id;
                }

                protected function regions(): array
                {
                    return ['main' => fn(Region $r) => $r->element('div')->text((string) $this->title)];
                }
            }
            PHP);

        $app = $this->app()->discover($this->directory, 'Empira\Fixtures');

        $this->assertStringContainsString('todo #42', $app->handle(new Request('GET', '/todos/42'))->body());
    }

    public function testMountReceivesAutowiredDependencies(): void
    {
        $this->write('InjectedLayout.php', 'Empira\Fixtures\InjectedLayout', <<<'PHP'
            use Empira\Attributes\Route;
            use Empira\Routing\RouteParams;
            use Empira\View\Layout;

            #[Route('/injected')]
            final class InjectedLayout extends Layout
            {
                public ?string $summary = null;

                public function mount(RouteParams $params): void
                {
                    $this->summary = 'injected:' . implode(',', $params->all());
                }

                protected function regions(): array
                {
                    return ['main' => fn(Region $r) => $r->element('div')->text((string) $this->summary)];
                }
            }
            PHP);

        $app = $this->app()->discover($this->directory, 'Empira\Fixtures');

        $this->assertStringContainsString('injected:', $app->handle(new Request('GET', '/injected'))->body());
    }

    public function testALayoutWithoutMountStillRenders(): void
    {
        $this->write('PlainLayout.php', 'Empira\Fixtures\PlainLayout', <<<'PHP'
            use Empira\Attributes\Route;
            use Empira\View\Layout;

            #[Route('/plain')]
            final class PlainLayout extends Layout
            {
                protected function regions(): array
                {
                    return ['main' => fn(Region $r) => $r->element('div')->text('plain')];
                }
            }
            PHP);

        $app = $this->app()->discover($this->directory, 'Empira\Fixtures');

        $this->assertStringContainsString('plain', $app->handle(new Request('GET', '/plain'))->body());
    }

    // ---------------------------------------------------------------- explicit registration

    public function testAnExplicitRegistrarAddsRoutes(): void
    {
        $app = $this->app();

        $app->routes()->get('/explicit', static fn(): string => 'explicit');

        $this->assertSame('explicit', $app->handle(new Request('GET', '/explicit'))->body());
    }

    public function testGroupsStillWorkAlongsideDiscovery(): void
    {
        $app = $this->app();

        $app->routes()->group('/admin', function (RouteCollection $routes): void {
            $routes->get('/stats', static fn(): string => 'stats');
        });

        $this->assertSame('stats', $app->handle(new Request('GET', '/admin/stats'))->body());
    }

    // ---------------------------------------------------------------- names

    public function testMiddlewareOnAnAttributeRuns(): void
    {
        $this->write('Blocker.php', 'Empira\\Fixtures\\Blocker', <<<'PHP'
            use Closure;
            use Empira\Http\Middleware;
            use Empira\Http\Request;
            use Empira\Http\Response;

            final class Blocker implements Middleware
            {
                public function handle(Request $request, Closure $next): Response
                {
                    return Response::html('blocked', 403);
                }
            }
            PHP);

        $this->write('SecuredLayout.php', 'Empira\\Fixtures\\SecuredLayout', <<<'PHP'
            use Empira\Attributes\Route;
            use Empira\View\Layout;

            #[Route('/secret', middleware: [Blocker::class])]
            final class SecuredLayout extends Layout
            {
                protected function regions(): array
                {
                    return ['main' => fn(Region $r) => $r->element('div')->text('shh')];
                }
            }
            PHP);

        $app = $this->app()->discover($this->directory, 'Empira\\Fixtures');

        $response = $app->handle(new Request('GET', '/secret'));

        $this->assertSame(403, $response->status());
        $this->assertSame('blocked', $response->body());
    }

    public function testAbstractAndNonInstantiableClassesAreSkipped(): void
    {
        $this->write('AbstractPage.php', 'Empira\\Fixtures\\AbstractPage', <<<'PHP'
            use Empira\Attributes\Route;
            use Empira\View\Layout;

            #[Route('/abstract')]
            abstract class AbstractPage extends Layout
            {
                protected function regions(): array
                {
                    return ['main' => fn(Region $r) => $r->element('div')->text('abstract')];
                }
            }
            PHP);

        $this->write('PrivatePage.php', 'Empira\\Fixtures\\PrivatePage', <<<'PHP'
            use Empira\Attributes\Route;
            use Empira\View\Layout;

            #[Route('/private')]
            final class PrivatePage extends Layout
            {
                private function __construct()
                {
                }

                protected function regions(): array
                {
                    return ['main' => fn(Region $r) => $r->element('div')->text('private')];
                }
            }
            PHP);

        $app = $this->app()->discover($this->directory, 'Empira\\Fixtures');

        $this->assertCount(0, $app->routes()->all(), 'nothing instantiable was declared');
        $this->assertSame(404, $app->handle(new Request('GET', '/abstract'))->status());
        $this->assertSame(404, $app->handle(new Request('GET', '/private'))->status());
    }

    public function testANameIsDerivedWhenTheAttributeOmitsOne(): void
    {
        $this->write('Pages/HomeLayout.php', 'App\Pages\HomeLayout', <<<'PHP'
            use Empira\Attributes\Route;
            use Empira\View\Layout;

            #[Route('/derived')]
            final class HomeLayout extends Layout
            {
                protected function regions(): array
                {
                    return ['main' => fn(Region $r) => $r->element('div')->text('derived')];
                }
            }
            PHP);

        $this->write('Controllers/TodoController.php', 'App\Controllers\TodoController', <<<'PHP'
            use Empira\Attributes\Route;

            final class TodoController
            {
                #[Route('/todos')]
                public function index(): string
                {
                    return 'index';
                }
            }
            PHP);

        $app = $this->app()->discover($this->directory, 'App');

        $this->assertTrue($app->routes()->has('pages.home'), 'layout: namespace + class, suffix dropped');
        $this->assertTrue($app->routes()->has('controllers.todo.index'), 'method: appends the method name');
    }

    public function testExplicitDataOverridesRouteParameters(): void
    {
        $this->write('EchoLayout.php', 'Empira\\Fixtures\\EchoLayout', <<<'PHP'
            use Empira\Attributes\Route;
            use Empira\View\Layout;

            #[Route('/echo/{id}')]
            final class EchoLayout extends Layout
            {
                protected function regions(): array
                {
                    return ['main' => fn(Region $r) => $r->element('div')->text((string) $r->data('id', ''))];
                }
            }
            PHP);

        $app = $this->app()->discover($this->directory, 'Empira\\Fixtures');

        // The matched `{id}` reaches the page as data...
        $this->assertStringContainsString('7', $app->handle(new Request('GET', '/echo/7'))->body());

        // ...but a handler that passes it explicitly is telling the truth.
        $body = $app->view('Empira\\Fixtures\\EchoLayout', ['id' => 'override'], new Request('GET', '/echo/7'))->body();

        $this->assertStringContainsString('override', $body);
        $this->assertStringNotContainsString('>7<', $body);
    }

    public function testAnExplicitNameWins(): void
    {
        $this->write('NamedLayout.php', 'Empira\Fixtures\NamedLayout', <<<'PHP'
            use Empira\Attributes\Route;
            use Empira\View\Layout;

            #[Route('/named', name: 'explicit.name')]
            final class NamedLayout extends Layout
            {
                protected function regions(): array
                {
                    return ['main' => fn(Region $r) => $r->element('div')->text('named')];
                }
            }
            PHP);

        $app = $this->app()->discover($this->directory, 'Empira\Fixtures');

        $this->assertTrue($app->routes()->has('explicit.name'));
    }

    public function testOneRouteCanAnswerSeveralVerbs(): void
    {
        $this->write('SubmitController.php', 'Empira\Fixtures\SubmitController', <<<'PHP'
            use Empira\Attributes\Route;
            use Empira\Http\Response;
            use Empira\Routing\Controller;

            final class SubmitController extends Controller
            {
                #[Route('/submit', methods: ['GET', 'POST'], name: 'submit')]
                public function submit(): Response
                {
                    return Response::html('done');
                }
            }
            PHP);

        $app = $this->app()->discover($this->directory, 'Empira\Fixtures');

        $this->assertSame('done', $app->handle(new Request('GET', '/submit'))->body());
        $this->assertSame('done', $app->handle(new Request('POST', '/submit'))->body());
        $this->assertSame(405, $app->handle(new Request('PUT', '/submit'))->status());
    }

    public function testTwoRoutesMayShareANameOnlyWhenTheyDifferByVerb(): void
    {
        $routes = new RouteCollection();
        $routes->get('/a', fn(): string => '', 'shared');
        $routes->post('/a', fn(): string => '', 'shared');

        $this->assertTrue($routes->has('shared'));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('already used');

        $routes->get('/b', fn(): string => '', 'shared');
    }

    public function testRouteParametersDoNotLeakIntoTheNextRequest(): void
    {
        $this->write('TodoLayout.php', 'Empira\\Fixtures\\TodoLayout', <<<'PHP'
            use Empira\Attributes\Route;
            use Empira\View\Layout;

            #[Route('/todos/{id}')]
            final class TodoLayout extends Layout
            {
                protected function regions(): array
                {
                    return ['main' => fn(Region $r) => $r->element('div')->text('todo')];
                }
            }
            PHP);

        $app = $this->app()->discover($this->directory, 'Empira\\Fixtures');

        $this->assertSame(200, $app->handle(new Request('GET', '/todos/7'))->status());
        $this->assertSame('7', $app->container()->get(RouteParams::class)->get('id'));

        // The second request matches nothing. A leaked `id` would still be bound.
        $app->handle(new Request('GET', '/nope'));

        $this->assertSame('', $app->container()->get(RouteParams::class)->get('id', ''));
    }
}
