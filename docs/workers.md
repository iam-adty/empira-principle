# Long-running workers

One `Application`, booted once, serving many requests.

```bash
php -S localhost:8000 -t public            # classic: one request, one process
docker compose up -d                       # worker, 2 x CPU — the default
FRANKENPHP_WORKERS=1 docker compose up -d  # one worker: a diagnostic, see below
```

## Why this page exists

Under php-fpm, and under PHP's built-in server, every request gets a fresh
process. Everything a framework instance holds dies with the request. That is
free, and it is invisible: you never write a line to make it true.

A worker removes that. The `Application` is constructed once and handed
thousands of requests, and **anything it holds between them becomes the next
request's input**. The failure mode is the bad kind — not an exception, but a
wrong answer, served confidently, with nothing in the log.

This framework is safe in that shape. Not because it has no state, but because
the state it has is reset in `handle()`, where it cannot be forgotten. The
rules below are what make that true, and what to hold new code to.

## The rule

**Worker memory may hold only re-derivable data. Anything per-visitor belongs
in a shared store.**

Workers are separate processes with separate memory. A value written during one
request is invisible to every other worker, and to the *next* request whenever it
lands elsewhere. So the question to ask of any new field, cache or service is not
"is this reset between requests?" but **"could two workers disagree about this?"**
If yes, it is in the wrong place, and resetting it will not fix it — you will
only make the window narrower.

| State | Lives in | Shared between workers? |
|---|---|---|
| `$_SESSION` | `/tmp/sess_*` files | **yes** — one container filesystem |
| Database rows | PostgreSQL | **yes** |
| Component state (`SessionState`) | `$_SESSION`, so files | **yes** |
| Secret, registry, route table | re-derived from the environment at boot | **yes** — identical in every worker |
| `PropBag` / `ActionMap` caches | reflection of class definitions | **yes** — same input, same output |
| Per-request instances | discarded each request | not needed |
| The `Db` handle | per worker | the handle is not; the data is |

This is why the framework has no in-process cache of user data anywhere, and why
it should stay that way. A memoising decorator around the user repository is the
shape of bug this rule exists to prevent.

## What is reset, and where

Everything below happens in `Application::handle()`, before dispatch:

| State | How it is handled |
|---|---|
| `Request`, `Context`, `Format`, `RouteParams` | A fresh instance is bound per request. |
| `Auth` | Rebuilt per request from the guards and this request. |
| `Response` | A fresh instance per request. |
| `Session` | **Shared** — see below — and reset by `beginRequest()`. |
| `Connection` | Kept, because a connection per request defeats the point. Its statement log is cleared. |
| `Registry`, `TokenCodec`, `Csrf`, the route table | Stateless or boot-time config. Persisting is correct. |
| `Component::$nonce` | Minted per instance, so ids, state keys and tokens are unique per request with no reset. |

## What the worker count costs

The default is FrankenPHP's, **2 × CPU** — 32 workers on a 16-core machine. That
is the right default for throughput and it is what the reference application
ships. It is not free, and it is worth knowing the bill:

**Boot is concurrent.** Every worker thread runs `App::boot()`. Thirty-two
threads asking "does the demo user exist?" all get "no" before any of them
inserts, and 31 die on the unique index. `App::bootLock()` takes a
`pg_advisory_lock` around migrations and seeding to put boot back to happening
once — **anything added to boot that writes must go under it.**

**Connections scale per worker, not per application.** Each worker holds its own
PDO handle, so 32 workers is 32 connections out of PostgreSQL's default 100.
Fine here; worth remembering before running the app and a second service against
one database.

**Failures become intermittent.** This is the cost that actually hurts. A
per-worker state leak only surfaces when two consecutive requests for one
session land on the same worker — about 1 in 32. The bug has not gone away at 32
workers; it has become flaky, which is harder to diagnose and easier to ignore.

Which is why `FRANKENPHP_WORKERS=1` is worth knowing about. It is a **diagnostic,
not a configuration**: force one worker and every request shares a process, so a
state leak cannot hide. Use it when you are chasing one, then put it back.

## The session is the sharp end

A PHP session is process-wide, and PHP does not follow the request boundary for
you. The session id stays bound and `$_SESSION` stays populated, so the second
visitor's request reads and writes the first visitor's data, and `regenerate()`
defends the wrong id. It is a cross-user data leak, silent, with no error and
nothing in the log.

`Session::beginRequest()` closes the session and empties `$_SESSION` before each
dispatch, so the next `begin()` sees `PHP_SESSION_NONE` and starts fresh from
the incoming cookie. It also clears the latch that stops the flash buckets
rotating twice in one request — left set, it would stop them rotating *ever*,
giving a worker exactly one flash message for its entire life.

Note that `Session` is a **shared** instance, not one per request. An earlier
version of this fix made it per request, and that was wrong: `$app->auth(new
SessionGuard($app->session(), …))` captures the session at boot, so a fresh
instance per request leaves every guard reading a session nothing writes to.
Shared-and-reset is the shape that works.

## Rules for your own code

**Bind per request, or reset.** Either a fresh instance is bound in `handle()`,
or whatever is shared has its state cleared there.

**Do not capture per-request services at boot.** A `Session`, `Auth`, `Context`
or `Format` held by an object built during boot is frozen at whichever request
happened to be first. Pass the **class-string** to `$app->use()`, not an
instance, and the pipeline will rebuild it — see `use()`.

**Do not `exit`.** `Response::send()` emits and exits, which is correct for a
process with nothing else to do and fatal for a worker. Use `emit()`, which
writes headers and body and returns.

**`exit` is not the only way to lose a worker.** FrankenPHP restarts a script
that dies with a non-zero status, and a restart that fails immediately is a
tight crash loop. Catch `Throwable` where the worker calls your handler.

**Remember `$_ENV` is not reset between requests.** Every other superglobal is,
per FrankenPHP's own documentation. Anything request-specific written there is
visible to every later request the same worker serves. Configuration read at
boot is fine.

**`ini_set`, `chdir`, `set_error_handler`, `set_exception_handler` persist** for
the life of the process, by the same design. This framework uses none of them.

**Recycle the worker.** PHP was not built to run forever, and code outside your
application leaks in ways you cannot reach. FrankenPHP's `MAX_REQUESTS` restarts
the script after a set number of requests; use it.

## Testing it

A test suite that runs in one process is already a worker-mode test bed, which
is why two of them used to reset `$_SESSION` by hand. Keep them honest by
driving **two requests through one `Application`** — `tests/WorkerTest.php` does
exactly this, and five of its tests fail if the per-request reset is removed.

To see it for real against a running server, force a single worker. Then every
request is guaranteed the same process, and anything carrying over between them
is unambiguous — which is the point, because at the 2 × CPU default a leak only
shows itself about one request in 32:

```bash
FRANKENPHP_WORKERS=1 docker compose up -d --force-recreate --wait app
```

Sign in, then interleave requests from that cookie jar and an empty one. The
signed-in user stays signed in and the anonymous one stays anonymous. Any other
result is a leak.

This is a diagnostic, not a deployment setting. Running the stack at one worker
hides nothing but fixes nothing either; it only makes a bug that is already
there visible in one attempt instead of thirty-two.
