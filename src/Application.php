<?php

declare(strict_types=1);

namespace IamAdty\Empira;

use IamAdty\Empira\Config\Config;
use IamAdty\Empira\Auth\Auth;
use IamAdty\Empira\Auth\Guard;
use IamAdty\Empira\Container\Container;
use IamAdty\Empira\Data\Connection;
use IamAdty\Empira\Data\ConnectionFactory;
use IamAdty\Empira\Data\Db;
use IamAdty\Empira\Html;
use IamAdty\Empira\Http\Format;
use IamAdty\Empira\Http\Middleware;
use IamAdty\Empira\Http\Pipeline;
use IamAdty\Empira\Http\Request;
use IamAdty\Empira\Http\Response;
use IamAdty\Empira\Http\Session;
use IamAdty\Empira\Routing\HttpException;
use IamAdty\Empira\Routing\RouteCollection;
use IamAdty\Empira\Routing\RouteParams;
use IamAdty\Empira\Routing\RouteRegistrar;
use IamAdty\Empira\Routing\RouteRegistry;
use IamAdty\Empira\Routing\UrlGenerator;
use IamAdty\Empira\Security\Csrf;
use IamAdty\Empira\State\NullState;
use IamAdty\Empira\State\StateStore;
use IamAdty\Empira\Validation\ValidationException;

/**
 * The framework's front door.
 *
 * Wire it up once in `public/index.php`:
 *
 *     $app = new Application($_ENV['APP_SECRET']);
 *     $app->registry()->alias('counter', Counter::class);
 *
 *     $app->fallback(function (Request $request, Context $context) {
 *         if ($request->path() !== '/') {
 *             return 'Not found';
 *         }
 *
 *         return '<h1>Demo</h1>' . $context->registry()->... ;
 *     });
 *
 *     $app->send();
 *
 * `send()` intercepts action URLs before the fallback ever sees them, so
 * component traffic and ordinary page traffic coexist on one endpoint.
 *
 * @see \IamAdty\Empira\Component
 * @see \IamAdty\Empira\Registry
 */
final class Application
{
    private Registry $registry;
    private TokenCodec $tokens;
    private Router $router;
    private ?Csrf $csrf;

    private RouteCollection $routes;
    private Container $container;
    private UrlGenerator $urls;
    private Pipeline $pipeline;

    /** @var (callable(Request, Context): (string|Response|null))|null */
    private $fallback = null;

    /** @var (callable(\Throwable, Request, Response): Response)|null */
    private $errorHandler = null;

    /** @var (callable(int, string): (Element|Raw|string))|null */
    private $errorView = null;

    private ?Context $context = null;

    /** The format negotiated for the request in flight. */
    private ?Format $format = null;

    /** The parameters the current route matched. */
    private ?RouteParams $params = null;

    private ?\IamAdty\Empira\Data\Db $db = null;

    /** @var list<\IamAdty\Empira\Auth\Guard> */
    private array $guards = [];

    public function __construct(
        string $secret,
        private readonly StateStore $state = new NullState(),
        string $baseUrl = '/empira',
        ?Csrf $csrf = null,
        ?Container $container = null,
    ) {
        $this->tokens = new TokenCodec($secret);
        $this->registry = new Registry();
        $this->router = new Router($baseUrl);
        $this->csrf = $csrf;
        $this->routes = new RouteCollection();
        $this->container = $container ?? new Container();
        $this->urls = new UrlGenerator($this->routes);
        $this->pipeline = new Pipeline($this->container);

        $this->container->instance(self::class, $this);
        $this->container->instance(Container::class, $this->container);
        $this->container->instance(StateStore::class, $state);
        $this->container->instance(Registry::class, $this->registry);
        $this->container->instance(TokenCodec::class, $this->tokens);
        $this->container->instance(RouteCollection::class, $this->routes);
        $this->container->instance(UrlGenerator::class, $this->urls);

        if ($csrf !== null) {
            $this->container->instance(Csrf::class, $csrf);
        }
    }

