---
name: add-route
description: Use when adding or changing an Empira route — editing app/routes.php or a RouteRegistrar, adding a controller, declaring path parameters with defaults, creating route groups, adding middleware to a route or group, naming routes for url()->to(), or debugging why a route does not match or returns the wrong one.
---

# Adding an Empira route

Routes map a method and path to a handler. Handlers return a `Response`, or
anything stringable, and the framework normalises it.

## Declare it

```php
// app/routes.php — a returned closure receives the RouteCollection
return static function (RouteCollection $routes): void {
    $routes->get('/todos', [TodoController::class, 'index'], 'todos.index')
            ->get('/todos/{id}', [TodoController::class, 'show'], 'todos.show');
};
```

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

## Ordering is first-match-wins

This is the sharp edge. A specific path **must** precede a general one:

```php
->get('/todos/new', NewTodo::class);   // specific — first
->get('/todos/{id}', ShowTodo::class); // general
```

Reversed, `/todos/new` is swallowed by `/todos/{id}` — it returns the wrong page
rather than an error. There is no specificity sort yet (see `SPEC.md`).

## Path parameters

```php
->get('/todos/{id}', …)           // one segment
->get('/posts/{page?}', …)       // optional
->get('/n/{id:[0-9]+}', …)        // constrained
```

A captured segment arrives as a **string**. Type it with `defaults`:

```php
->get('/todos/{id}', $handler, 'todos.show', defaults: ['id' => 0]);   // int $id
```

An `int` default → `int`, `float` → `float`, `bool` → `bool`. Attribute routing is
planned and this workaround disappears with it.

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
| **Wrong page** | a general route declared before a specific one |
| **403 on a group** | middleware attached but not executed — verify the dispatcher |

```bash
php bin/empira route:list --app=path/to/bootstrap.php
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
- `docs/planned-routing.md` — attribute routing, not yet built