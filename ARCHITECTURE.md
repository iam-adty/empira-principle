# Architecture

> **Status: describes shipped code.** Verified against `744 tests, 1372 assertions`.
> Planned changes are in [SPEC.md](SPEC.md).

How a request flows through Empira, and why it is built this way.

## The one idea

htmx's model is that the server owns the page: an element that changes something is described by a URL, and the server returns the new markup. Normally that means hand-writing `hx-post`, `hx-target` and `hx-swap` in HTML — and keeping those in sync with whatever endpoint the server actually exposes.

Empira inverts it. **The PHP class is the source of truth.** The class declares a method as an action, and the framework derives everything from that: which `hx-*` attribute to emit, where the swap lands, and which HTTP method the router will accept. The markup and the dispatcher read from one declaration, so they cannot disagree.

## Request lifecycle

```
                      ┌─────────────────────────────────────┐
  HTTP request  ──────▶ Application::handle()                │
                      │  1. build Context (request, response,│
                      │     registry, tokens, state, container)
                      │  2. bind per-request services into   │
                      │     the container                   │
                      └──────────────┬──────────────────────┘
                                     │
                      ┌──────────────▼──────────────────────┐
                      │  Pipeline::send()  (middleware)     │  ← onion: in, handler, out
                      └──────────────┬──────────────────────┘
                                     │
                 ┌───────────────────┴───────────────────┐
                 │                                       │
      component action?                           matched a route?
      URL under /empira/<token>/<action>          RouteCollection::match()
                 │                                       │
      Router::dispatch()                        container->call(handler, params)
                 │                                       │
      1. TokenCodec::decode()  — verify HMAC         returns string | Element | Response
      2. Registry::resolve()    — allow-list              │
      3. new Component(...)     — build from props            │
      4. guardAction()          — declared? public? verb?     │
      5. beforeAction()         — CSRF verify                 │
      6. $action()              — your logic                  │
      7. afterAction()          — persist state               │
      8. toHtml()               — re-render the component     │
                 │                                       │
                 └───────────────────┬───────────────────┘
                                     │
                      ┌──────────────▼──────────────────────┐
                      │  normalise → Response → send()       │
                      └─────────────────────────────────────┘
```

Component action URLs are matched **before** named routes, because they live under a reserved prefix (`/empira`) and are an implementation detail of the component system.

## Layers

```
Component      UI: props, actions, render()          ← what you write
Element        fluent markup; all hx* helpers
Application    entry point; dispatch; exceptions
──────────────────────────────────────────────────────────────
Routing        Route, RouteCollection, UrlGenerator, Controller
Http           Request, Response, Session, Middleware, Pipeline
Container      autowiring
View           Layout, Region
──────────────────────────────────────────────────────────────
Security       Csrf, Registry, TokenCodec
State          StateStore + implementations, PropBag, ActionMap
Validation     Validator, ErrorBag
Data           Connection, Query, Db
Config         Env, Config
Logging        Logger, FileLogger, NullLogger
Testing        TestClient
Console        CLI
```

The upper layer knows nothing about routing; the lower layers know nothing about components.

## State: stateless by default

This is the decision that most shapes the framework.

**A component's props ride inside its signed action URL.** No session, no database, nothing to expire. The flow:

```php
$this->count += 5;              // action mutates a typed property
   ↓  render() builds action URLs
   ↓  token() → props exported into the payload
   ↓  HMAC over payload
/empira/eyJ2IjoxLCJj.../increment   ← the next click carries the new count
   ↓  Router::dispatch() verifies the HMAC
   ↓  props hydrate back onto the typed properties
$this->count  // 5 again
```

The consequence that matters: **the client cannot promote itself.** It can edit the URL all it likes; the signature will not verify. State integrity comes from the HMAC, not from a server-side session.

The cost is URL length. A component that opts into `stateful()` moves its props into a `StateStore` instead, keeping a small constant-size token. That ties the component to one user's session, so it is opt-in, not default.

