# Specification

> **Status: intended work, not shipped.** Every item is marked. Anything
> without a `[shipped]` marker **does not exist yet** — do not write code that
> assumes it, and do not document it as behaviour.
>
> Shipped behaviour is in [ARCHITECTURE.md](ARCHITECTURE.md) and `docs/`.

Last reviewed: 2026-10-11. Sections 1 and 2 have shipped.

---

## 1. Attribute-based routing `[shipped]`

### The problem

Routing lived in a `routes.php` file outside `src/`, as a returned closure. Every URL was declared twice — once in the route table, once in the handler — and every page route repeated the same `$app->view(Layout::class, [...])` shape. Route parameters were typed by a workaround (`defaults: ['id' => 0]`) rather than by the handler's own signature.

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

So **discovered** routes get one specificity sort before they join the table:

1. explicit `priority:` (descending, default 0)
2. **fewest `{param}` segments first**
3. most literal segments first
4. longer pattern first
5. registration order (stable tiebreak — PHP 8 guarantees it)

**Only discovered routes are sorted.** A hand-written table keeps the order it
was written in, because that order is a deliberate choice somebody made visible.
Sorting it as well would mean a table behaves differently depending on whether
the application also calls `discover()`, which is a worse surprise than the one
it fixes. `priority:` is available to either if precedence must be stated
explicitly.

### Route names

`name:` is optional. Omitted, it is derived from the class: `App\Pages\HomeLayout` → `pages.home`, `App\Controllers\TodoController::show` → `controllers.todo.show`, so `url()->to('controllers.todo.show')` works immediately.

**Known risk:** renaming a method silently changes the derived name. Explicit `name:` is the escape hatch.

### Files

| File | Purpose |
|---|---|
| `src/Attributes/Route.php` | the attribute |
| `src/Routing/RouteRegistrar.php` | interface for explicit registration |
| `src/Routing/RouteRegistry.php` | scan a directory, extract, derive names |
| `src/Routing/RouteParams.php` | request-scoped matched parameters |

Plus: `Application::discover()`, `Application::registrar()`, `view()` gains parameter merging and `mount()` injection, and the CLI `route:list` boots the real app instead of requiring a `routes.php`.

Shipped with `tests/RouteDiscoveryTest.php`. The reference `app/` moved in the same change: `routes.php` is deleted, `App\Layout` is now `App\Pages\HomeLayout` with `#[Route('/')]` on the class, and `src/Routing/AppRoutes.php` registers the endpoint that is not a page.

---

## 2. JSON / API routes `[shipped]`

### What it solved

`renderException()` used to produce a plain-text body or an HTML view. There was no JSON error shape, and `Request` did not look at `Accept` at all, so a non-browser client got HTML.

Shipped as `Request::accepts()`, `Http\Format`, and `Application::errorResponse()`, with coverage in `tests/NegotiationTest.php`.

### Negotiated, not declared

The route does **not** declare that it serves JSON. The response format is negotiated from the `Accept` header, so one endpoint can serve a page and an API client without a flag.

`Request::accepts()` parses real `Accept` headers including `q` values, following the HTTP rule that **an explicit match always beats a wildcard**:

| Client sends | Result | Why |
|---|---|---|
| **htmx: no `Accept` header at all** | **HTML** | browser XHR default is `*/*` |
| browser navigation: `text/html,…,*/*;q=0.8` | HTML | explicit `text/html` |
| curl: `*/*` | HTML | wildcard only |
| axios: `application/json, text/plain, */*` | JSON | explicit json |
| `text/html, application/json;q=0.9` | HTML | explicit html has the higher q |
| `application/json` | JSON | — |

**htmx never sends an `Accept` header.** Verified against `htmx.org@2.0.4`: the string `accept` does not appear anywhere in its request-building code, and it signals its intent with `xhr.overrideMimeType('text/html')` instead. What therefore arrives is whatever the browser's XHR sends, which is `*/*`.

Two consequences, both load-bearing:

- **`*/*` must resolve to HTML, never JSON.** It is the header every htmx request arrives with. Defaulting it to JSON would break every fragment in every app.
- **htmx traffic is permanently HTML.** Because of `overrideMimeType('text/html')`, a JSON body handed to an htmx request is misparsed by the client. No endpoint may serve JSON to an htmx caller, and that must be stated rather than discovered.

`Request` is immutable, so the negotiated format is computed once in `handle()` and passed to error rendering — never mutated onto the request.

