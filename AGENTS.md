# AGENTS.md

> **Status: describes shipped code.** Current behaviour, verified against `790 tests, 1451 assertions`.
> Intended-but-unbuilt work lives in [SPEC.md](SPEC.md), marked `[planned]`.

Guidance for AI agents and new contributors working in the Empira framework.

## What this is

A zero-dependency PHP framework where **htmx lives in PHP classes, not HTML attributes**. A component declares its state as a typed property and its behaviour as methods; the framework generates every `hx-*` attribute, the swap target, and a signed endpoint.

```
namespace IamAdty\Empira;     PHP ^8.4, MIT
```

## Commands

```bash
composer test                                    # the whole suite
vendor/bin/phpunit tests/RoutingTest.php         # one file, faster loop
vendor/bin/phpunit --filter=testRawStyles        # one behaviour

find src tests -name '*.php' -print0 | xargs -0 -n1 php -l   # lint sweep

php bin/empira list                              # the console
php bin/empira make:component Counter            # generators write to src/components
php bin/empira route:list                        # routes an app registers
```

**The test suite is the only gate** — `composer test` runs it, in Docker,
against a real PostgreSQL. There is no CI: neither repository has a remote, and
GitHub Actions only executes for a repository hosted on GitHub.

`tools/test` starts the database if it is down and **refuses to run** if it
cannot — a suite that quietly skipped the driver tests is a green tick that
proves nothing. It also refuses a run that **finished early**: PHPUnit's own exit
status cannot report truncation, so the script checks that a summary line was
printed. That check is why `composer test` means what it says — read
[`Fixed`](#fixed--do-not-reintroduce) for the measurement behind it.

## Where things live

93 source files across 19 namespaces. The 16 files at `src/` root are the core; everything else is a subsystem.

| Path | Responsibility |
|---|---|
| `src/Application.php` | Front door. `handle()`, `render()`, `view()`, `renderException()`, the middleware pipeline, the container |
| `src/Component.php` | Base class for all UI. Props, actions, lifecycle hooks, token minting |
| `src/Context.php` | Per-request services handed to every component |
| `src/Element.php` | Fluent tag/attribute builder. All the `hx*()` helpers live here |
| `src/ElementFactory.php` | Creates elements, optionally wired to a component's action resolver |
| `src/Raw.php` | A string emitted verbatim, bypassing escaping |
| `src/Html.php` | Escaping, attribute serialisation, JSON |
| `src/Registry.php` | What a token may resolve to: by alias, by class, or by namespace |
| `src/TokenCodec.php` | HMAC-signed component identity (`base64url(payload).base64url(hmac)`) |
| `src/Router.php` | Resolves an action URL back to a component and runs the action |
| `src/functions.php` | The global `hx()` helper |
| `src/Attributes/` | `Prop`, `Action`, `Route`, and the verb markers `Get`/`Post`/`Put`/`Patch`/`Delete` |
| `src/State/` | `StateStore` + implementations, `PropBag` (typed prop hydration), `ActionMap` |
| `src/Http/` | `Request`, `Response`, `Session`, `Middleware`, `Pipeline` |
| `src/Routing/` | `Route`, `RouteCollection`, `RouteRegistry` (attribute discovery), `RouteParams`, `RouteRegistrar`, `UrlGenerator`, `Controller`, `HttpException` |
| `src/View/` | `Layout`, `Region` — the page shell and its swappable areas |
| `src/Container/` | Autowiring service container |
| `src/Validation/` | `Validator`, `ErrorBag`, `ValidationException` |
| `src/Data/` | `Connection`, `Query`, `Db`, `Grammar` + dialects, `Migrator`, `ConnectionFactory` |
| `src/Config/` | `.env` parsing, dot-notation config |
| `src/Logging/` | `Logger`, `FileLogger`, `NullLogger`, `LogLevel` |
| `src/Auth/` | `Auth`, `Guard`, `UserProvider`, session/bearer guards, `RequireAuth` |
| `src/Security/Csrf.php` | Session-backed CSRF for component actions |
| `src/Testing/` | `TestClient`, `TestResponse` |
| `src/Console/` | CLI kernel and commands |

## Non-negotiable invariants

Break one of these and a component becomes exploitable or silently broken.

1. **A token may only resolve a reachable class.** `Registry` is the security boundary. Never construct a component from a class name without going through it. Reachable means an alias, an exact class, or a `Component` under a namespace given to `$app->components()` — nothing else, and nothing that is not a `Component`.
2. **The HMAC covers the props.** If that stops covering them, a client can promote itself by editing a URL.
3. **Actions must be reachable only if declared.** `actions()` or `#[Action]`/a verb attribute. Public + not declared by the framework base = refused.
4. **Private, static and inherited-from-`Component` methods are never callable as actions**, regardless of how they are declared.
5. **`Raw` is unescaped.** It is for values you control. Never request input.
6. **The verb an action declares must be the verb the router accepts** — one attribute feeds both the `hx-*` name and the method check, so they cannot drift.

## Conventions

- **Edit source with the Edit tool.** Not `sed -i`, not `python3 -c`, not a
  heredoc that rewrites a file. Those have no review step, they fail silently
  when the pattern does not match, and they leave a half-applied file when they
  do. Shell is for *reading* — `grep`, `find`, `git diff`, `php -l`.
- **Never rebuild to test a code change.** `principle/src` is bind-mounted into
  both the test runner and the app stack, and the worker watches it. Only a
  `composer.json` change, a Dockerfile change, or a fresh clone needs
  `docker compose build`.
- **Scratch goes in the workspace `tmp/`**, never `/tmp` — it is outside the
  working directory, so every use raises an `external_directory` prompt, and a
  failed run leaves its evidence out of sight.
- `declare(strict_types=1);` first in every PHP file.
- `final class` or `enum`. An interface only where there is genuinely more than one implementation.
- Constructor property promotion, `private readonly` where the value never changes.
- **Explanation belongs in docblocks, not inline comments.** Docblocks say *what and why*; comments only where a line is genuinely opaque.
- Typed PHPDoc: `@param array<string, mixed> $data`, `@return list<string>`.
- Class docblocks take the author's voice: plain, opinionated, one short example, no marketing.
- Tests: `final class XTest extends TestCase`, names that read as behaviour (`testATypedPropertyMutatesAcrossRequests`), `assertSame` over `assertEquals`.
- No emojis. British-ish spelling is inconsistent in the existing code; keep it neutral.

## Known Traps

Last reviewed: 2026-10-11. These have each cost time in this codebase.

### Live hazards — will bite you

**`self::` in a base class silently ignores subclass constants.** `AbstractGrammar`
reads `static::OPEN` for its quote character; `self::OPEN` would bind to the base
class, every dialect would emit backticks, and PostgreSQL would reject them at
runtime. It looked correct in review and was caught only by running against a
real server — a unit test asserting on strings would have passed.

**A hand-written quote character is the bug the grammar exists to prevent.**
The aggregate alias was written as ``AS `aggregate` `` straight into a format
string. On SQLite and MySQL that is fine, which is why it survived; on
PostgreSQL it is a syntax error. **When the framework emits SQL, every quoted
identifier goes through the dialect.**

**`usort()` returns `bool`, not the array.** It has happened here: a method did
`$sorted = usort($rows, …)` and the sort silently did nothing, so every ordering
test failed at once with no hint why.

**`$_ENV['X'] ?? 'default' === 'value'` does not mean what it looks like.** `??`
binds looser than `===`, so this is `$_ENV['X'] ?? ('default' === 'value')` —
truthy whenever `X` is set at all. It ran a SQLite-only `PRAGMA` against
PostgreSQL. **Parenthesise the left side**, and prefer asking the object which
dialect it is over re-reading an environment variable.

**A factory that falls back is worse than one that throws.** `ConnectionFactory`
was handed a config section and read `db.*` from it, found nothing, and opened
an in-memory database — a working application with no data in it. Defaults are
fine for *values*; a default that swallows a **shape** mistake is a silent
failure.

**A plain string appended to an element is escaped.** This is the rule that catches everyone:

```php
$this->hx->div()->append('<b>hi</b>')->render();          // <div>&lt;b&gt;hi&lt;/b&gt;/div>
$this->hx->div()->append(Raw::of('<b>hi</b>'))->render(); // <div><b>hi</b></div>
```

Note what this is *not*: `render()` is declared `Element|Raw|string`, and `Component::markup()` wraps a returned string in `Raw` for you — so returning a plain string from `render()` is supported and is **not** a type error. The escaping happens at `Element::append()`. Wrap markup in `Raw::of()` when you mean it literally.

**A test can pass while asserting nothing.** `AppTest` once asserted `assertStringContainsString('htmx', $body)` — which matched `class="htmx-indicator"` in the layout and would have passed whatever the search returned. **Assert on something that can only appear if the behaviour worked**: a specific `<li>`, an exact attribute, a status code. When you write an assertion, ask what the *opposite* outcome would produce.

**A hook with no test is a bug waiting.** `Layout::styles()` appended its return value straight into `<head>`, so returning raw CSS emitted naked text that browsers hoist away and discard — the app rendered unstyled with a 200, and nothing caught it. `View/` had no test file at all. **Add the test with the feature.**

**Route matching depends on where the route came from.** A **hand-written**
table is first-match-wins, so `/todos/new` must be declared before `/todos/{id}`
or the dynamic route swallows it — the order is a deliberate choice and is
preserved. **Discovered** routes are sorted by specificity before joining the
table, because their order would otherwise come from the filesystem. Do not
"fix" the sort into `register()` and change every existing table's behaviour.

**`usort()` returns `bool`.** It has already happened here: `commitDiscovered()`
assigned its return value to the array it was meant to hold, so nothing was ever
sorted and every ordering test failed at once. Sort into a variable, then use it.

**`Container::singleton()` takes a class-string or factory, never an object.** The signature is `callable|string|null`. Pre-built objects go through `instance()`; passing one is a `TypeError`.

**A session outlives the request that opened it.** PHP sessions are
process-wide. Under php-fpm every request got a fresh process and this never
surfaced; under a long-running worker, an un-reset `Session` means the second
visitor reads the first visitor's `$_SESSION` — silently, with nothing in the
log. `Session::beginRequest()` is what prevents it, and it resets rather than
replaces: a fresh `Session` per request looks cleaner and is **wrong**, because
`$app->auth(new SessionGuard($app->session(), …))` captures it at boot and every
guard then reads a session nothing writes to. See
[docs/workers.md](docs/workers.md).

**Anything built at boot and held is frozen at whichever request came first.**
That is what `$app->use(new Middleware(...))` does to a middleware's `Format`
and `Auth`. Pass the class-string; the pipeline rebuilds it per request.

### Fixed — do not reintroduce

**The suite could report success having run almost nothing.** PHPUnit picks its exit status in `ShellExitCodeCalculator`, which nothing reaches if the process exits first — so an `exit` anywhere in the code under test truncated the run, printed no summary, and returned **0**. Measured: a fixture with an `exit` in it stopped the suite after 21 tests and `composer test` still succeeded.

Two halves, because they are different failures. `failOnEmptyTestSuite="true"` in `phpunit.xml` covers *nothing ran* — a filter or a broken autoloader used to exit 0 too. `tools/test` covers *stopped early*: it captures the output and refuses a run with no summary line. Verified by planting an `exit` in a fixture and getting a non-zero status, then reverting. Live streaming is preserved with `tee`.

The habit that survives: **read the summary line, not the exit status.** Any exit anywhere still truncates, and only `tools/test` stands between that and a gate that means nothing.

**`Response::html()` used to discard a second argument.** PHP silently drops extra arguments to a function that does not declare them, so `Response::html('x', 406)` returned a 200. Fixed in `456b960` — it now takes `$status`. Still worth knowing when a status quietly goes missing.

**The `Empira\` → `IamAdty\Empira\` rename was a one-time migration.** 336 occurrences, done. The technique generalises — a negative lookbehind (`(?<!IamAdty\\)Empira\\`) makes a search-and-replace idempotent, where a second pass would double-prefix everything — but it is not a live hazard here.

### Not repository-specific

General PHP and Composer facts that bit us once. They are not conventions of this codebase:

- `ReflectionMethod::invokeArgs()` takes a target object; `ReflectionFunction::invokeArgs()` must not get one. Different signatures, same method name.
- A Composer **path repository** needs at least one commit in the target repository — with no git history there is no branch to infer a version from, and resolution fails. Relevant when setting up the sibling `app/` package, which links `../principle`.

## Definition of done

A change is done when:

- [ ] `composer test` passes, and you added a test that fails without your change
- [ ] `php -l` is clean on every file you touched
- [ ] Public API changes have a docblock saying why
- [ ] You checked the invariants above against anything security-adjacent
- [ ] `docs/` is updated if you changed documented behaviour
- [ ] Nothing in a "shipped" document now describes something that does not exist

## Further reading

- [ARCHITECTURE.md](ARCHITECTURE.md) — how a request flows, and why
- [docs/components.md](docs/components.md) — props, actions, verbs
- [docs/routing.md](docs/routing.md) — the routing API that exists today
- [docs/views.md](docs/views.md) — layouts, regions, fragments
- [docs/data.md](docs/data.md) — connections, dialects, queries, migrations
- [docs/auth.md](docs/auth.md) — who is asking, and CSRF exemptions
- [docs/testing.md](docs/testing.md) — fixtures, conventions, the client
- [docs/workers.md](docs/workers.md) — one boot, many requests, and what is reset
- [docs/recipes.md](docs/recipes.md) — copy-pasteable how-tos
- [SPEC.md](SPEC.md) — planned work