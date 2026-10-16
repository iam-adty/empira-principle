# Changelog

All notable changes to this project are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

**This project is pre-1.0 and has never been released.** The API can change at any
commit, though changes are always documented here. The development line is
`0.1.x-dev`, a Composer branch alias rather than a release.

When a first release does happen it will be `0.1.0-alpha.N`, **not** `0.1.0`.
Composer parses `0.1.0` and `0.0.1` as `stable`, so a plain `0.x` tag would read
as a release — which is the opposite of what an unreleased project needs to
signal. `alpha` is honestly non-stable and promises nothing about a 1.0 API.

## [Unreleased]

> **Nothing has been released.** No git tag exists in any repository, and the
> package is not on Packagist. All five repositories are public on GitHub and
> everything below describes `main`.

### Added

- **`Empira\Application\` — the seam an application is built from.** `Modules`,
  `LoginPage`, `Overrides`, `ProvidesSecret`, `ProvidesLogger`, `ProvidesStorage`,
  `EnvSecret`, `StderrLogger` and `SqliteStorage`. All nine were in the `app/`
  skeleton, which is the one directory in the workspace belonging to no package
  and therefore tested by nobody.

  `Modules` is variadic and says one thing — which modules load:

  ```php
  #[Modules(AuthModule::class, DebugToolsModule::class)]
  #[LoginPage(LoginLayout::class)]
  #[Overrides(secret: EnvSecret::class, logger: StderrLogger::class, storage: SqliteStorage::class)]
  final class App extends Application {}
  ```

  A variadic must be the last parameter, which is why `login:` cannot stay on
  `Modules` and became `#[LoginPage]` instead. That constraint produced a better
  shape than the one it replaced: a module's identity and a module's
  configuration are different facts.

  `Empira\Application` the class and the `Empira\Application\` namespace coexist,
  which is legal and deliberate — the namespace is the vocabulary an application
  uses to describe itself.

- **`monolog/monolog` is now a dependency**, for `StderrLogger` alone.
  `Application::log()` still takes a `LoggerInterface` and `principle/src` outside
  `Application/StderrLogger.php` still names no concrete implementation. The
  framework ships *a* working default rather than none; an application supplying
  its own logger never touches Monolog.
- **A `Response::emit()` that does not exit.** `send()` emits and then exits,
  which is right for a process with nothing else to do and fatal for a
  long-running worker: FrankenPHP restarts the script, so an `exit` in the
  request path earns one request per boot and none of the reason to run a
  worker. See [docs/workers.md](docs/workers.md).
- **A documented worker-safety contract.** What the framework resets per
  request, what it deliberately keeps, and the rules for holding new code to
  it — [docs/workers.md](docs/workers.md).
- **`Application::respond()` — the worker's entry point, beside `send()`.**
  `send()` emits and exits, which is right for a process with nothing to come
  back to. Under a worker it is fatal: the script ends, FrankenPHP restarts it,
  and the process serves exactly one request — correct, and none of the point.

  `respond()` is the same call without the exit, so a front controller reads the
  same way whichever runtime it is under. `handle(Request): Response` is
  untouched and stays public, which is what lets `TestClient` drive the whole
  framework with synthetic requests and no running server.

  A throwable from `handle()` propagates out of `respond()` rather than being
  swallowed, because in a worker `set_exception_handler` only fires when the
  script ends — which never comes. Error policy belongs to whoever owns the
  runtime.
- **`$app->components(__NAMESPACE__)` — reachability by namespace.** Every
  `Component` under a prefix becomes reachable by token without being named.
  Registration had become the price of nesting, and it was paid for components
  whose only fault was being forgotten: a component only ever rendered by another
  one rendered perfectly and then answered 404 when somebody pressed its button.

  The rule says which *code* is in scope, not that you meant each class — a class
  under the prefix that is not a `Component` is still refused, and `vendor/` is
  covered by nothing but an explicit `allowNamespace()`. What it gives up is
  "you meant this specific one": an internal component in that namespace is
  reachable if the secret leaks, where an alias list would not include it. The
  HMAC is unchanged and is what actually stops a client, since a token can only
  be replayed, never minted.
- **`Component::child()` returns the child, and `children()` reports what a
  component composed.** Composite means a component containing components is the
  same kind of thing as one that does not, and that only holds if the parent can
  hold the children rather than their rendered markup. `renderComponent()` is
  now shorthand for `child()->markup()`, and still works.

  A component may be composed without ever registering it, which is what makes
  nesting free.
- **`aggregateStyles()` — one stylesheet for a composite's whole subtree.**
  Returning a `StyleOrder` gathers this component's CSS and its children's into
  a single `<style>` at its root: identical rulesheets once rather than once per
  instance, and the composite's own rules placed deliberately among its
  children's rather than wherever positional insertion happened to put them.
  `null`, the default, changes nothing.

  `StyleOrder` exists because the order the framework produced was never chosen.
  A parent prepends its sheet while its children's sit inside them, so the
  parent's rules land first — and CSS gives the last of two equal rules the win,
  meaning children win a tie. `SelfFirst` preserves that so opting in does not
  silently reorder an application; `SelfLast` is for a parent that dresses its
  children.

  A child swapped on its own would arrive unstyled once its rules move up, so
  `swapsIndependently()` declares the opposite: it keeps its own sheet *and* is
  left out of the parent's, so the rules appear once and travel with every swap.
  The framework cannot check this, which is why the default is the safe one.
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
  storage and no password policy — see ../auth/README.md.
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
- Documentation set: `AGENTS.md`, `ARCHITECTURE.md`, [SPEC.md](https://github.com/iam-adty/empira-dev/blob/main/planning/SPEC.md), [CHECKLIST.md](https://github.com/iam-adty/empira-dev/blob/main/planning/CHECKLIST.md),
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
  This removed the CI workflow, which never ran.
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

- **`SqliteStorage` answers a relative path.** It is the framework's default now
  rather than the skeleton's, so `dirname(__DIR__)` resolves into `vendor/` — a
  skeleton's database landing inside the package it was installed from, green in
  a test run and gone with the next `composer install`. It answers
  `storage/app.sqlite` and the application resolves that against its own root.
  `ProvidesStorage` allows either form, so an absolute path still passes through.

- **`EnvSecret` names `.env` without claiming to know where it is.** Its refusal
  interpolated its own directory, which was true in the application and actively
  wrong in a package — it would have pointed at `vendor/…/principle/.env`.

- **`ClassScanner` no longer logs about interfaces and traits.** `class_exists()`
  is false for both, so an application with either under a scanned directory got
  a "no autoloadable class" warning about a file exactly where PSR-4 said it
  should be.

- **`Application::orm()` with no configuration reads the environment.** It
  returned `[]` when no `Config` was bound, which was always, so the ORM opened
  `:memory:` and every query answered. `orm($paths)` — entity paths in the
  configuration argument — is now refused rather than silently ignored.

- **The suite could pass having run almost none of itself.** PHPUnit chooses its
  exit status in `ShellExitCodeCalculator`, which nothing reaches if the process
  exits first — so an `exit` or `die` in the code under test truncated the run,
  printed no summary, and still returned **0**. Measured rather than suspected: a
  fixture with an `exit` in it stopped the run after 21 of 790 tests and
  `composer test` reported success.

  Half of it is a setting: `failOnEmptyTestSuite="true"` in `phpunit.xml` makes a
  suite that matched nothing a failure, which it had not been. The other half
  cannot be a setting, because no PHPUnit flag is reached. `tools/test` captures
  the output and refuses a run with no summary line, which is the only thing that
  can tell a finished run from a truncated one. `tee` keeps the suite streaming,
  so nothing is lost to find out late.

  The habit that survives is simpler than the mechanism: read the summary line,
  not the exit status.

- **A request never handed the session back.** `handle()` opened the session and
  never closed it, leaving the write-back to happen at the start of whatever
  request this worker served *next* — which, at the 32 workers the reference
  application ships, is usually a different worker entirely. A sign-in would
  have failed to survive to the next request roughly 31 times in 32.

  It did not, because FrankenPHP flushes `$_SESSION` during per-request teardown.
  The application was correct by accident: working, and resting on undocumented
  runtime behaviour that contradicted the framework's own model. `Session::
  endRequest()` closes it in the `finally` that already clears the context, so
  the lock and the write-back belong to the request that made the change.

  Two tests, both failing without it. The obvious one — assert `handle()` left
  no session open — would have been vacuous under the CLI, where the framework
  never opens one, so the test opens a real session by pointing
  `session.save_path` at the workspace `tmp/` first.

- **A session survived the request that opened it, in a long-running worker.**
  `Session` is a container singleton whose `begun` latch stops its flash buckets
  rotating more than once — which, in a worker, means never. So `session_start()`
  ran once per *process*, not per request: the session id stayed bound and
  `$_SESSION` stayed populated, and every request after the first read and
  wrote the first visitor's data. `regenerate()` defended the wrong id, so the
  fixation defence protected a session nobody was using. It is a cross-user data
  leak, silent, with no error and nothing in the log — and php-fpm hid it,
  because every request got a fresh process.

  `Session::beginRequest()` now closes the session and clears the latch before
  each dispatch. The fix is deliberately a *reset* rather than a fresh instance
  per request: `$app->auth(new SessionGuard($app->session(), …))` captures the
  session at boot, so replacing the instance per request would leave every guard
  reading a session nothing ever writes to. `WorkerTest` drives two requests
  through one `Application`; five of its tests fail without this.
- **A context outlived its request.** `Application::$context` was replaced per
  dispatch but never cleared, so a `view()` called between requests mutated the
  `Response` object that request had already been sent.
- **`$app->use()` could freeze a middleware's view of the world.** An instance
  passed to `use()` is built at boot and reused unchanged, so under a worker it
  keeps the `Format` and `Auth` of whichever request ran first — a JSON client
  answered with an HTML page, or a session-authenticated POST exempted from CSRF
  on the strength of another request's bearer token. `use()` now says to pass the
  class-string, which the pipeline rebuilds per request.
- **A worker's statement log grew without bound.** `Connection` is opened once
  and kept, so `queries()` was every statement the process had ever run. It is
  cleared between requests.

  Two hazards an audit raised did not survive checking and were not "fixed",
  because there is nothing to fix: raw transaction control is not exposed
  anywhere — `Connection::transaction()` wraps begin/commit/rollback in
  try/catch, so no request can leave one open for the next — and the three
  static caches (`PropBag`, `ActionMap`, `ElementFactory`) are keyed by class
  name or immutable, so they cannot grow per request.

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

True and unfixed **at the end of initial development** — a snapshot, not the
current state. Several of these were fixed long ago: routing is specificity-sorted,
authentication shipped as a separate module, and all five repositories are public.
The repositories exist now; this list is what was true before they did. Current
gaps are in [dev/planning/CHECKLIST.md](https://github.com/iam-adty/empira-dev/blob/main/planning/CHECKLIST.md).

- Routing is first-match-wins with no specificity sort, so `/todos/new` must be
  declared before `/todos/{id}`. Scheduled in [SPEC.md](https://github.com/iam-adty/empira-dev/blob/main/planning/SPEC.md).
- JSON **errors** are negotiated, but a response is only JSON when a handler
  returns `Response::json()`. There is no route-level format declaration.
- No authentication. See `SECURITY.md`.
- No CSRF exemption for token-authenticated routes.
- No CI configuration.
- No git remote; nothing is published anywhere.