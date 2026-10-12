# Checklist

> **Status: live.** Checked items are struck, not deleted — the history is the
> point. If an item here disagrees with the code, the code wins and this file is
> a bug.

Framework: `iam-adty/empira-principle` · app: `iam-adty/empira-app`
Current: **502 framework tests / 1127 assertions**, **45 app tests / 128 assertions**.

---

## Shipped

### Core component model
- ~~Fluent `Element` builder with the full `hx*()` surface~~
- ~~Signed component tokens (`TokenCodec`, HMAC over props)~~
- ~~Registry allow-list with short, rename-safe aliases~~
- ~~Stateless-by-default props riding the token; opt-in `StateStore`~~
- ~~CSRF for component actions, inherited via `hx-headers` on `<body>`~~

### Typed state — 2026-10-10
- ~~`#[Prop]` on typed properties; props hydrate before user code runs~~
- ~~Coercion to the declared type, falling back to the default~~
- ~~Object-typed props never instantiate a class named by a token~~
- ~~`private` / `static` / `readonly` props ignored~~

### Actions and verbs — 2026-10-10
- ~~`#[Action]` + `#[Get]/#[Post]/#[Put]/#[Patch]/#[Delete]`~~
- ~~The declared verb drives both the `hx-*` name and the router's method check~~
- ~~405 with an `Allow` header on a verb mismatch~~
- ~~`actions()` kept working; the two declaration styles union~~

### HTTP stack
- ~~Routing: closures, `[Class, 'method']`, groups, named routes, `url()->to()`~~
- ~~Middleware pipeline, onion-ordered, autowired~~
- ~~Session with flash messages~~
- ~~Request/Response: cookies, downloads, streaming, cache headers~~

### Views
- ~~`Layout` + `Region`; one route serves the document or a single region~~
- ~~Boosted links still receive a full document~~

### Support
- ~~Autowiring container with cycle detection~~
- ~~Validation: 15 rules, error bag, no coercion~~
- ~~Logging: PSR-3, with a Monolog implementation in the application~~ — was
  a hand-rolled `Logger`/`LogLevel`/`FileLogger`, removed 2026-10-12 in favour
  of `psr/log` so the logger an application already has is the one the framework
  writes to. The framework records an exception that ends a request, before
  re-throwing it; it did not record anything at all before.
- ~~Persistence: Doctrine ORM + DBAL + Migrations~~ — replaced a hand-written
  PDO query builder, dialect layer and migrator (1433 lines, gone). One reader
  for `DB_*`, a real version ledger, and `empira migrate`/`--status`/`--step=down`.
- ~~Config: `.env` parsing, dot-notation, typed getters~~
- ~~`TestClient` for application authors~~
- ~~CLI: `make:component`, `make:controller`, `route:list`, `serve`, `migrate`~~

