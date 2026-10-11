---
name: add-route
description: Use when adding or changing an Empira route — declaring #[Route] on a Layout or controller method, writing a RouteRegistrar, taking typed path parameters, creating route groups, adding middleware to a route or group, naming routes for url()->to(), or debugging why a route does not match or returns the wrong one.
---

# Adding an Empira route

Routes map a method and path to a handler. Handlers return a `Response`, or
anything stringable, and the framework normalises it.

## Declare it

With an attribute — the usual case:

```php
final class TodoController extends Controller
{
    #[Route('/todos', name: 'todos.index')]
    public function index(): Response { … }

    #[Route('/todos/{id}', name: 'todos.show')]
    public function show(int $id): Response { … }
}
```

```php
$app->discover(__DIR__, __NAMESPACE__);   // walk src/, read the attributes
```

`#[Route]` is repeatable and targets classes and methods, so one class can answer
several URLs:

```php
#[Route('/', name: 'home')]
#[Route('/dashboard', name: 'dash')]
final class HomeLayout extends Layout { /* … */ }
```

By hand — for a group, shared middleware, or a route that is not a page:

```php
final class AppRoutes implements RouteRegistrar
{
    public function register(RouteCollection $routes): void
    {
        $routes->get('/healthz', static fn(): Response => Response::json(['ok' => true]), 'health');
    }
}

// in App::boot()
$app->registrar(Routing\AppRoutes::class);
```

**Pages use attributes; everything else uses a registrar.** Giving a health check
a layout would be a lie.

Handler shapes:

| Shape | Resolved as |
|---|---|
| closure | called with injected parameters |
| `[Controller::class, 'method']` | controller from the container, then the method |
| controller class name | built from the container, `__invoke()` called |

Parameters are injected **by name** from the match; anything typed as a class is
autowired:

```php
static fn(Request $request, Application $app, int $id) => …
```

## Ordering

**Discovered routes are sorted** — priority, then fewest parameters, then most
literal segments, then longest. So `/todos/new` beats `/todos/{id}` no matter
which file sorts first. Use `priority:` when two routes are genuinely ambiguous.

**A hand-written table is first-match-wins**, because its order is a deliberate
choice someone wrote down:

```php
$routes->get('/todos/new', NewTodo::class);   // specific — first
       ->get('/todos/{id}', ShowTodo::class); // general
```

Reversed, `/todos/new` is swallowed by `/todos/{id}` — the wrong page, not an
error.

## Path parameters

```php
->get('/todos/{id}', …)           // one segment
->get('/posts/{page?}', …)       // optional
->get('/n/{id:[0-9]+}', …)        // constrained
```

A captured segment arrives as a **string**. A discovered route types it with the
signature:

```php
#[Route('/todos/{id}', name: 'todos.show')]
public function show(int $id): Response { … }
```

A hand-written handler uses `defaults` instead:

```php
->get('/todos/{id}', $handler, 'todos.show', defaults: ['id' => 0]);   // int $id
```

An `int` default → `int`, `float` → `float`, `bool` → `bool`.

A **Layout** takes parameters in `mount()`, which must be `public`:

```php
#[Route('/todos/{id}')]
final class TodoLayout extends Layout
{
    public function mount(TodoRepository $todos, int $id): void { … }
}
```

Matched parameters also arrive as layout data (`$region->data('id')`); explicit
`view()` data overrides them.

## Groups

```php
$routes->group('/admin', function (RouteCollection $r): void {
    $r->get('/stats', [StatsController::class, 'show']);
}, middleware: [RequireAuth::class]);
```

Groups share a prefix, defaults and middleware. They nest; the prefix does not
escape its group.

## Middleware

```php
$app->use(VerifyCsrfToken::class, new Timing($logger));   // global
```

Or per group / per route via `middleware:`. Onion-shaped: the first-registered
layer is outermost. Constructors are autowired.

```php
final class RequireAuth implements Middleware
{
    public function __construct(private readonly Session $session) {}

    public function handle(Request $request, Closure $next): Response
    {
        return $this->session->has('user_id')
            ? $next($request)
            : Response::html('Sign in', 401);
    }
}
```

**If a group-level rule "does nothing", check it is actually being executed** —
that exact bug shipped once: middleware was attached to the `Route` but the
dispatcher never wrapped the handler in it.

## Naming and reverse routing

```php
$url->to('todos.show', ['id' => 7]);     // '/todos/7'
$url->to('search', ['q' => 'milk']);     // '/search?q=milk'
```

Parameters not in the pattern become the query string. A missing required
parameter throws rather than producing a broken URL.

## Stopping early

```php
$this->abort(403, 'Not yours.');
$this->notFound();
HttpException::notFound('Gone.');
```

## Diagnosing

| Symptom | Cause |
|---|---|
| **404** | path not matched, or no fallback registered |
| **405 + `Allow`** | path matched, method did not |
| **403** | class not registered, token invalid, or action not declared |
| **Wrong page** | a general route declared before a specific one, **in a hand-written table** |
| **403 on a group** | middleware attached but not executed — verify the dispatcher |
| **Route never discovered** | file name does not match its class, or the namespace passed to `discover()` is wrong |

```bash
php bin/empira route:list --target=.     # boots src/App.php
```

## Not routes: component action URLs

Component actions live under a reserved prefix and are matched **before** named
routes:

```
/empira/{signed-token}/{action}
```

They belong to a component instance, not to your route table. If you are looking
for where to register an action, you want `->action('name')` inside a component —
see the `add-component` skill.

## Reference

- `docs/routing.md` — the full routing API
- `docs/http.md` — Request/Response and middleware