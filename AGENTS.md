# AGENTS.md

> **Status: describes shipped code.** Current behaviour, verified against `587 tests, 1060 assertions`.
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

There is no CI config yet. **The test suite is the only gate** — run it before you claim something works.

## Where things live

69 source files across 13 namespaces. The 16 files at `src/` root are the core; everything else is a subsystem.

| Path | Responsibility |
|---|---|
| `src/Application.php` | Front door. `handle()`, `render()`, `view()`, `renderException()`, the middleware pipeline, the container |
| `src/Component.php` | Base class for all UI. Props, actions, lifecycle hooks, token minting |
| `src/Context.php` | Per-request services handed to every component |
| `src/Element.php` | Fluent tag/attribute builder. All the `hx*()` helpers live here |
| `src/ElementFactory.php` | Creates elements, optionally wired to a component's action resolver |
| `src/Raw.php` | A string emitted verbatim, bypassing escaping |
| `src/Html.php` | Escaping, attribute serialisation, JSON |
| `src/Registry.php` | Allow-list of classes a token may resolve to |
| `src/TokenCodec.php` | HMAC-signed component identity (`base64url(payload).base64url(hmac)`) |
| `src/Router.php` | Resolves an action URL back to a component and runs the action |
| `src/functions.php` | The global `hx()` helper |
| `src/Attributes/` | `Prop`, `Action`, and the verb markers `Get`/`Post`/`Put`/`Patch`/`Delete` |
| `src/State/` | `StateStore` + implementations, `PropBag` (typed prop hydration), `ActionMap` |
| `src/Http/` | `Request`, `Response`, `Session`, `Middleware`, `Pipeline` |
| `src/Routing/` | `Route`, `RouteCollection`, `UrlGenerator`, `Controller`, `HttpException` |
| `src/View/` | `Layout`, `Region` — the page shell and its swappable areas |
| `src/Container/` | Autowiring service container |
| `src/Validation/` | `Validator`, `ErrorBag`, `ValidationException` |
| `src/Data/` | PDO wrapper + fluent query builder |
| `src/Config/` | `.env` parsing, dot-notation config |
| `src/Logging/` | `Logger`, `FileLogger`, `NullLogger`, `LogLevel` |
| `src/Security/Csrf.php` | Session-backed CSRF for component actions |
| `src/Testing/` | `TestClient`, `TestResponse` |
| `src/Console/` | CLI kernel and commands |

## Non-negotiable invariants

Break one of these and a component becomes exploitable or silently broken.

1. **A token may only resolve a registered class.** `Registry` is the security boundary. Never construct a component from a class name without going through it.
2. **The HMAC covers the props.** If that stops covering them, a client can promote itself by editing a URL.
3. **Actions must be reachable only if declared.** `actions()` or `#[Action]`/a verb attribute. Public + not declared by the framework base = refused.
4. **Private, static and inherited-from-`Component` methods are never callable as actions**, regardless of how they are declared.
5. **`Raw` is unescaped.** It is for values you control. Never request input.
6. **The verb an action declares must be the verb the router accepts** — one attribute feeds both the `hx-*` name and the method check, so they cannot drift.

## Conventions

- `declare(strict_types=1);` first in every PHP file.
- `final class` or `enum`. An interface only where there is genuinely more than one implementation.
- Constructor property promotion, `private readonly` where the value never changes.
- **Explanation belongs in docblocks, not inline comments.** Docblocks say *what and why*; comments only where a line is genuinely opaque.
- Typed PHPDoc: `@param array<string, mixed> $data`, `@return list<string>`.
- Class docblocks take the author's voice: plain, opinionated, one short example, no marketing.
- Tests: `final class XTest extends TestCase`, names that read as behaviour (`testATypedPropertyMutatesAcrossRequests`), `assertSame` over `assertEquals`.
- No emojis. British-ish spelling is inconsistent in the existing code; keep it neutral.

## Known Traps

Every one of these has already cost time in this codebase.

**`markup()` must return `Element|Raw`, never a bare string.** A string handed to `append()` is *escaped*, which turns a component's markup into visible angle brackets in the middle of the page. `Component::markup()` is typed `Element|Raw` to make this a type error rather than a surprise.

**A test can pass while asserting nothing.** `ExampleTest` once asserted `assertStringContainsString('htmx', $body)` — which matched `class="htmx-indicator"` in the layout and would have passed whatever the search returned. **Assert on something that can only appear if the behaviour worked**: a specific `<li>`, an exact attribute, a status code. When you write an assertion, ask what the *opposite* outcome would produce.

**A hook with no test is a bug waiting.** `Layout::styles()` appended its return value straight into `<head>`, so returning raw CSS emitted naked text that browsers hoist away and discard — the app rendered unstyled with a 200, and nothing caught it. `View/` had no test file at all. **Add the test with the feature.**

**Route matching is first-match-wins.** `/todos/new` must be registered before `/todos/{id}` or the dynamic route swallows it. There is no specificity sort yet (see `SPEC.md`).

**Namespace renames must be idempotent.** Use a negative lookbehind (`(?<!IamAdty\\)Empira\\`) or a second pass double-prefixes everything.

**`Response::html()` discarded a second argument.** PHP silently drops extra args to a function that does not declare them. `Response::html('x', 406)` returned a 200 until the parameter was added.

**`Container::singleton()` takes a class-string or factory, never an object.** Pre-built objects go through `instance()`.

**`ReflectionMethod::invokeArgs()` needs its target object; `ReflectionFunction` must not get one.** They have different signatures.

**Composer path repositories need at least one commit.** With no git history there is no branch to infer a version from, and resolution fails. `APP_SECRET` must be ≥16 bytes (`TokenCodec` refuses less) and must not change between deploys.

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
- [docs/testing.md](docs/testing.md) — fixtures, conventions, the client
- [docs/recipes.md](docs/recipes.md) — copy-pasteable how-tos
- [SPEC.md](SPEC.md) — planned work