    /**
     * Wire authentication.
     *
     *     $app->auth(
     *         new SessionGuard($session, $users),
     *         new BearerGuard($users->byApiToken(...)),
     *     );
     *
     * An `Auth` is then built and bound **per request**, not once at boot. That
     * is deliberate: a singleton would need resetting before every dispatch,
     * and a reset someone forgets is a request that runs as the user before
     * it. A fresh instance cannot remember the request before it, so there is
     * nothing to forget.
     *
     * Guards are consulted in order and the first user wins.
     */
    public function auth(Guard ...$guards): self
    {
        $this->guards = array_values($guards);

        return $this;
    }

    /**
     * The authentication guards, whether or not any have been registered.
     *
     * @return list<Guard>
     */
    public function guards(): array
    {
        return $this->guards;
    }

    /**
     * Wire a database and make `Db` autowirable from here on.
     *
     * Before this, asking the container for `Db` throws: its constructor wants
     * a `Connection`, which wants a `PDO`, and nothing about a `PDO` can be
     * guessed. This is the one place the DSN is built, so it is the one place a
     * driver name is turned into a real handle.
     *
     *     $app->database(['driver' => 'sqlite', 'database' => ':memory:']);
     *
     *     // …now in any component, controller or middleware:
     *     public function __construct(private readonly Db $db) {}
     *
     * Configuration is taken as an argument or, with none, from `db.*` in the
     * container's `Config`. Nothing is connected until this is called: an
     * application that never asks for a database never opens one.
     */
    public function database(?array $config = null): self
    {
        $items = $config;

        if ($items === null) {
            $bound = $this->container->bound(Config::class)
                ? $this->container->get(Config::class)
                : null;

            $items = $bound instanceof Config ? ($bound->array('db') ?: null) : null;
        }

        $connection = ConnectionFactory::make(new Config($items ?? []));
        $db = new Db($connection);

        $this->db = $db;
        $this->container->instance(Connection::class, $connection);
        $this->container->instance(Db::class, $db);

        return $this;
    }

    /**
     * The database, once {@see Application::database()} has been called.
     */
    public function db(): Db
    {
        if ($this->db === null) {
            throw new \LogicException(
                'No database is configured. Call $app->database() during boot before asking for one.'
            );
        }

        return $this->db;
    }

    /**
     * The request's session, for flash messages and the like.
     */
    public function session(): Session
    {
        return $this->container->get(Session::class);
    }

    public function registry(): Registry
    {
        return $this->registry;
    }

    public function routes(): RouteCollection
    {
        return $this->routes;
    }

    public function container(): Container
    {
        return $this->container;
    }

    /**
     * Reverse routing: build URLs from route names.
     */
    public function url(): UrlGenerator
    {
        return $this->urls;
    }

    public function router(): Router
    {
        return $this->router;
    }

    public function csrf(): ?Csrf
    {
        return $this->csrf;
    }

    /**
     * Render a component for embedding in a page.
     *
     * Returns markup, not a string, so it can be appended straight into a
     * parent element without being escaped.
     *
     * @param class-string<Component> $class
     * @param array<string, mixed>    $props
     */
    public function render(string $class, array $props = []): Element|Raw
    {
        /** @var Component $component */
        $component = new $class($this->context ?? $this->makeContext(), $props);

        return $component->initialise($props);
    }

    /**
     * Handle everything that is not a component action.
     *
     * @param callable(Request, Context): (string|Response|null) $handler
     */
    public function fallback(callable $handler): self
    {
        $this->fallback = $handler;

        return $this;
    }

    /**
     * @param callable(\Throwable, Request, Response): Response $handler
     */
    public function onException(callable $handler): self
    {
        $this->errorHandler = $handler;

        return $this;
    }

    public function makeContext(?Request $request = null): Context
    {
        return new Context(
            request: $request ?? Request::capture(),
            response: new Response(),
            registry: $this->registry,
            tokens: $this->tokens,
            state: $this->state,
            baseUrl: $this->router->baseUrl(),
            csrf: $this->csrf,
            container: $this->container,
        );
    }

