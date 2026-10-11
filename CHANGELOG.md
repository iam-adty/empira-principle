# Changelog

All notable changes to this project are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

**This project is pre-1.0.** Treat `0.x` releases as a moving target: the API
can change without a major bump, though changes are always documented here.

## [Unreleased]

> **Nothing has been released.** No git tag exists, no remote is configured, and
> the package is not on Packagist. Everything below describes the working tree.

### Added

- **A component may carry its own stylesheet.** `Component::styles()` is emitted
  inside the component's root element — which is the swap target — so the CSS
  arrives again with every htmx swap. A sheet in `<head>` would be gone the
  moment a component was re-fetched on its own. Prepending rather than appending
  lets it override the global sheet on order.
- **A database you can wire up.** `$app->database([...])` builds a connection
  from configuration and makes `Db` autowirable, so a repository can take it in a
  constructor. Before this, asking the container for a `Db` threw — its
  constructor wants a `Connection`, which wants a `PDO`.
- **Dialects.** `Data\Grammar` owns how an identifier is quoted: backticks for
  SQLite and MySQL, double quotes for PostgreSQL. The validation of what counts
  as an identifier lives in `AbstractGrammar` and is inherited, so a dialect
  cannot ship with a looser check.
- **Migrations.** `Data\Migrator` plus `empira migrate`, running versioned files
  from `migrations/`. A migration is recorded only after it succeeds, so a
  failure is retried rather than skipped past.
- **Authentication.** `Auth\Guard`, `Auth\UserProvider` and `Auth\Auth`; session
  and bearer guards; `RequireAuth` middleware; `DatabaseUserProvider`. No user
  storage and no password policy — see docs/auth.md.
- **Query builder for what an ORM needs:** aggregates (`count`, `sum`, `avg`,
  `min`, `max`), `groupBy`, `having`, `leftJoin`, `rightJoin`, `exists`.
- **`#[Route]` attribute routing.** A `Layout` or controller method can declare
  its own URL: `#[Route('/todos', name: 'todos.index')]`. Repeatable, and
  targeting both classes and methods, so one class can answer several URLs.
- `Application::discover($directory, $namespace)` — scans a directory, maps each
  file to the class its path implies, and registers what it finds. A file whose
  class cannot be loaded is reported on `error_log`; abstract, interface, enum
  and non-instantiable classes are skipped.
- **Specificity ordering for discovered routes** — priority, then fewest
  parameters, then most literal segments, then longest. `/todos/new` is matched
  before `/todos/{id}` regardless of which file sorts first. Hand-written tables
  keep the order they were written in.
- `RouteParams` — the matched parameters, request-scoped and bound in the
  container. They also arrive as layout data, beneath any explicit `view()` data.
- **`mount()` injection.** A layout or controller method may declare `public
  function mount(…)` and have matched parameters and autowired services passed
  in, so `int $id` types itself and `defaults:` is unnecessary.
- `RouteRegistrar` and `Application::registrar()` — explicit registration for
  routes that are not pages: groups, shared middleware, endpoints other code
  calls.
- `methods:` on `#[Route]` accepts several verbs, registering one route per verb
  under one name.
- `Request::accepts()` — a real `Accept` parse with quality values. An explicit
  mention of a media type beats a wildcard; a missing or wildcard-only header
  expresses no preference at all.
- `Http\Format` and `Application::format()` — the format negotiated once per
  request, injectable from the container.
- One JSON error envelope, `{"error": {status, message, errors?}}`, for every
  failure when the client explicitly asks for JSON. HTML is unchanged.
- `VerifyCsrfToken` now refuses in the negotiated format.
- Documentation set: `AGENTS.md`, `ARCHITECTURE.md`, `SPEC.md`, `CHECKLIST.md`,
  `CONTRIBUTING.md`, `SECURITY.md`, `CHANGELOG.md` and a `docs/` reference.

### Changed

- **Component actions are autowired.** They were called with no arguments at
  all, which made a component unable to do anything needing a service — a login
  form could render itself and then had nowhere to go. `Router::dispatch()` now
  resolves an action's parameters by type, and `Component::container()` is
  protected so `render()` can read a service too.

  This does not widen what may be *invoked*: `guardAction()` still refuses an
  undeclared action first, and a mangled action URL is still a 403.
- **The suite runs in Docker against a real PostgreSQL.** `tools/test` starts
  the database if it is down and refuses to run if it cannot — a suite that
  quietly skipped the driver tests would be a green tick that proves nothing.
  This removed the CI workflow, which never ran because neither repository has
  a remote.
- `Layout::styles()` now wraps a `Raw` stylesheet in a `<style>` element instead
  of appending it bare into `<head>`, where a browser would hoist it away and
  discard it. An `Element` return value is still used as-is.
- `RouteCollection` allows two routes to share a name when they differ by method,
  which is what `methods: ['GET', 'POST']` produces. Two routes for the same
  verb under one name is still an error.
- `route:list` with no `--app` boots `src/App.php` in the target directory, so
  it prints the table the application really serves. It loads the project's own
  `vendor/autoload.php` first. `--app=path.php` still accepts a file returning an
  `Application`, a `RouteCollection`, or a closure.
- A layout's `mount()` must be `public`. A non-public one now raises a
  `LogicException` naming the class, rather than a bare `TypeError`.

### Fixed