Negotiation governs the **error** body only. A handler always chooses its own success body, so `Response::json()` works regardless of what the client asked for.

### Constraint: htmx traffic is permanently HTML

Because htmx calls `xhr.overrideMimeType('text/html')`, a JSON body is **misparsed** by the client, not merely unexpected. No endpoint may serve JSON to an htmx caller, and this is not negotiable at the route level — which is one reason no `Format::Auto` override was added. An app that needs guaranteed JSON errors regardless of the client uses `onException()`, which runs before any format decision and returns a full `Response`.

### One error envelope

Every failure, framework-originated or application-originated, returns the same shape:

```json
{"error": {"status": 422, "message": "…", "errors": {"title": ["Required"]}}}
```

`errors` appears only for validation. One shape means clients parse one thing. When HTML is negotiated, behaviour is byte-identical to today.

### Known bug this fixed `[shipped]`

`ValidationException` was **not** mapped in `renderException()`. It fell through to `default => 500`, and because it was not an `HttpException` it was **rethrown** — a controller that threw it produced an uncaught fatal rather than a 422, despite its own docblock documenting the 422 usage.

Fixed alongside this work, and worth having had it been independent: it is now mapped, so `throw new ValidationException($bag)` answers 422 with the envelope's `errors` key populated. Covered by `tests/NegotiationTest.php`.

### Deferred

- CSRF exemption for token-authenticated routes
- Any user model

---

## 3. Authentication `[shipped]`

Shipped as three interfaces that stay apart on purpose: `Auth\Guard` (who is
this request), `Auth\UserProvider` (which user is that id, is this password
theirs) and `Auth\Auth` (the guards, asked once per request). The framework
stores no users and hashes no passwords — `password_hash` is used where a hash
is needed, and `DatabaseUserProvider` keeps it out of `User` so it cannot be
logged by accident.

Two seams this document already established are what it plugs into, exactly as
planned: `RequireAuth` throws rather than returning a response, so a 401 gets
the negotiated format and the error envelope for free; and CSRF exemption is
decided by the middleware that performs the check, because a route-scoped flag
arrives after the refusal.

`Auth` is bound per request rather than reset, so identity cannot survive into
a request it does not belong to.

See [../auth/README.md](../auth/README.md).

---

## 4. Explicit session write-back `[shipped]`

### The problem

`Application::handle()` opened the session at the start of every request and
never closed it at the end. `Session::beginRequest()` wrote back the
*previous* request's data before loading the current one, so the framework's own
model said the save happened at the start of the *next* request on the same
worker.

Under a long-running worker that model is wrong, and wrong in the way that
should worry you. At the default 2 × CPU — 32 workers on a 16-core machine — the
next request almost never returns to the same worker. If the deferred write-back
were the only mechanism, a sign-in would fail to survive to the following
request roughly 31 times in 32.

It was not the only mechanism. FrankenPHP flushes `$_SESSION` during per-request
teardown, which is why the application worked — verified by reading the session
file immediately after a response and finding the token already in it. But that
was undocumented runtime behaviour the framework did not control, and the
opposite of what our own code assumed. **The application was correct by
accident**, resting on a property nobody had asked for and nobody could see
fail.

### The design

**The framework owns the save. `Session::endRequest()` closes the session at the
end of a request**, called from the `finally` that already clears the context.

```php
} finally {
    $this->container->get(Session::class)->endRequest();
    $this->context = null;
}
```

Symmetry with `beginRequest()` is the entire point: reset at the start, close at
the end. It also bounds the session lock to the request rather than to the
runtime's teardown, which matters the first time an early-return path is added.

`endRequest()` is deliberately **not** gated on the CLI, where `beginRequest()` is.
There the guard exists because `$_SESSION` is an in-process array standing in for
a session and must survive between requests. Closing a session that was actually
started is correct in every SAPI — and it is what lets a test open one by hand
and prove the call runs.

### What it did not change

Nothing observable. The file was written at the same point either way. What it
changed is that *we* are the thing writing it, and the property has a test that
can fail — which is the difference between a guarantee and an observation. The
test opens a real session by pointing `session.save_path` at the workspace
`tmp/` and asserting `handle()` left it closed; under the CLI alone that
assertion would have been vacuous, because the framework never opens one there.

### Files

- `src/Http/Session.php` — `endRequest()`, beside `beginRequest()`
- `src/Application.php` — the `finally` in `handle()`
- `tests/WorkerTest.php` — two tests, both failing without the change