### Packaging — 2026-10-11
- ~~Split into two Composer packages: `principle/` (library) and `app/` (project)~~
- ~~Namespace `Empira\` → `Empira\`~~
- ~~`app` links the library through `franzl/studio` (`dev-main`)~~
- ~~Two git repositories, one per package~~

### Fixes — 2026-10-11
- ~~`<style>` was missing around a layout's stylesheet~~
- ~~`Response::html()` silently discarded a second argument~~
- ~~Group middleware was attached but never executed~~
- ~~Session rotated its flash buckets on every access~~
- ~~`validate()` fired its event even on success~~
- ~~A session outlived the request that opened it, leaking between users in a worker~~
- ~~The context outlived its request, so a view between requests mutated a sent response~~
- ~~Middleware instances froze the first request's `Format` and `Auth`~~
- ~~A worker's statement log grew without bound~~

### Composition — 2026-10-11
- ~~`$app->components(__NAMESPACE__)` — reachable by namespace, not by a list~~
- ~~`Component::child()` returns the child; `children()` reports what was composed~~
- ~~A nested component needs no registration — the 404 trap is gone~~
- ~~`renderComponent()` is shorthand for `child()->markup()`~~

### Runtime entry points — 2026-10-11
- ~~`Application::respond()` — the worker's `send()`, without the exit~~
- ~~The gate cannot be passed by a run that did not finish~~
- ~~`failOnEmptyTestSuite` — a suite that ran nothing is a failure~~

### Aggregated styles — 2026-10-11
- ~~`aggregateStyles()` — one sheet for a composite's whole subtree~~
- ~~`StyleOrder` — where a composite's rules sit among its children's~~
- ~~Identical rulesheets once per page, not once per instance~~
- ~~`swapsIndependently()` — a child that keeps its own sheet and is not collected~~

### Long-running workers — 2026-10-11
- ~~`Session::beginRequest()` resets between requests; five tests fail without it~~
- ~~`Application::respond()` — a non-exiting send, so a worker survives more than one request~~
- ~~The context is cleared in a `finally`~~
- ~~The EntityManager's identity map is cleared per request~~ — was the
  connection's statement log, which went with the data layer; same trap, same
  place it is handled now
- ~~The shared-store rule: worker memory holds only re-derivable data~~

### Doctrine — 2026-10-12
The framework took its first runtime dependencies and gave up the
zero-dependency claim, which is no longer true and is not papered over. Doctrine
was chosen for the standardised parts; components, routing, views and the htmx
wiring are still the framework's own.

- ~~`psr/log` — the framework asks for PSR-3 and supplies no logger unless the
  application hands one over~~
- ~~`Config\DatabaseConfig` — one reader for `DB_*`; two readers with different
  defaults had `empira migrate` reporting success against a database that
  vanished on exit~~
- ~~`Console\Bootstrap` — an application registers its own commands; the console
  finds them by running `bootstrap/app.php`~~
- ~~`Persistence\EntityManagerFactory` — `orm()`, and a `clear()` beside
  `Session::beginRequest()`~~
- ~~`Auth\User` is an entity, `DoctrineUserProvider` reads it~~
- ~~`src/Data/` deleted — 1433 lines the ORM had already replaced~~
- ~~`Request::ip()` — the socket address, and proxy headers only when
  `Application::trustProxies()` says so; a log full of client-chosen addresses
  is worse than none, because it looks like evidence~~
- ~~An `onHttpError` view no longer discards the headers it was handed~~ — it
  chooses the body, not what the response means

### Operations — 2026-10-12
- ~~Migrations and seeding are deploy steps — `empira migrate`, `empira seed` —
  because a worker boots once per thread and a boot that migrates has every
  thread racing through the same migration~~
- ~~The application refuses to start on the placeholder `APP_SECRET` outside a
  local environment~~

---

## Next

In priority order. See [SPEC.md](SPEC.md) for the design behind each.

### 1. Attribute routing `[shipped]` — 2026-10-11
- ~~`Attributes/Route.php` — `TARGET_CLASS|TARGET_METHOD|IS_REPEATABLE`~~
- ~~`Routing/RouteRegistrar.php`, `RouteRegistry.php`, `RouteParams.php`~~
- ~~Specificity sort (priority → fewest params → most literal → longest → registration)~~
- ~~`Application::discover()` and `Application::registrar()`~~
- ~~`view()` merges route params; `mount()` injected by reflection~~
- ~~Auto-derived route names~~
- ~~CLI `route:list` boots the real app~~
- ~~Tests: discovery, ordering, repeatable attribute, mount injection, middleware, skips~~
- ~~Migrated `app/` to `#[Route]`; `app/routes.php` deleted~~

**Two bugs the tests caught, both worth remembering.** `commitDiscovered()`
assigned `usort()`'s return value — which is `true` — to the sorted array, so
nothing was ever sorted. And the registry skipped abstract classes but not other
non-instantiable ones, so a `#[Route]` on a class with a private constructor
registered a URL that could not be built.

