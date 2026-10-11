# Routing

> **Status: describes shipped code.** Attribute-based routing is
> [planned](../SPEC.md) and will change parts of this page.

Routes map a method and path to a handler. Handlers return a `Response`, or
anything stringable, and Empira normalises it.

## Declaring routes

```php
$app->routes()
    ->get('/', static fn(): string => 'home', 'home')
    ->get('/todos/{id}', [TodoController::class, 'show'], 'todos.show')
    ->post('/todos', [TodoController::class, 'store']);
```

A handler may be:

| Shape | Resolved as |
|---|---|
| a closure | called with injected parameters |
| `[Controller::class, 'method']` | the controller from the container, then the method |
| a controller class name | built from the container, `__invoke()` called |

Handler parameters are resolved by name from the matched route, and anything
typed as a class is autowired:

```php
static fn(Request $request, Application $app, int $id) => …
```

## Path parameters

```php
->get('/todos/{id}', …)              // one segment
->get('/posts/{page?}', …)          // optional — may be absent
->get('/n/{id:[0-9]+}', …)           // constrained
```

### Typing parameters

A captured segment arrives as a **string**. Its type comes from the route's
`defaults`, which is the one rough edge in this API:

```php
->get('/todos/{id}', $handler, 'todos.show', defaults: ['id' => 0]);   // int $id
```

An `int` default makes it an `int`, a `float` default a `float`, a `bool`
default a `bool`. **Attribute-based routing is planned**, and this workaround
disappears with it — the handler's own signature will type the parameter.

## Ordering is first-match-wins

Routes are matched in declaration order, so a specific path must precede a
general one:

```php
->get('/todos/new', NewTodo::class);   // specific — first
->get('/todos/{id}', ShowTodo::class); // general — catches the rest
```

Get this backwards and `/todos/new` is swallowed by `/todos/{id}`, which returns
the wrong thing rather than an error. A specificity sort is [planned](../SPEC.md).

## Groups

```php
$app->routes()->group('/admin', function (RouteCollection $r): void {
    $r->get('/stats', StatsController::class, 'admin.stats');
}, middleware: [RequireAuth::class]);
```

Groups share a prefix, parameter defaults and middleware. They nest, and the
prefix does not escape its group.

## Named routes and reverse routing

```php
$url->to('todos.show', ['id' => 7]);      // '/todos/7'
$url->to('search', ['q' => 'milk']);      // '/search?q=milk'
```

Parameters not in the pattern become the query string. A missing required
parameter raises `InvalidArgumentException` naming the parameter rather than
producing a broken URL. Values are URL-encoded.

## Controllers

```php
final class TodoController extends Controller
{
    public function show(int $id): Response
    {
        return $this->view(TodoLayout::class, ['id' => $id]);
    }

    public function store(): Response
    {
        return Response::json(['ok' => true], 201);
    }
}
```

`Controller` gives you `view()`, `component()`, `redirect()`, `json()`,
`abort()`, `notFound()`, `request()`, `response()` and `url()`.

## Stopping early

```php
$this->abort(403, 'Not yours.');      // throws HttpException
$this->notFound();
HttpException::notFound('Gone.');
```

`HttpException` is mapped to its status code and rendered through the error
handler.

## Method mismatch

A request whose path matches but whose method does not gets **405** with an
`Allow` header listing what would have worked. An unmatched path is a **404**.

## Middleware

Global, via `use()`:

```php
$app->use(VerifyCsrfToken::class, new TimingMiddleware($logger));
```

Per group or per route, through the group's `middleware:` argument. Middleware is
onion-shaped — it runs before the handler and again after it — and handlers are
autowired:

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

## Component action URLs are separate

Component actions are **not** routes. They live under a reserved prefix and are
matched before named routes:

```
/empira/{signed-token}/{action}
```

The signed token carries the component name, an instance nonce and its props.
This is deliberate: the endpoint belongs to a component instance, not to your
route table. See [components.md](components.md).

## Listing routes

```bash
php bin/empira route:list --app=path/to/bootstrap.php
```

Currently requires a bootstrap file that returns an `Application`, a
`RouteCollection`, or a closure that fills one. Bootstrapping the real
application instead is [planned](../SPEC.md).

## Further reading

- [ARCHITECTURE.md](../ARCHITECTURE.md) — where routing sits in the request
- [docs/planned-routing.md](planned-routing.md) — attribute routing, tutorial form
- [../SPEC.md](../SPEC.md) — planned work