## Why the allow-list exists

A token carries a class name. Without a check, a crafted request could name any class on the autoloader and have it instantiated. `Registry` sits in front of that:

```php
$app->registry()
    ->alias('counter', Counter::class)      // short, rename-safe name in the URL
    ->alias('todos', TodoList::class);
```

Aliases keep tokens short and survive a namespace move — renaming a class does not invalidate links sitting in users' histories. Anything unregistered is refused before `new` is reached.

## Why routing is first-match-wins

`RouteCollection::match()` walks routes in declaration order and takes the first whose pattern and method match. This is the simplest rule that explains itself:

```php
$routes->get('/todos/new', NewTodo::class);   // specific — must come first
$routes->get('/todos/{id}', ShowTodo::class); // general — catches the rest
```

The order above is a hand-written table, so it is the author's to maintain. Routes
**discovered** from `#[Route]` attributes have no such order to inherit — it would
come from the filesystem — so they are sorted by specificity before joining the
table: `/todos/new` beats `/todos/{id}` because it has fewer parameters. See
[docs/routing.md](docs/routing.md#ordering-discovered-routes).

## Middleware is onion-shaped

```php
$app->use(new Recording('first'), new Recording('second'));
// → first:in, second:in, handler, second:out, first:out
```

Each layer runs before the handler and again after it, which is what lets a layer measure duration or add a header to a response it did not produce itself. Handlers are autowired, so a middleware asks for what it needs by type.

## Content negotiation

For pages, the framework is HTML-only: `Layout::render()` returns a full document to a browser and a single region to htmx (detected via `HX-Request`, with boosted links treated as real navigations).

Errors are different. A client that explicitly asks for JSON with
`Accept: application/json` gets a single `{"error": {…}}` envelope and the right
status code, while everything else stays HTML. The rule that makes this safe is
that **htmx sends no `Accept` header at all**, so a wildcard resolves to HTML —
see [docs/json-errors.md](docs/json-errors.md).

## Data sits below everything

```
Request → middleware → routing → handler
                                 ↓
                          Db / Query → Connection → PDO → driver
```

Nothing above `Db` knows what a database is. A handler asks for `Db` by type and
the container supplies one, which is what lets a repository — or, later, an
ORM's model — be an ordinary autowired class with no framework wiring of its own.

The one thing that varies by database is quoted identifiers, and that lives in a
`Grammar`. Everything else in `Query` is portable, and the validation of what
counts as an identifier does not vary at all: identifiers cannot be bound by any
driver, so they travel inside the SQL string, which makes them an injection
vector. A dialect inherits the check rather than restating it.

See [docs/data.md](docs/data.md).

## Identity is resolved before anything asks

`Auth` is bound per request, beside `Request`, `Format` and `RouteParams`, and
consults its guards once. It is not a singleton because it does not need
resetting — a fresh instance cannot remember the request before it.

The distinction the framework is careful about is *who* versus *which
credential*: a session-authenticated request and a bearer-authenticated request
both have a user, and only the second is exempt from CSRF. One is protected by
an ambient cookie the browser attaches by itself; the other is not, and a
cross-site page cannot forge it.

See [docs/auth.md](docs/auth.md).

## Testing strategy

The suite drives the framework the way htmx does — render a page, extract the signed URLs its own markup advertises, post to one, assert on the returned fragment. That means a change that breaks wiring fails a test without anyone asserting on markup by hand.

`TestClient` wraps this for application authors:

```php
$client = new TestClient($app);

$client->get('/todos')->assertOk()->assertSee('buy milk');
$client->asHtmx()->post('/empira/<token>/add', ['title' => 'walk dog'])->assertSee('walk dog');
```

## Further reading

- [AGENTS.md](AGENTS.md) — conventions, invariants, known traps
- [SPEC.md](SPEC.md) — planned work with its rationale