---

## Adding to this document

Every entry needs a status marker, and a marker has to move. When an item ships:

1. Flip it to `[shipped]` with the commit.
2. Move its *behaviour* into [ARCHITECTURE.md](ARCHITECTURE.md) and the relevant `docs/` page.
3. Move it into [CHANGELOG.md](CHANGELOG.md) under `[Unreleased]`.
4. Delete it from here — a shipped item still listed as planned is worse than no document.

A specification that describes software which does not exist is the most expensive kind of documentation to keep.
---

## 5. Composition and reachability `[shipped]`

### The problem

Two designs met and neither was right. Components composed by rendering each
other — `renderComponent()` returned markup — and a token named a class the
registry had been told about, one alias at a time. The seam between them was the
trap: a nested component was built directly, so nothing looked wrong, but the
token in its action URL named the class and was resolved through the registry
when the button was pressed. An application paid a registration per component
it nested, and the symptom was a 404 that looked like the framework was broken.

Registration could not simply be dropped. Reachability has to be known *before
the first request*, and a component tree only exists once something renders —
which, under the 32 workers the reference application ships, is usually a
different process from the one that handles the action.

### The design

**Reachability is a namespace, not a list. Composition hands back components,
not markup.**

```php
$app->components(__NAMESPACE__);   // every Component under it, named once

$logout = $this->child(LogoutButton::class);   // a live component
return $this->hx->nav()->append($logout->markup());
```

`Registry::resolve()` falls back to a namespace match, still requiring a
`Component`; `nameFor()` already returned the fully-qualified name for an
unregistered class, so the two halves met without changing it. `initialise()`
is split so `child()` can prepare without rendering, and `renderComponent()`
becomes shorthand for `child()->markup()`.

### What it costs

Token length: an unaliased component carries its fully-qualified name in every
action URL. `alias()` remains for short tokens and for rename-safety.

Security: the rule says which *code* is in scope, not that you meant each class.
An internal component under the namespace is reachable if the secret leaks,
where an alias list would not include it. `vendor/` is covered only by an
explicit `allowNamespace()`, so a third-party component package opts in by name.
The HMAC is unchanged, and it is what actually stops a client — a token can only
be replayed, never minted.

### Files

- `src/Registry.php` — `allowNamespace()`, the `resolve()` fallback
- `src/Application.php` — `components()`
- `src/Component.php` — `prepare()`, `child()`, `children()`
- `tests/RegistryTest.php`, `tests/CompositionTest.php`

---

## 6. Aggregated styles `[shipped]`

### The problem

Every component emitted a `<style>` inside its own root element, because the
root is the swap target and a sheet in `<head>` does not travel with a swap.
Correct for a component fetched alone, repetitive for a composite: five children
of one class meant five identical rule blocks, and the parent had no say in the
order its rules landed in.

That order was never chosen. A parent prepends its sheet to its own root while
its children's already sit inside them, so the parent's rules land *first* — and
CSS gives the last of two equal rules the win, which means **children win a
tie**. Any implementation of aggregation had to choose what to do with that.

### The design

**A composite may gather its subtree's CSS into one sheet at its own root.**
Returning `null` — the default — changes nothing.

```php
protected function aggregateStyles(): ?StyleOrder
{
    return StyleOrder::SelfLast;
}
```

`StyleOrder::SelfFirst` preserves the inherited order so opting in cannot
silently reorder an application; `SelfLast` is for a parent that dresses its
children. The walk is over the *component* tree rather than the markup, because
`Element` has no children accessor and the instances are held anyway.

### The cost

A child swapped on its own would arrive unstyled, its rules having moved up to a
root that was not swapped. `swapsIndependently()` declares the opposite, and such
a child is *excluded* from collection rather than duplicated — the rules appear
once and travel with every swap of it.

The framework cannot check that declaration. A component that self-swaps and
does not say so arrives unstyled after a swap and nothing reports it, which is
why the default is the safe one.

Anything rendered on its own is unaffected: `lazy()` fetches through an action,
so that render pass has no aggregating parent.

### Files

- `src/StyleOrder.php` — the enum
- `src/Component.php` — `aggregateStyles()`, `swapsIndependently()`, `child()`
  hoisting, `collectedStyleElement()`, `contributeStyles()`
- `tests/StyleAggregationTest.php`, `tests/Fixtures/Styles.php`