### 2. JSON / API routes — 2026-10-11
- ~~`Request::accepts()` — real q-value parse; explicit mention beats wildcard~~
- ~~Format negotiated once in `handle()`, readable via `Application::format()`~~
- ~~One error envelope: `{"error": {status, message, errors?}}`~~
- ~~**Fixed:** `ValidationException` mapped to 422 — it used to rethrow and fatalise~~
- ~~`VerifyCsrfToken` format-aware~~
- ~~29 tests: accept matrix, envelope per status, HTML path byte-identical~~

htmx is protected by the rule that made it necessary: **htmx sends no `Accept`
header at all**, so a wildcard resolves to HTML. Verified against
`htmx.org@2.0.4`, whose intent signal is `overrideMimeType('text/html')`.

### 3. Documentation — 2026-10-11
- ~~`AGENTS.md`, `ARCHITECTURE.md`, `SPEC.md`, this file~~
- ~~`CONTRIBUTING.md`, `CHANGELOG.md`, `SECURITY.md`~~
- ~~`docs/` reference pages — 8, including `json-errors.md`~~
- ~~`.opencode/skills/` (4) + `opencode.json`~~

### 4. Operations — 2026-10-11
- ~~CI — removed; the suite runs locally through Docker (see `docs/testing.md`)~~
- ~~Decided: `.opencode/` and `tests/` are **export-ignore**d — the
  published package carries `src/`, `docs/`, `bin/` and the markdown docs. The
  skills point at this repository's own `tests/` paths, which do not exist in a
  consumer's `vendor/`; contributors get them from the git checkout~~
- ~~Push both repositories to a remote~~ — **not doing it.** Local git only, by
  decision. Nothing has ever been pushed, no tag exists, and the suite is the
  gate because there is nowhere for it to run otherwise. Revisit only if the
  package is actually published to Packagist; the published tarball's shape is
  already fixed above, and a first release would need that constraint changing.

### 5. Deferred, by decision
- ~~`Format::Auto` override on `#[Route]`~~ — **not adding it.** htmx's
  `overrideMimeType` means htmx traffic is permanently HTML, so a route-level
  force would be a rule with no correct use. `onException()` covers apps that
  want guaranteed JSON errors.

---

## Deferred

- ~~Authentication~~ — shipped 2026-10-11. Guards, `UserProvider`, per-request
  `Auth`, `RequireAuth`, bearer and session guards. See
  [docs/auth.md](docs/auth.md).
- ~~CSRF exemption for token-authenticated routes~~ — shipped 2026-10-11. A
  valid bearer token is exempt because an `Authorization` header is not an
  ambient credential, plus a per-route `#[Route(csrf: false)]`. A
  session-authenticated POST with no token is still refused, and a test fails
  if that ever changes.
- **Explicit session write-back `[planned]`** — `handle()` opens the session and
  never closes it, so the save is FrankenPHP's per-request teardown rather than
  ours. Verified working, and resting on undocumented runtime behaviour our own
  `beginRequest()` contradicts. At 2 × CPU a deferred write-back would lose a
  sign-in 31 times in 32. Design, evidence and the fix:
  [SPEC.md §4](SPEC.md#4-explicit-session-write-back-planned).
- **Session isolation is only probabilistically checked at the default.** The
  stack ships 2 × CPU workers, and `verify` no longer pins one, so a per-worker
  state leak surfaces only when two consecutive requests for one session land on
  the same worker — about 1 in 32. Ten rounds catch roughly a quarter of such a
  bug. Re-run the check with `FRANKENPHP_WORKERS=1` whenever a state bug is
  suspected; the override is kept for exactly that.

---

## Maintenance rules

1. Strike, never delete — the history is why this file is trustworthy.
2. Every shipped item gets a date.
3. If code and checklist disagree, the code is right and this file is the bug.
4. Anything marked `[planned]` that turns out to be wrong should be corrected **before** it is built, not after.