    /**
     * Route a request and produce a response.
     *
     * Component action traffic is matched first, because those URLs live under
     * a reserved prefix; then named routes, then the fallback.
     */
    public function handle(Request $request): Response
    {
        // An Application outlives the request in a long-running worker, and the
        // session is the one piece of state where that outlives *wrongly* — see
        // Session::detach(), which is why the reason lives there.
        Session::detach();

        // Likewise the connection: one is opened at boot and kept, so its
        // statement log would otherwise grow for as long as the process lives.
        $this->db?->connection()->forgetQueries();

        $context = $this->makeContext($request);
        $this->context = $context;

        // Negotiated once, up front: `Request` is immutable, so the format is a
        // property of this dispatch rather than something mutated onto it later.
        $this->format = $request->accepts('application/json', ['text/html'])
            ? Format::Json
            : Format::Html;

        // Reset per request: an Application instance outlives the request in tests and
        // in long-running workers, and `/todos/7` must not leave its `id` behind
        // for the next request that matches nothing.
        $this->params = new RouteParams();

        $this->container->instance(Request::class, $request);
        $this->container->instance(Response::class, $context->response);
        $this->container->instance(Context::class, $context);
        $this->container->instance(Format::class, $this->format);
        $this->container->instance(RouteParams::class, $this->params);

        // Per request, not per application: see auth().
        $this->container->instance(Auth::class, new Auth($this->guards, $request));

        // Per request for the same reason, and it is not enough to reset the
        // object — the latch inside it decides whether the flash buckets rotate,
        // and a latched Session is a Session that will never rotate again.
        $this->container->instance(Session::class, new Session());

        try {
            $response = $this->pipeline->send(
                $request,
                fn(Request $request): Response => $this->dispatch($request, $context)
            );
        } catch (\Throwable $e) {
            $response = $this->renderException($e, $request, $context->response);
        } finally {
            // A context outliving its request is how `view()` outside a dispatch
            // ends up mutating a response that has already been sent.
            $this->context = null;
        }

        return $response;
    }

    /**
     * The format negotiated for the request currently being handled.
     *
     * HTML unless the client explicitly asked for JSON — see {@see Format}.
     */
    public function format(): Format
    {
        return $this->format ?? Format::Html;
    }

    /**
     * Render an error the way this request's client can read it.
     *
     * Every failure leaves through here in one shape: HTML for a browser or
     * htmx, a JSON envelope for a client that asked for JSON. A client should
     * never have to parse two different error bodies depending on what went
     * wrong.
     */
    private function errorResponse(int $status, string $message, array $headers = [], ?array $errors = null): Response
    {
        $response = new Response();

        foreach ($headers as $name => $value) {
            $response->withHeader($name, (string) $value);
        }

        if ($this->format() === Format::Json) {
            $error = ['status' => $status, 'message' => $message];

            if ($errors !== null && $errors !== []) {
                $error['errors'] = $errors;
            }

            return $response
                ->withHeader('Content-Type', 'application/json')
                ->setStatus($status)
                ->setBody(Html::json(['error' => $error]));
        }

        return $response->setStatus($status)->setBody($message);
    }

    /**
     * Find something to handle this request and normalise what it returns into
     * a {@see Response}.
     */
    private function dispatch(Request $request, Context $context): Response
    {
        $action = $this->router->match($request);

        if ($action !== null) {
            return $this->router->dispatch($context, $action);
        }

        $match = $this->routes->match($request->method, $request->path());

        if ($match !== null) {
            // Available to the handler by type, and merged into view() below.
            $this->params = new RouteParams($match->parameters);
            $this->container->instance(RouteParams::class, $this->params);

            $handler = fn(Request $request): Response => $this->toResponse(
                $this->container->call($this->resolveHandler($match->handler()), $match->parameters),
                $context->response
            );

            return $match->route->middleware === []
                ? $handler($request)
                : (new Pipeline($this->container, $match->route->middleware))->send($request, $handler);
        }

        $allowed = $this->routes->allowedMethods($request->path());

        if ($allowed !== []) {
            throw new MethodNotAllowedException(
                sprintf('%s is not allowed on %s.', $request->method, $request->path()),
                $allowed
            );
        }

        if ($this->fallback === null) {
            throw HttpException::notFound();
        }

        return $this->toResponse(($this->fallback)($request, $context), $context->response);
    }

