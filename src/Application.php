<?php

declare(strict_types=1);

namespace Empira;

use Empira\Config\Config;
use Empira\Config\DatabaseConfig;
use Empira\Console\CommandProvider;
use Empira\Security\Guard;
use Empira\Security\RequestUser;
use Empira\Container\Container;
use Empira\Html;
use Empira\Http\Format;
use Empira\Http\Middleware;
use Empira\Http\Pipeline;
use Empira\Http\Request;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Empira\Http\Response;
use Empira\Http\Session;
use Empira\Routing\HttpException;
use Empira\Routing\RouteCollection;
use Empira\Routing\RouteParams;
use Empira\Routing\RouteRegistrar;
use Empira\Routing\RouteRegistry;
use Empira\Routing\UrlGenerator;
use Empira\Security\Csrf;
use Empira\Persistence\EntityManagerFactory;
use Doctrine\ORM\EntityManagerInterface;
use Empira\State\NullState;
use Empira\State\StateStore;
use Empira\Validation\ValidationException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

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
 * @see \Empira\Component
 * @see \Empira\Registry
 *
 * ## Not `final`, deliberately
 *
 * Composing this is the conventional way to be an application, and what most
 * of them do. Extending it is the other way, and an application whose own
 * `App` class *is* its `Application` gets something composition cannot: the class
 * that boots the application is also the class that declares what it is, so the
 * secret, the modules and the login page are attributes on it rather than
 * arguments threaded through a constructor that also builds a logger.
 *
 * The cost is that this class's methods are now part of someone's public
 * surface, so a signature cannot change the way one on a `final` class can.
 */
