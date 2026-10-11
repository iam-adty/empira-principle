# Checklist

> **Status: live.** Checked items are struck, not deleted — the history is the
> point. If an item here disagrees with the code, the code wins and this file is
> a bug.

Framework: `iam-adty/empira-principle` · app: `iam-adty/empira-app`
Current: **587 framework tests / 1060 assertions**, **12 app tests / 33 assertions**.

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
- ~~Logging: levels, file logger, newline-safe~~
- ~~Data: PDO query builder with strict identifier validation~~
- ~~Config: `.env` parsing, dot-notation, typed getters~~
- ~~`TestClient` for application authors~~
- ~~CLI: `make:component`, `make:controller`, `route:list`, `serve`~~

### Packaging — 2026-10-11
- ~~Split into two Composer packages: `principle/` (library) and `app/` (project)~~
- ~~Namespace `Empira\` → `IamAdty\Empira\`~~
- ~~`app` links the library through `franzl/studio` (`dev-main`)~~
- ~~Two git repositories, one per package~~

### Fixes — 2026-10-11
- ~~`<style>` was missing around a layout's stylesheet~~
- ~~`Response::html()` silently discarded a second argument~~
- ~~Group middleware was attached but never executed~~
- ~~Session rotated its flash buckets on every access~~
- ~~`validate()` fired its event even on success~~

---

## Next

In priority order. See [SPEC.md](SPEC.md) for the design behind each.

### 1. Attribute routing `[planned]`
- [ ] `Attributes/Route.php` — `TARGET_CLASS|TARGET_METHOD|IS_REPEATABLE`
- [ ] `Routing/RouteRegistrar.php`, `RouteRegistry.php`, `RouteParams.php`
- [ ] Specificity sort (priority → fewest params → most literal → longest → registration)
- [ ] `Application::discover()`
- [ ] `view()` merges route params; `mount()` injected by reflection
- [ ] Auto-derived route names
- [ ] CLI `route:list` boots the real app
- [ ] Tests: discovery, ordering, repeatable attribute, mount injection
- [ ] Migrate `app/` to `#[Route]` and delete `app/routes.php`

### 2. JSON / API routes `[planned]`
- [ ] `Request::accepts()` with q-value parsing (explicit match beats wildcard)
- [ ] Negotiated format computed once in `handle()`
- [ ] One error envelope: `{"error": {status, message, errors?}}`
- [ ] **Fix:** map `ValidationException` to 422 — today it rethrows and fatalises
- [ ] `VerifyCsrfToken` becomes format-aware
- [ ] Assert htmx still receives fragments (it sends `Accept: text/html, */*;q=0.9`)
- [ ] Tests: accept matrix, envelope per status, HTML path unchanged

### 3. Documentation `[building]`
- [x] `AGENTS.md`, `ARCHITECTURE.md`, `SPEC.md`, this file
- [ ] `CONTRIBUTING.md`, `CHANGELOG.md`, `SECURITY.md`
- [ ] `docs/` reference pages
- [ ] `.opencode/skills/` + `opencode.json`

### 4. Operations
- [ ] CI — no config exists; the test suite is currently the only gate
- [ ] Push both repositories to a remote
- [ ] Decide whether `.opencode/` ships in the published package

---

## Deferred

- [ ] Authentication — explicitly parked. It plugs into the negotiated format and the error envelope once those land.
- [ ] CSRF exemption for token-authenticated routes

---

## Maintenance rules

1. Strike, never delete — the history is why this file is trustworthy.
2. Every shipped item gets a date.
3. If code and checklist disagree, the code is right and this file is the bug.
4. Anything marked `[planned]` that turns out to be wrong should be corrected **before** it is built, not after.