    /**
     * Turn a declared handler into something callable.
     *
     * A controller may be written as `[TodoController::class, 'show']` or, when
     * it is invokable, as the bare class name. A **Layout** is neither: naming
     * one directly is the "this URL renders this page" case, so it is rendered
     * here rather than called.
     */
    private function resolveHandler(mixed $handler): callable
    {
        if (is_string($handler) && is_subclass_of($handler, View\Layout::class)) {
            return fn(): Response => $this->view($handler);
        }

        if (is_string($handler) && class_exists($handler)) {
            return $this->container->get($handler);
        }

        if (is_array($handler) && count($handler) === 2 && is_string($handler[0])) {
            return [$this->container->get($handler[0]), $handler[1]];
        }

        return $handler;
    }

    /**
     * Accept whatever a handler returned as a real response.
     */
    private function toResponse(mixed $result, Response $response): Response
    {
        if ($result instanceof Response) {
            return $result;
        }

        $body = match (true) {
            $result instanceof Element => $result->render(),
            $result instanceof Raw => $result->get(),
            $result instanceof \Stringable => (string) $result,
            $result === null => '',
            default => (string) $result,
        };

        return $response->setStatus(200)->setBody($body);
    }

    /**
     * Render a page through a layout.
     *
     * The same call answers a browser and htmx: a normal visit gets the whole
     * document, a request carrying `HX-Request` gets just the region htmx
     * named. That is what lets a route serve both without the handler knowing
     * which one it is answering.
     *
     * Parameters the current route matched are merged **beneath** `$data`, so an
     * explicit value always wins. If the layout defines `mount()`, it is called
     * with them — by name, with class-typed arguments autowired — which is how
     * a page receives `int $id` and a repository without a `defaults` workaround.
     *
     * @param class-string<View\Layout> $layout
     * @param array<string, mixed>       $data
     */
    public function view(string $layout, array $data = [], ?Request $request = null): Response
    {
        $request ??= $this->context?->request ?? Request::capture();

        $params = $this->params ?? new RouteParams();
        $merged = $params->merge($data);

        $markup = new $layout(
            app: $this,
            request: $request,
            data: $merged,
            session: $this->container->get(Session::class),
            csrf: $this->csrf,
        );

        $this->mountLayout($markup, $merged);

        $body = $markup->render();

        return ($this->context?->response ?? new Response())
            ->setStatus(200)
            ->setBody($body instanceof Element ? $body->render() : $body->get());
    }

    /**
     * Give a layout its parameters, if it asks for them.
     *
     * `mount()` is deliberately **not** declared on {@see View\Layout}: a fixed
     * signature there would make every override with arguments a fatal
     * signature violation. Declaring it here by reflection keeps each page free
     * to declare exactly the parameters it needs.
     *
     * @param array<string, mixed> $params
     */
    private function mountLayout(object $layout, array $params): void
    {
        if (!method_exists($layout, 'mount')) {
            return;
        }

        // `Container::call()` needs a callable, so a non-public `mount()` would
        // otherwise surface as a bare TypeError about array vs callable.
        if (!(new \ReflectionMethod($layout, 'mount'))->isPublic()) {
            throw new \LogicException(sprintf(
                '%s::mount() must be public to receive route parameters.',
                $layout::class
            ));
        }

        $this->container->call([$layout, 'mount'], $params);
    }