class Application
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

    /**
     * Whether a reverse proxy is in front, and its headers may be believed.
     *
     * Off by default because the consequence of being wrong is silent: a log
     * full of addresses the client chose, which is the one thing a rate limiter
     * must not be built on.
     */
    private bool $trustProxies = false;

    /** @var list<\Empira\Security\Guard> */
    private array $guards = [];

    /** @var list<Module> */
    private array $modules = [];

    /** @var array<string, string> namespace => migration directory, from modules */
    private array $migrationSources = [];

    /** @var array<string, \Empira\Console\Command> commands the application supplied */
    private array $commands = [];

    /** Where anything the framework has to record goes. Discards, unless told otherwise. */
    private LoggerInterface $logger;

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

        // A null logger by default so an app that never asked for logging does
        // not write one — and so no call site needs a null check.
        $this->logger = new NullLogger();
        $this->container->instance(LoggerInterface::class, $this->logger);

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
     * Wire authentication.
     *
     *     $app->auth(
     *         new SessionGuard($session, $users),
     *         new BearerGuard($users->byApiToken(...)),
     *     );
     *
     * A `RequestUser` is then built and bound **per request**, not once at boot.
     * That is deliberate: a singleton would need resetting before every
     * dispatch, and a reset someone forgets is a request that runs as the user
     * before it. A fresh instance cannot remember the request before it, so
     * there is nothing to forget.
     *
     * Guards are consulted in order and the first identity wins.
     */
    public function auth(Guard ...$guards): self
    {
        $this->guards = array_values($guards);

        return $this;
    }

    /**
     * The guards currently registered, in the order they are consulted.
     *
     * Exposed for modules that need to build their own per-request service from
     * them — the auth module binds its `Auth` façade from exactly this list, so
     * the object it publishes and the one the framework resolves from are built
     * from the same guards and cannot disagree about who is signed in.
     *
     * @return list<Guard>
     */
    public function guards(): array
    {
        return $this->guards;
    }

    /**
     * Install modules — the units of functionality this framework does not
     * assume an application needs.
     *
     *     $app->module(new AuthModule(new DoctrineUserProvider($app->entities())));
     *
     * Registered in order and immediately: a module that adds entity paths
     * needs `orm()` to have run, and one that adds routes needs to be installed
     * before discovery. Registering late is therefore a mistake the caller sees
     * as a missing route or an unmapped entity, not as "too late".
     *
     * Chainable, like everything else here, because the boot sequence reads as
     * one statement per thing the application wants rather than a list of calls
     * whose return values have to be threaded.
     *
     * @param Module ...$modules
     */
    public function module(Module ...$modules): self
    {
        foreach ($modules as $module) {
            $module->register($this);

            $this->modules[] = $module;

            // A module that ships migrations says so here, at the same moment it
            // registers the rest of itself. Collecting it separately — after
            // every module had run — would be a second pass over the list for
            // information already available.
            if ($module instanceof MigrationSource) {
                foreach ($module->migrations() as $namespace => $directory) {
                    $this->migrationSources[$namespace] = $directory;
                }
            }

            // Same reasoning as migrations, same moment: a module that ships
            // commands says so here rather than reaching into the application
            // from inside register(), which would make the commands a property of
            // the wiring instead of of the module.
            if ($module instanceof CommandProvider) {
                foreach ($module->commands() as $name => $command) {
                    $this->command($name, $command);
                }
            }
        }

        return $this;
    }

    /**
     * The modules installed on this application, in registration order.
     *
     * @return list<Module>
     */
    public function modules(): array
    {
        return $this->modules;
    }

    /**
     * Migration directories contributed by modules, keyed by namespace.
     *
     * Read by `empira migrate`, which has to plan across every source at once —
     * Doctrine keeps one version ledger for the whole database, so a module's
     * migration and a project's are steps in one sequence rather than two that
     * happen to share a table.
     *
     * @return array<string, string> namespace => directory
     */
    public function migrationSources(): array
    {
        return $this->migrationSources;
    }

    /**
     * Add a command this application supplies.
     *
     * The framework cannot know what an application's seeders or importers look
     * like, so `empira seed` is written by the application that owns it and
     * registered here. Registering it at boot is what lets `bin/empira` find
     * it: the console boots the project, asks the {@see Application} what
     * commands it has, and adds them to the listing.
     *
     *     $app->command('seed', new Seed($app->container()->get(UserRepository::class)));
     *
     * @throws \InvalidArgumentException when the name is already registered
     */
    public function command(string $name, \Empira\Console\Command $command): self
    {
        if (isset($this->commands[$name])) {
            throw new \InvalidArgumentException(sprintf(
                'A command called "%s" is already registered.',
                $name
            ));
        }

        $this->commands[$name] = $command;

        return $this;
    }

    /**
     * Register every command under `$directory`, found by existing.
     *
     * The counterpart to {@see discover()}, and the same bargain: a class that
     * declares a name is a command, so a new one is wired by being written
     * rather than by adding a line to a bootstrap. Laravel's `app/Console` is the
     * convention and this is the same one.
     *
     *     $app->discoverCommands(dirname(__DIR__) . '/src/Console', 'App\\Console');
     *
     * Two things are decided here rather than by the caller.
     *
     * **The name comes from the command, not the file.** `Command::name()` is the
     * contract the console dispatches on, so a class called `Purge` may be
     * invoked as `purge`. Deriving it from the basename would mean the file name
     * silently mattered.
     *
     * **Commands are built by the container.** A command that touches the
     * database needs `Application`, and requiring every command to be
     * dependency-free would push `new` into the caller — which is the
     * registration this method exists to remove. A command whose dependencies
     * cannot be resolved fails here, at boot, rather than when an operator types
     * its name.
     *
     * A directory that does not exist contributes nothing. An application with no
     * commands has no `src/Console/`, and that is not a mistake to report.
     * A class in the directory that is not a command is skipped, so
     * `app/Console/` can hold helpers.
     *
     * @param string $namespace the namespace `$directory` holds, e.g. `App\Console`
     *
     * @throws \InvalidArgumentException when two commands answer to one name
     * @throws \Empira\Container\ContainerException when a command cannot be built
     */
    public function discoverCommands(string $directory, string $namespace): self
    {
        foreach (ClassScanner::in($directory, $namespace) as $class) {
            if (!is_a($class, Console\Command::class, true)) {
                continue;
            }

            /** @var Console\Command $command */
            $command = $this->container->make($class);

            $this->command($command->name(), $command);
        }

        return $this;
    }

    /**
     * The commands this application registered, keyed by the name each is
     * invoked with.
     *
     * @return array<string, \Empira\Console\Command>
     */
    public function commands(): array
    {
        return $this->commands;
    }

    /**
     * The database settings, from the argument, from `db.*` in the container's
     * Config, or from the environment.
     *
     * **The environment is the last fallback, not the only source.** It used to
     * be neither: with no argument and no bound `Config` this returned `[]`,
     * `DatabaseConfig` filled in its defaults, and `orm()` silently opened
     * `:memory:`. Nothing said so — the ORM connected, every query answered, and
     * the only symptom was a table that had been migrated into a real file a
     * moment earlier not being there. An application that had to spell out the
     * database in code was not being redundant; it was working around this.
     *
     * So the fallback goes through {@see DatabaseConfig::fromEnvironment()},
     * which is the same reader `empira migrate` uses. That is what the sentence
     * below `orm()` has always claimed, and it is now true.
     *
     * @return array<string, mixed>
     */
    private function dbConfig(?array $config): array
    {
        if ($config !== null) {
            // A list is the *entity paths*, passed one argument too early —
            // `orm($paths)` where the signature wants `orm($config, $paths)`.
            //
            // Worth refusing rather than ignoring, because the old behaviour was
            // the worst kind: no `driver` and no `database` in the config meant
            // `DatabaseConfig` supplied its defaults, so the application opened
            // `:memory:`, every query answered, and the only symptom was a table
            // migrated into a real file not being there. An empty array is left
            // alone, because `orm([])` is a caller who means "no overrides".
            if ($config !== [] && array_is_list($config)) {
                throw new \LogicException(sprintf(
                    'Application::orm() takes the database configuration first and the entity '
                    . 'paths second, so orm($paths) is passing the paths as the configuration. '
                    . 'Got %s. Did you mean orm(null, $paths)?',
                    implode(', ', array_map(strval(...), $config)),
                ));
            }

            return $config;
        }

        $bound = $this->container->bound(Config::class)
            ? $this->container->get(Config::class)
            : null;

        if ($bound instanceof Config && ($bound->array('db')) !== []) {
            return $bound->array('db');
        }

        return DatabaseConfig::fromEnvironment()->all();
    }

    /**
     * Wire the ORM and make `EntityManagerInterface` autowirable from here on.
     *
     *     $app->orm(['driver' => 'sqlite', 'database' => __DIR__ . '/app.sqlite']);
     *
     *     // …now in any component, controller or middleware:
     *     public function __construct(private readonly EntityManagerInterface $em) {}
     *
     * Configuration is the flat shape the old `database()` took, and it goes
     * through {@see DatabaseConfig} — the same reader `empira migrate` uses, so
     * the runtime and the deploy step cannot disagree about which database
     * either of them means.
     *
     * `entityPaths` is exactly what it says — the directories to map — and
     * defaults to none. It used to silently include the framework's own `Auth`
     * directory, which meant an application could not have an ORM without also
     * getting a `users` table it never asked for, and could not have its own
     * entities *instead* of the framework's. A framework choosing its
     * application's schema is not a default; it is a decision made on the
     * application's behalf. An application using the auth module passes its
     * directory alongside its own, or asks `AuthModule::entityPath()` for it.
     *
     * @param list<string> $entityPaths directories holding `#[Entity]` classes
     */
    public function orm(?array $config = null, array $entityPaths = []): self
    {
        $items = $this->dbConfig($config);

        $this->container->instance(
            EntityManagerInterface::class,
            EntityManagerFactory::make(new Config($items), $entityPaths),
        );

        return $this;
    }

    /**
     * The ORM, once {@see Application::orm()} has been called.
     */
    public function entities(): EntityManagerInterface
    {
        if (!$this->container->bound(EntityManagerInterface::class)) {
            throw new \LogicException(
                'No ORM is configured. Call $app->orm() during boot before asking for one.'
            );
        }

        return $this->container->get(EntityManagerInterface::class);
    }

    /**
     * The request's session, for flash messages and the like.
     */
    public function session(): Session
    {
        return $this->container->get(Session::class);
    }

    /**
     * Where the application records what happened.
     *
     *     $app->log(new Monolog\Logger('app'));
     *
     * PSR-3 rather than an interface of the framework's own, so the logger an
     * application already has — Monolog, a cloud handler, a test double — is
     * the logger the framework writes to. Defaults to discarding: a framework
     * that logs by surprise is a framework nobody trusts in production.
     *
     * The logger is also bound as `LoggerInterface` in the container, so a
     * component or a repository can take it by type the way it takes `Auth`.
     */
    public function log(LoggerInterface $logger): self
    {
        $this->logger = $logger;
        $this->container->instance(LoggerInterface::class, $logger);

        return $this;
    }

    /**
     * The logger the framework writes to, which is the one the app supplied.
     */
    public function logger(): LoggerInterface
    {
        return $this->logger;
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
            request: $request ?? Request::capture($this->trustProxies),
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
     * Answer one request.
     *
     * Component action traffic is matched first, because those URLs live under
     * a reserved prefix; then named routes, then the fallback.
     *
     * Typed as PSR-7's `ServerRequestInterface` rather than this package's
     * {@see Request}, so a PSR-15 consumer can drive an Empira application
     * directly and get one of these back. The concrete types still work — they
     * implement the interface — and everything downstream narrows with
     * {@see Request::of()} if it needs the htmx conveniences.
     *
     * ## Why the resets at the top are here
     *
     * An `Application` outlives the request in a long-running worker, and two
     * pieces of state outlive it *wrongly*.
     *
     * **The session.** The lock and the write-back belong to one request, not to
     * whichever one this worker serves next. The reason lives in
     * {@see Session::beginRequest()}, which is where the reset happens.
     *
     * **The ORM's identity map.** Every entity an `EntityManager` has loaded
     * stays loaded, and asking for one again returns the old object without
     * touching the database. Under php-fpm that is invisible, because the
     * process ends with the request. Under a worker it means request two is
     * served request one's user — silently, with nothing in any log. `clear()`
     * detaches everything rather than closing the manager, so the next request
     * reads from the database again and the connection is still the one opened
     * at boot.
     *
     * The same reset covers {@see RouteParams}: `/todos/7` must not leave its
     * `id` behind for the next request that matches nothing.
     *
     * The format is negotiated once, up front: `Request` is immutable, so it is
     * a property of this dispatch rather than something mutated onto it later.
     *
     * ## Why `RequestUser` is bound here and not by the auth module
     *
     * Per request, not per application, and bound under its **own** contract
     * rather than the auth module's façade — so anything that only needs to ask
     * "who is this" works with whatever `Identity` a guard produced, including
     * one from a module this framework has never heard of. The auth module binds
     * its own `Auth`, which narrows to a `User` and adds login/logout; core does
     * not know that class exists.
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $request = Request::of($request);

        $this->container->get(Session::class)->beginRequest();

        if ($this->container->bound(EntityManagerInterface::class)) {
            $this->container->get(EntityManagerInterface::class)->clear();
        }

        $context = $this->makeContext($request);
        $this->context = $context;

        $this->format = $request->accepts('application/json', ['text/html'])
            ? Format::Json
            : Format::Html;

        $this->params = new RouteParams();

        $this->container->instance(Request::class, $request);
        $this->container->instance(Response::class, $context->response);
        $this->container->instance(Context::class, $context);
        $this->container->instance(Format::class, $this->format);
        $this->container->instance(RouteParams::class, $this->params);

        $this->container->instance(RequestUser::class, new RequestUser($this->guards, $request));

        try {
            $response = $this->pipeline->send(
                $request,
                fn(Request $request): Response => $this->dispatch($request, $context)
            );
        } catch (\Throwable $e) {
            $response = $this->renderException($e, $request, $context->response);
        } finally {
            // The request is over, so it must not leave the session open. The
            // lock and the write-back both belong to this request, not to
            // whichever one this worker serves next — see Session::endRequest().
            $this->container->get(Session::class)->endRequest();

            // A context outliving its request is how `view()` outside a dispatch
            // ends up mutating a response that has already been sent.
            $this->context = null;
        }

        return $response;
    }

    /**
     * Believe the proxy headers when deciding who a request came from.
     *
     *     $app->trustProxies();   // nginx, Caddy, Cloudflare in front
     *
     * Off unless asked for. `X-Forwarded-For` is set by whoever sent the
     * request, so with nothing in front of the application it is a value the
     * client chose — and a log full of client-chosen addresses is worse than no
     * addresses, because it looks like evidence.
     *
     * Turn it on only when something genuinely rewrites the address. The reverse
     * mistake is quieter: behind a proxy every request arrives from the proxy,
     * so a log with one repeated value is a lie rather than an absence.
     */
    public function trustProxies(bool $trust = true): self
    {
        $this->trustProxies = $trust;

        return $this;
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
            $response = $response->withHeader($name, (string) $value);
        }

        if ($this->format() === Format::Json) {
            $error = ['status' => $status, 'message' => $message];

            if ($errors !== null && $errors !== []) {
                $error['errors'] = $errors;
            }

            return $response
                ->withHeader('Content-Type', 'application/json')
                ->withStatus($status)->withHtml(Html::json(['error' => $error]));
        }

        return $response->withStatus($status)->withHtml($message);
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

        return $response->withStatus(200)->withHtml($body);
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
        $request ??= $this->context?->request ?? Request::capture($this->trustProxies);

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
            ->withStatus(200)->withHtml($body instanceof Element ? $body->render() : $body->get());
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
     * @param class-string<MiddlewareInterface>|MiddlewareInterface ...$layers
     */
    public function use(string|MiddlewareInterface ...$layers): self
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
     * Make every component under `$namespace` reachable by token.
     *
     * The counterpart to {@see discover()}, and the reason composition does not
     * cost a registration per component:
     *
     *     $app->components(__NAMESPACE__);
     *
     * A component that is only ever reached through another one still answers
     * its own action URLs, because a token naming its class resolves without
     * anyone having listed it. Registering each nested component by hand used
     * to be the price of nesting, and the price was paid for components whose
     * only error was being forgotten.
     *
     * Prefer this over `$app->registry()->add(...)`. An alias is still worth
     * having for a component whose token should stay short, or survive the class
     * moving namespaces.
     *
     * @param class-string|string $namespace
     */
    public function components(string $namespace): self
    {
        $this->registry->allowNamespace($namespace);

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
     *
     * For a classic runtime — php-fpm, the built-in server, FrankenPHP in
     * classic mode. One request, one process, nothing to come back to.
     *
     * @see self::respond() for the same thing under a worker
     */
    public function send(?Request $request = null): never
    {
        $this->handle($request ?? Request::capture($this->trustProxies))->send();
    }

    /**
     * Route a request, emit the result, and return.
     *
     * The worker's counterpart to {@see send()}, and the reason it exists. The
     * two runtimes should read the same at the call site — one call, not
     * plumbing — while doing the one thing that differs between them:
     *
     * `send()` **emits and exits**, which is right for a process with nothing
     * else to do. Under a worker it is fatal: the script ends, FrankenPHP
     * restarts it, and the process serves exactly one request — correct, and
     * none of the point of running a worker. So this emits and returns.
     *
     *     $handler = static function () use ($app): void {
     *         try {
     *             $app->respond();
     *         } catch (\Throwable $e) {
     *             // still needed: see below
     *         }
     *     };
     *
     * The `catch` is not decoration. `set_exception_handler` fires when the
     * script ends, which in a worker is never, so a throwable that escapes
     * {@see handle()} — a genuine bug the framework chose to re-throw rather
     * than dress as a tidy 500 — would take the worker down and be restarted
     * straight back into the same bug. Error policy belongs to whoever owns the
     * runtime, so it stays at the call site rather than being swallowed here.
     *
     * @see self::handle() to get the response instead of sending it
     */
    public function respond(?Request $request = null): void
    {
        ($this->handle($request ?? Request::capture($this->trustProxies)))->emit();
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
            // An application that renders the exception still wants it recorded:
            // a tidy page is a response, not a diagnosis.
            $this->logFailure($e, $request);

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
            // Logged before it is re-thrown, because the re-throw is what sends
            // it to the server's error output — and that is off by default in
            // most PHP configurations, so the one thing a developer most needs
            // to see is the one thing that was being lost.
            $this->logFailure($e, $request);

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

            // The headers are applied here too. An error view chooses the *body*
            //; it has no business changing what the response means, and
            // dropping these silently costs a 405 its `Allow` and a 429 its
            // `Retry-After` — both of which are part of the protocol rather than
            // decoration on it.
            foreach ($headers as $name => $value) {
                $response = $response->withHeader($name, $value);
            }

            return $response
                ->withStatus($status)->withHtml($body instanceof Element ? $body->render() : (string) $body);
        }

        return $this->errorResponse($status, $e->getMessage(), $headers, $errors);
    }

    /**
     * Record an exception that ended a request, with enough about the request
     * to reproduce it.
     *
     * The method and path are in the context rather than the message so that a
     * structured handler can index them, and the whole thing is guarded: a
     * logger that throws while being asked to report a failure would replace a
     * 500 with a different 500 and lose the original.
     *
     * @internal
     */
    private function logFailure(\Throwable $e, Request $request): void
    {
        try {
            $this->logger->error($e->getMessage(), [
                'exception' => $e,
                'method' => $request->method,
                'path' => $request->path(),
            ]);
        } catch (\Throwable) {
            // Nothing sensible to do here, and saying so would be recursive.
        }
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