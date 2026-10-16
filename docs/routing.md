# Routing

> **Status: describes shipped code.**

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
default a `bool`.

A [discovered route](#attribute-routing) types its own parameters by signature,
so `defaults:` is only needed for a closure registered by hand.

## Ordering

A hand-written table is matched **in declaration order**, so a specific path
must precede a general one:

```php
->get('/todos/new', NewTodo::class);   // specific — first
->get('/todos/{id}', ShowTodo::class); // general — catches the rest
```

Get this backwards and `/todos/new` is swallowed by `/todos/{id}`, which returns
the wrong thing rather than an error.

[Discovered routes](#attribute-routing) cannot rely on that, because their order
would come from the filesystem — which is how you get a 404 that reappears when
someone renames a file. They are sorted by specificity before they join the
table; see [ordering](#ordering-discovered-routes).

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

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        return $this->session->has('user_id')
            ? $handler->handle($request)
            : Response::html('Sign in', 401);
    }
}
```

That is PSR-15's `process()`. [`Request::of()`](http.md#middleware) narrows the
parameter back to this framework's own where a layer wants the htmx helpers.

## Attribute routing

A page can declare its own URL, on the class that draws it:

```php
namespace App\Pages;

#[Route('/', name: 'home')]
#[Route('/dashboard', name: 'dash')]
final class HomeLayout extends Layout
{
    protected function regions(): array
    {
        return ['main' => fn(Region $r) => $r->element('div')->text('Hello')];
    }
}
```

`#[Route]` targets **classes and methods** and is **repeatable**, so one page can
answer several URLs. On a `Layout` it means *this URL renders this page*; on a
controller method it means *this URL calls this method*.

```php
$app->discover(__DIR__, __NAMESPACE__);   // walk src/, read the attributes
```

`discover()` scans a directory, maps each `.php` file to the class its path
implies, and asks the autoloader for it. A file whose class cannot be loaded is
reported on `error_log` and skipped — a path that disagrees with its class is a
mistake worth saying out loud rather than silently ignoring. Abstract classes,
interfaces, enums and anything not instantiable are skipped: they cannot be a
page, and a URL that cannot be built when the request arrives is worse than no
URL.

### The attribute

```php
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final class Route
{
    public function __construct(
        public readonly string $pattern,
        public readonly string|array $methods = 'GET',
        public readonly ?string $name = null,
        public readonly array $middleware = [],
        public readonly ?int $priority = null,
    ) {}
}
```

`methods:` is separate from the existing `#[Get]`/`#[Post]` attributes **on
purpose** — those mark htmx actions, and one annotation should not grant two
capabilities. It takes a string or an array and defaults to `GET`.

One attribute may answer several verbs:

```php
#[Route('/submit', methods: ['GET', 'POST'], name: 'submit')]
```

which registers one route per verb under one name. Two routes may share a name
when they differ by method; two for the *same* verb is a collision and raises
`InvalidArgumentException`.

### `mount()` — typed parameters

Matched parameters are bound into a request-scoped `RouteParams`, and the page's
`mount()` is invoked with autowiring:

```php
#[Route('/todos/{id}')]
final class TodoLayout extends Layout
{
    public function mount(TodoRepository $todos, int $id): void
    {
        $this->todo = $todos->findOrFail($id);   // typed from the URL
    }
}
```

`int $id` in the signature does the typing. A page can ask for `RouteParams` for
everything at once, or for `Request` and any other service, and the container
supplies them. Parameters also arrive as layout **data**, so a region can read
`$region->data('id')` without a `mount()` at all. Explicit data passed to
`view()` **overrides** the matched parameter.

`mount()` must be **public** — it is called through the container, and a
protected one raises a `LogicException` naming the class rather than a bare
`TypeError` about callables.

`Layout` deliberately does **not** declare `mount()`: a fixed signature in the
base class would make every override with arguments a fatal signature violation.

### Ordering (discovered routes)

Discovered routes are sorted before they join the table:

| Priority | Rule |
|---|---|
| 1 | explicit `priority:` descending (default 0) |
| 2 | **fewest `{param}` segments first** |
| 3 | most literal segments first |
| 4 | longer pattern first |
| 5 | registration order |

So `/todos/new` beats `/todos/{id}` because it has no parameters — and unlike a
hand-written table, that does not depend on which file sorts first. Routes that
are equally specific keep their registration order, which is PHP 8's guaranteed
stable sort rather than an accident of the algorithm.

`priority:` is the escape hatch when two routes are genuinely ambiguous and you
want to say so out loud:

```php
#[Route('/promo', priority: 10)]
```

### Names

`name:` is optional. Omitted, it derives from the class: a leading `App\` or
`Src\` is dropped as the package root, the namespace segments are kept, and a
trailing `Layout`, `Page` or `Controller` is removed.

| Declaration | Derived name |
|---|---|
| `App\Pages\HomeLayout` | `pages.home` |
| `App\Controllers\TodoController::show` | `controllers.todo.show` |

**Known risk:** renaming a class or method silently changes the derived name and
orphans links. Explicit `name:` is the escape hatch.

### Explicit registration still exists

Attributes cover pages. A registrar covers everything else — routes that are not
pages, groups, and anything awkward to express as an attribute:

```php
namespace App\Routing;

final class AppRoutes implements RouteRegistrar
{
    public function register(RouteCollection $routes): void
    {
        $routes->group('/admin', static function (RouteCollection $r): void {
            $r->get('/stats', [StatsController::class, 'show'], 'admin.stats');
        }, middleware: [RequireAuth::class]);
    }
}
```

```php
$app->registrar(Routing\AppRoutes::class);
$app->discover(__DIR__, __NAMESPACE__);
```

**Both are for different jobs.** A page and its URL belong in one file, so the
page declares it. An endpoint somebody else calls is not a page, and giving it a
layout would be a lie — so it is registered deliberately, in order.

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
php bin/empira route:list --target=.        # boots src/App.php
php bin/empira route:list --app=path.php    # any bootstrap file
```

With no `--app`, the command reads `src/App.php` in the target directory and
**boots it**, so the table it prints is the table the application really serves,
discovered attributes included. A `--app` file may instead return an
`Application`, a `RouteCollection`, or a closure that fills one — which is still
how a project registers its routes by hand.

## Further reading

- [ARCHITECTURE.md](../ARCHITECTURE.md) — where routing sits in the request
- [SPEC.md](https://github.com/iam-adty/empira-dev/blob/main/planning/SPEC.md) — planned work