    /**
     * Add middleware that runs for every request.
     *
     * Pass the **class-string** wherever you can. The pipeline rebuilds a
     * class-string for every request, so it picks up that request's `Format`,
     * `Auth` and container bindings. An instance is built once at boot and reused
     * unchanged, which under a long-running worker freezes whatever the first
     * request happened to hold: a `Format` pinned to HTML answers a JSON client
     * with a page, and an `Auth` built for request one decides who request two
     * is. Reachable, but only if you hand the pipeline an object.
     *
     * @param class-string<Middleware>|Middleware ...$layers
     */
    public function use(string|Middleware ...$layers): self
    {
        $this->pipeline->push(...$layers);

        return $this;
    }

    /**
 * Find the routes declared with `#[Route]` under a directory.
     *
     * A **class-level** attribute means "this URL renders this class" — normally a
     * Layout, which the framework renders for you. A **method-level** one names
     * the method to call.
     *
     *     $app->discover(__DIR__, __NAMESPACE__);
     *
     * Discovered routes are committed in specificity order once the scan
     * finishes, so `/todos/new` beats `/todos/{id}` regardless of which file
     * happened to sort first. Call this after any {@see registrar()} calls you
     * want sorted too — both share the same table.
     *
     * @param class-string $namespace the namespace `$directory` maps to
     */
    public function discover(string $directory, string $namespace): self
    {
        RouteRegistry::discover($this->routes, $directory, $namespace);
        $this->routes->commitDiscovered();

        return $this;
    }

    /**
     * Register routes declared explicitly.
     *
     * @param RouteRegistrar|class-string<RouteRegistrar> $registrar
     */
    public function registrar(RouteRegistrar|string $registrar): self
    {
        $instance = $registrar instanceof RouteRegistrar ? $registrar : $this->container->get($registrar);

        $instance->register($this->routes);

        return $this;
    }

    /**
     * Route a request (captured from the environment) and send the result.
     * Terminates the script.
     */
    public function send(?Request $request = null): never
    {
        $this->handle($request ?? Request::capture())->send();
    }

/**
 * Render a caught exception as a response.
     *
     * Known framework exceptions become their proper status code. Anything else is
     * a genuine bug and is re-thrown unless the application has registered an
     * error handler, so a mistake in a component fails loudly in development
     * instead of being dressed up as a tidy 500 page.
     *
     * The body is rendered for the format this request negotiated, so a JSON
     * client gets an envelope and a browser gets a page — from one code path.
     */
    public function renderException(\Throwable $e, Request $request, Response $response): Response
    {
        if ($this->errorHandler !== null) {
            return ($this->errorHandler)($e, $request, $response);
        }

        $status = match (true) {
            $e instanceof HttpException => $e->status,
            $e instanceof ValidationException => $e->status,
            $e instanceof UnknownComponentException => 404,
            $e instanceof InvalidTokenException => 403,
            $e instanceof ForbiddenActionException => 403,
            $e instanceof MethodNotAllowedException => 405,
            default => 500,
        };

        if ($status === 500 && !$e instanceof HttpException && !$e instanceof ValidationException) {
            throw $e;
        }

        $headers = [];

        if ($e instanceof MethodNotAllowedException && $e->allow !== []) {
            $headers['Allow'] = implode(', ', $e->allow);
        }

        if ($e instanceof HttpException) {
            foreach ($e->headers as $name => $value) {
                $headers[$name] = (string) $value;
            }
        }

        $errors = $e instanceof ValidationException ? $e->errors()->all() : null;

        if ($this->errorView !== null && $this->format() === Format::Html) {
            $body = ($this->errorView)($status, $e->getMessage());

            return $response
                ->setStatus($status)
                ->setBody($body instanceof Element ? $body->render() : (string) $body);
        }

        return $this->errorResponse($status, $e->getMessage(), $headers, $errors);
    }

    /**
     * Render every error through one view.
     *
     * @param callable(int, string): (Element|Raw|string) $view
     */
    public function onHttpError(callable $view): self
    {
        $this->errorView = $view;

        return $this;
    }
}