- **`self::OPEN` in `AbstractGrammar` bound to the base class**, so every
  dialect emitted backticks and PostgreSQL would have rejected them. Only a live
  server caught it; string assertions passed.
- **The aggregate alias was written with a hand-typed backtick**, which is the
  exact mistake the grammar exists to prevent — invisible on SQLite and MySQL,
  a syntax error on PostgreSQL.
- **`ConnectionFactory` read `db.*` from a config section that held the bare
  keys**, found nothing, and quietly opened an in-memory database. Defaults that
  swallow a shape mistake are silent failures.
- **Discovered routes were never sorted.** `commitDiscovered()` assigned
  `usort()`'s return value — a `bool` — to the array it was meant to hold, so
  the specificity sort silently did nothing.
- **A `#[Route]` on a non-instantiable class registered a URL that could not be
  built.** The registry skipped abstract classes but not other classes that
  cannot be constructed, so a private constructor produced a route that failed
  at request time instead of being skipped at boot.
- **Route parameters leaked between requests.** An `Application` outlives the
  request in tests and long-running workers, and `RouteParams` was bound with
  `??=`, so `/todos/7` left its `id` behind for a later request that matched
  nothing.
- **`ValidationException` is mapped in `renderException()`.** It fell through to
  500 and, not being an `HttpException`, was rethrown — so `throw new
  ValidationException($bag)` produced an uncaught fatal rather than a 422, despite
  its own docblock documenting exactly that usage.
- `Response::html()` silently discarded a second argument, so
  `Response::html('x', 406)` returned a 200.
- Route group middleware was attached to `Route` but the dispatcher never
  wrapped the handler in it — a group-level auth rule did nothing.
- `Session` rotated its flash buckets on every access, so a second read in the
  same request discarded the message.
- `Component::validate()` fired its `invalid` event even when validation passed.

### Note on htmx and `Accept`

htmx sends **no `Accept` header at all** — it signals intent with
`xhr.overrideMimeType('text/html')`, verified against `htmx.org@2.0.4`. What the
server actually receives is the browser's XHR default, `*/*`.

Negotiation therefore resolves a wildcard to **HTML**, because that is the header
every fragment arrives with; treating it as a vote for JSON would break every
fragment in every application. Two consequences worth knowing:

- JSON has to be asked for explicitly (`Accept: application/json`).
- Because of `overrideMimeType`, a JSON body handed to an htmx request is
  *misparsed*, not merely unexpected. No endpoint may serve JSON to htmx.

### Initial development

Everything below was built during initial development and is present in the
working tree. It has not been tagged or published.

**Component model**
- Fluent `Element` / `ElementFactory` with the full `hx*()` attribute surface.
- `Component` base class: props, declared actions, lifecycle hooks, rendering.
- `#[Prop]` typed properties — state hydrates before user code and serialises
  back into the signed token. Coerced to the declared type, falling back to the
  default. `private`, `static` and `readonly` properties are ignored.
- `#[Action]` with `#[Get]`, `#[Post]`, `#[Put]`, `#[Patch]` and `#[Delete]`.
  The declared verb drives both the generated `hx-*` attribute and the router's
  method check, so markup and dispatch cannot drift. A mismatch is `405` with an
  `Allow` header.
- The legacy `actions()` declaration still works; the two forms union.

**Security**
- `TokenCodec` — HMAC-signed component identity. Stateless by default: props
  ride inside the token, so state integrity comes from the signature rather than
  a session. `StateStore` (`NullState`, `SessionState`) is opt-in.
- `Registry` — allow-list of classes a token may resolve to, with short
  rename-safe aliases. Rejection happens before instantiation.
- `Csrf` — session-backed protection for component actions, forwarded through a
  single inherited `hx-headers` attribute on `<body>`.
- `Http\Middleware\VerifyCsrfToken` for route-level requests.

**HTTP**
- `Routing` — closures, `[Controller::class, 'method']`, invokable controllers,
  groups, named routes, path parameters typed from `defaults`, `url()->to()` for
  reverse routing, `405` with `Allow`.
- Middleware pipeline, onion-ordered, handlers autowired from `Container`.
- `Request` / `Response` — the htmx `HX-*` headers both ways, plus cookies,
  downloads, streaming, cache headers and the `HX-*` response helpers.
- `Session` with flash messages.

**Views**
- `Layout` and `Region` — one route serves a full document to a browser and a
  single named region to htmx. Boosted links receive the document.

**Support**
- Autowiring `Container` with cycle detection and `call()` injection.
- `Validation` — 15 rules, an `ErrorBag`, no coercion of the input.
- `Logging` — levels, a newline-safe file logger, a null logger.
- `Data` — PDO wrapper and a fluent query builder with strict identifier
  validation and bound parameters.
- `Config` — `.env` parsing and dot-notation access with typed getters.
- `Testing` — `TestClient` / `TestResponse` for application authors.
- CLI (`empira`) — `make:component`, `make:controller`, `route:list`, `serve`.

### Known limitations

True and unfixed at this point:

- Routing is first-match-wins with no specificity sort, so `/todos/new` must be
  declared before `/todos/{id}`. Scheduled in `SPEC.md`.
- JSON **errors** are negotiated, but a response is only JSON when a handler
  returns `Response::json()`. There is no route-level format declaration.
- No authentication. See `SECURITY.md`.
- No CSRF exemption for token-authenticated routes.
- No CI configuration.
- No git remote; nothing is published anywhere.