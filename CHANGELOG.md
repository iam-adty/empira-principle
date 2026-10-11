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

- Documentation set: `AGENTS.md`, `ARCHITECTURE.md`, `SPEC.md`, `CHECKLIST.md`,
  `CONTRIBUTING.md`, `SECURITY.md`, `CHANGELOG.md` and a `docs/` reference.

### Changed

- `Layout::styles()` now wraps a `Raw` stylesheet in a `<style>` element instead
  of appending it bare into `<head>`, where a browser would hoist it away and
  discard it. An `Element` return value is still used as-is.

### Fixed

- `Response::html()` silently discarded a second argument, so
  `Response::html('x', 406)` returned a 200.
- Route group middleware was attached to `Route` but the dispatcher never
  wrapped the handler in it — a group-level auth rule did nothing.
- `Session` rotated its flash buckets on every access, so a second read in the
  same request discarded the message.
- `Component::validate()` fired its `invalid` event even when validation passed.

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

- `ValidationException` is not mapped in `renderException`; it falls through to
  500 and is rethrown instead of returning 422. Scheduled in `SPEC.md`.
- Routing is first-match-wins with no specificity sort, so `/todos/new` must be
  declared before `/todos/{id}`. Scheduled in `SPEC.md`.
- JSON responses are not negotiated — every page and error is HTML.
- No authentication. See `SECURITY.md`.
- No CI configuration.
- No git remote; nothing is published anywhere.