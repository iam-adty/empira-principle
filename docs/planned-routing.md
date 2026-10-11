# Attribute routing — planned

> ## ⚠️ NOT IMPLEMENTED
>
> Everything on this page is **design**. None of it exists yet. The API you can
> use today is [routing.md](routing.md). This page is written to be read *before*
> building, and to be corrected against the implementation *after*.
>
> Source of truth: [../SPEC.md](../SPEC.md) §1.

The goal: one class is one page is one URL, and the route stops being a separate
declaration from the thing it renders.

## Where it hurts today

```php
// app/routes.php
$routes->get('/', static fn(Request $request, Application $app) => $app->view(Layout::class), 'home');
```

Three costs:

1. **The URL and the page live in different files.** To answer "what renders at
   `/todos/1`?" you look in a layout, then in a route table, then in a layout
   again.
2. **Every page route repeats the same shape** — `$app->view(SomeLayout::class, [...])`.
3. **Parameters are typed by a workaround.** `defaults: ['id' => 0]` exists only
   so the handler receives an `int` instead of a string.

## The shape

### A page that declares its own URL

```php
namespace App\Pages;

use IamAdty\Empira\Attributes\Route;
use IamAdty\Empira\View\Layout;
use IamAdty\Empira\View\Region;

#[Route('/', name: 'home')]
#[Route('/dashboard', name: 'dash')]
final class HomeLayout extends Layout
{
    public function mount(Clock $clock): void
    {
        $this->greeting = $clock->greeting();
    }

    protected function regions(): array
    {
        return [
            'summary' => fn(Region $r): mixed => $r
                ->element('section')
                ->append($r->component(Summary::class, ['greeting' => $this->greeting])),
        ];
    }
}
```

- `#[Route]` targets **classes and methods** and is **repeatable**, so one page
  can serve several URLs.
- On a `Layout`, it means *this URL renders this page*.

### A controller endpoint

```php
namespace App\Controllers;

use IamAdty\Empira\Attributes\Route;
use IamAdty\Empira\Http\Response;
use IamAdty\Empira\Routing\Controller;

final class TodoController extends Controller
{
    #[Route('/api/todos', methods: ['GET'], name: 'todos.index')]
    public function index(): Response
    {
        return Response::json(['items' => $this->todos->all()]);
    }

    #[Route('/api/todos', methods: ['POST'], name: 'todos.store',
        middleware: [RequireAuth::class])]
    public function store(): Response
    {
        return Response::json(['ok' => true], 201);
    }
}
```

### Explicit registration still exists

```php
namespace App\Routing;

use IamAdty\Empira\Routing\RouteCollection;
use IamAdty\Empira\Routing\RouteRegistrar;

final class AppRoutes implements RouteRegistrar
{
    public function register(RouteCollection $routes): void
    {
        $routes->group('/admin', middleware: [RequireAuth::class])
            ->get('/stats', [StatsController::class, 'show'], 'admin.stats');
    }
}
```

Attributes for the common case; a registrar for groups, global middleware and
anything awkward to express as an attribute.

```php
// app/src/App.php
$app->discover(__DIR__, __NAMESPACE__);        // attributes
(new AppRoutes())->register($app->routes()); // explicit
```

## `mount()` — the parameter story

**`Layout` must not declare `mount()` in its base class.** A fixed signature
would make every override a fatal LSP violation:

```php
// This would break every subclass wanting parameters:
abstract class Layout { public function mount(): void {} }
```

Instead the dispatcher stashes matched parameters in a request-scoped
`RouteParams` bound into the container. `view()` merges them beneath explicit
data, and `mount()` — if the layout defines it — is invoked by reflection with
autowiring.

```php
#[Route('/todos/{id}')]
final class TodoLayout extends Layout
{
    public function mount(TodoRepository $todos, int $id): void
    {
        $this->todo = $todos->findOrFail($id);   // typed, autowired
    }
}
```

`int $id` in the signature does the typing. `defaults: ['id' => 0]` disappears.

## Ordering — the sharp edge

Matching is first-match-wins. With discovery, registration order would come from
the **filesystem**, which is how you get a 404 that reappears when someone
renames a file.

So every route — discovered or explicit — gets **one specificity sort**:

| Priority | Rule |
|---|---|
| 1 | explicit `priority:` descending (default 0) |
| 2 | **fewest `{param}` segments first** |
| 3 | most literal segments first |
| 4 | longer pattern first |
| 5 | registration order (stable tiebreak) |

`/todos/new` beats `/todos/{id}` because it has no parameters.

This is safe for existing code: equal-specificity routes keep declaration order,
so only param-versus-static precedence changes.

## Route names

`name:` is optional. Omitted, it derives from the class:

| Declaration | Derived name |
|---|---|
| `#[Route('/')] class HomeLayout` | `pages.home` |
| `#[Route('/todos/{id}')] class TodoLayout` | `todos.show` |

```php
$url->to('todos.show', ['id' => 7]);   // '/todos/7'
```

**Known risk:** renaming a method silently changes the derived name and orphans
links. Explicit `name:` is the escape hatch.

## The attribute

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

`methods:` is separate from the existing `#[Get]`/`#[Post]` attributes on
purpose — those mark **htmx actions**, and one annotation should not grant two
capabilities. `methods` accepts a string or an array, and defaults to `GET`.

## Files

| File | Purpose |
|---|---|
| `src/Attributes/Route.php` | the attribute |
| `src/Routing/RouteRegistrar.php` | interface for explicit registration |
| `src/Routing/RouteRegistry.php` | scan a directory, extract, sort |
| `src/Routing/RouteParams.php` | request-scoped matched parameters |

`Application` gains `discover()`. `view()` gains parameter merging and `mount()`
injection. The CLI `route:list` boots the real application instead of requiring
a `routes.php`.

## Migration

1. Ship the attribute, discovery and the sort **without** changing the app.
2. Move one page at a time: `#[Route]` on a layout, delete its closure.
3. Delete `app/routes.php` once nothing needs it.
4. Retire `defaults:` as parameters become signature-typed.

## Tests it needs

- class-level and method-level routes discovered
- repeatable `#[Route]` on one class
- multiple `methods:` on one route
- middleware attached and executed
- names auto-derived
- **ordering**: `/todos/new` matched before `/todos/{id}`
- abstract and non-instantiable classes skipped
- `mount()` receives typed params and autowired dependencies
- explicit `$data` overrides route parameters
- all 616 existing tests still pass unchanged

---

**This page describes software that does not exist.** When it is built, move the
behaviour into [routing.md](routing.md), flip the marker in
[../SPEC.md](../SPEC.md), and delete this file.