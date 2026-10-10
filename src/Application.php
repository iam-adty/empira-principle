<?php

declare(strict_types=1);

namespace IamAdty\Empira;

use IamAdty\Empira\Container\Container;
use IamAdty\Empira\Http\Middleware;
use IamAdty\Empira\Http\Pipeline;
use IamAdty\Empira\Http\Request;
use IamAdty\Empira\Http\Response;
use IamAdty\Empira\Http\Session;
use IamAdty\Empira\Routing\HttpException;
use IamAdty\Empira\Routing\RouteCollection;
use IamAdty\Empira\Routing\UrlGenerator;
use IamAdty\Empira\Security\Csrf;
use IamAdty\Empira\State\NullState;
use IamAdty\Empira\State\StateStore;

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

        $this->container->singleton(Session::class, static fn(Container $container): Session => new Session());
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
        $context = $this->makeContext($request);
        $this->context = $context;

        $this->container->instance(Request::class, $request);
        $this->container->instance(Response::class, $context->response);
        $this->container->instance(Context::class, $context);

        try {
            return $this->pipeline->send(
                $request,
                fn(Request $request): Response => $this->dispatch($request, $context)
            );
        } catch (\Throwable $e) {
            return $this->renderException($e, $request, $context->response);
        }
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
     * it is invokable, as the bare class name; either way the container builds
     * it so its own dependencies are autowired.
     */
    private function resolveHandler(mixed $handler): callable
    {
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
     * @param class-string<View\Layout> $layout
     * @param array<string, mixed>       $data
     */
    public function view(string $layout, array $data = [], ?Request $request = null): Response
    {
        $request ??= $this->context?->request ?? Request::capture();

        $markup = new $layout(
            app: $this,
            request: $request,
            data: $data,
            session: $this->container->get(Session::class),
            csrf: $this->csrf,
        );

        $body = $markup->render();

        return ($this->context?->response ?? new Response())
            ->setStatus(200)
            ->setBody($body instanceof Element ? $body->render() : $body->get());
    }

    /**
     * Add middleware that runs for every request.
     *
     * @param class-string<Middleware>|Middleware ...$layers
     */
    public function use(string|Middleware ...$layers): self
    {
        $this->pipeline->push(...$layers);

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
 */
    public function renderException(\Throwable $e, Request $request, Response $response): Response
    {
        if ($this->errorHandler !== null) {
            return ($this->errorHandler)($e, $request, $response);
        }

        $status = match (true) {
            $e instanceof HttpException => $e->status,
            $e instanceof UnknownComponentException => 404,
            $e instanceof InvalidTokenException => 403,
            $e instanceof ForbiddenActionException => 403,
            $e instanceof MethodNotAllowedException => 405,
            default => 500,
        };

        if ($status === 500 && !$e instanceof HttpException) {
            throw $e;
        }

        if ($e instanceof MethodNotAllowedException && $e->allow !== []) {
            $response->withHeader('Allow', implode(', ', $e->allow));
        }

        if ($e instanceof HttpException) {
            foreach ($e->headers as $name => $value) {
                $response->withHeader($name, (string) $value);
            }
        }

        if ($this->errorView !== null) {
            $body = ($this->errorView)($status, $e->getMessage());

            return $response
                ->setStatus($status)
                ->setBody($body instanceof Element ? $body->render() : (string) $body);
        }

        return $response->setStatus($status)->setBody($e->getMessage());
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