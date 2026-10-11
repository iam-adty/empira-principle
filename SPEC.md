# Specification

> **Status: intended work, not shipped.** Every item is marked. Anything
> without a `[shipped]` marker **does not exist yet** — do not write code that
> assumes it, and do not document it as behaviour.
>
> Shipped behaviour is in [ARCHITECTURE.md](ARCHITECTURE.md) and `docs/`.

Last reviewed: 2026-10-11. Nothing on this page has been implemented.

---

## 1. Attribute-based routing `[planned]`

### The problem

Routing today lives in a `routes.php` file outside `src/`, as a returned closure. Every URL is declared twice — once in the route table, once in the handler — and every page route repeats the same `$app->view(Layout::class, [...])` shape. Route parameters are typed by a workaround (`defaults: ['id' => 0]`) rather than by the handler's own signature.

### The design

**Attributes declare routes; an explicit registrar handles what an attribute expresses badly.**

```php
#[Route('/', name: 'home')]
#[Route('/dashboard', name: 'dash')]          // repeatable: many URLs, one page
final class HomeLayout extends Layout
{
    protected function regions(): array { /* … */ }
}
```

- `#[Route]` targets **classes and methods**, and is `IS_REPEATABLE` — so one class can serve several URLs.
- On a **Layout class**, it means "this URL renders this page", which collapses route and page into one file.
- On a **controller method**, it is the conventional thing.
- `methods:` accepts a string or an array and defaults to `GET`. It is kept separate from the existing `#[Get]`/`#[Post]` verb attributes on purpose: those mark *htmx actions*, and one annotation should not grant two different capabilities.

**Getting parameters into a page.** `Layout` must **not** declare `mount()` in the base class — a fixed signature would make every override a fatal LSP violation. Instead the dispatcher stashes matched parameters in a request-scoped `RouteParams` bound in the container, `view()` merges them under explicit data, and `mount()` is invoked by reflection with autowiring if the layout defines it:

```php
#[Route('/todos/{id}')]
final class TodoLayout extends Layout
{
    public function mount(TodoRepository $todos, int $id): void
    {
        $this->todo = $todos->findOrFail($id);
    }
}
```

The signature types the parameter, so `defaults: ['id' => 0]` disappears.

### Ordering

The current sharp edge: `/todos/new` must precede `/todos/{id}`, or the dynamic route swallows it. With discovery that order would come from the filesystem — a silent 404 that reappears when a file is renamed.

So routes get **one specificity sort**, applied uniformly to discovered and explicit routes:

1. explicit `priority:` (descending, default 0)
2. **fewest `{param}` segments first**
3. most literal segments first
4. longer pattern first
5. registration order (stable tiebreak)

Safe for existing code: equal-specificity routes keep declaration order, so only param-versus-static precedence changes.

### Route names

`name:` is optional. Omitted, it is derived from the class: `Pages\HomeLayout` → `pages.home`, `Controllers\TodoController::show` → `todos.show`, so `url()->to('todos.show')` works immediately.

**Known risk:** renaming a method silently changes the derived name. Explicit `name:` is the escape hatch.

### Files

| File | Purpose |
|---|---|
| `src/Attributes/Route.php` | the attribute |
| `src/Routing/RouteRegistrar.php` | interface for explicit registration |
| `src/Routing/RouteRegistry.php` | scan a directory, extract, sort |
| `src/Routing/RouteParams.php` | request-scoped matched parameters |

Plus: `Application::discover()`, `view()` gains parameter merging and `mount()` injection, and the CLI `route:list` boots the real app instead of requiring `routes.php`.

---

## 2. JSON / API routes `[planned]`

### The problem

`renderException()` produces a plain-text body or an HTML view. There is no JSON error shape, and `Request` does not look at `Accept` at all. A non-browser client gets HTML.

### Negotiated, not declared

The route does **not** declare that it serves JSON. The response format is negotiated from the `Accept` header, so one endpoint can serve a page and an API client without a flag.

`Request::accepts()` parses real `Accept` headers including `q` values, following the HTTP rule that **an explicit match always beats a wildcard**:

| Client sends | Result |
|---|---|
| browser / htmx: `text/html, */*;q=0.9` | HTML |
| curl: `*/*` | HTML |
| axios: `application/json, text/plain, */*` | JSON |
| `text/html, application/json;q=0.9` | HTML (explicit html has the higher q) |
| `application/json` | JSON |

htmx sends `Accept: text/html, */*;q=0.9`, so **fragment behaviour is unaffected**. This must be asserted in the smoke test rather than assumed.

`Request` is immutable, so the negotiated format is computed once in `handle()` and passed to error rendering — never mutated onto the request.

### One error envelope

Every failure, framework-originated or application-originated, returns the same shape:

```json
{"error": {"status": 422, "message": "…", "errors": {"title": ["Required"]}}}
```

`errors` appears only for validation. One shape means clients parse one thing. When HTML is negotiated, behaviour is byte-identical to today.

### Known bug this fixes `[planned]`

`ValidationException` is **not** mapped in `renderException()`. It falls through to `default => 500`, and because it is not an `HttpException` it is **rethrown** — so a controller that throws it today produces an uncaught fatal rather than a 422, despite its own docblock documenting the 422 usage.

This is worth fixing on its own merits, independent of the API work.

### Deferred

- CSRF exemption for token-authenticated routes
- Any user model

---

## 3. Authentication `[deferred]`

Explicitly out of scope for now. When it arrives it plugs into two seams this document already establishes: the negotiated response format, and the error envelope (`401` in the same shape).

No design work has been done. Deliberately.

---

## Adding to this document

Every entry needs a status marker, and a marker has to move. When an item ships:

1. Flip it to `[shipped]` with the commit.
2. Move its *behaviour* into [ARCHITECTURE.md](ARCHITECTURE.md) and the relevant `docs/` page.
3. Move it into [CHANGELOG.md](CHANGELOG.md) under `[Unreleased]`.
4. Delete it from here — a shipped item still listed as planned is worse than no document.

A specification that describes software which does not exist is the most expensive kind of documentation to keep.