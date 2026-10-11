# Architecture

> **Status: describes shipped code.** Verified against `587 tests, 1060 assertions`.
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

The sharp edge is that the order is yours to maintain. A specificity sort is [planned](SPEC.md).

## Middleware is onion-shaped

```php
$app->use(new Recording('first'), new Recording('second'));
// → first:in, second:in, handler, second:out, first:out
```

Each layer runs before the handler and again after it, which is what lets a layer measure duration or add a header to a response it did not produce itself. Handlers are autowired, so a middleware asks for what it needs by type.

## Content negotiation

Today the framework is HTML-only for pages: `Layout::render()` returns a full document to a browser and a single region to htmx (detected via `HX-Request`, with boosted links treated as real navigations).

JSON responses for non-htmx clients are [planned](SPEC.md), including a single error envelope. The design decisions for that are already made; nothing is built yet.

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