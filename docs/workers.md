# Long-running workers

One `Application`, booted once, serving many requests. **Optional** — nothing here
is needed to run an application.

```bash
composer serve                              # classic, one request, one process
```

A worker is something an application installs, like any other module:

```php
// bootstrap/modules.php
return function (Application $app): array {
    return [new FrankenPhpModule()];
};
```

and then runs under a FrankenPHP PHP, from the application root:

```bash
vendor/bin/frankenphp-worker --app=/path/to/app
```

There is no image to pull. A stock PHP cannot become FrankenPHP at runtime — the
extension has to exist before PHP starts — so producing one is an application and
deployment decision, and [`empira-frankenphp`](https://github.com/iam-adty/empira-frankenphp) deliberately does not
make it for you.

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

**They hold under a classic runtime too, and that is deliberate.** Everything
listed under "what is reset" is reset on *every* request, worker or not, and it
costs nothing on a fresh process. Resetting unconditionally is what lets the same
application run under both without knowing which it is — and it means the rules
on this page are not a worker-only appendix, they are simply how the framework is
written.

Worker mode ships as [`iam-adty/empira-frankenphp`](https://github.com/iam-adty/empira-frankenphp), which holds
the loop and a base image, because FrankenPHP cannot be added to a stock PHP at
runtime — the extension has to exist before PHP starts. The framework itself
contains no FrankenPHP code and does not require that package; an application that
never installs it is not missing anything.

`FrankenPhpModule::register()` throws if you install it under a classic runtime.
That is on purpose: the framework resets correctly either way, so the mismatch is
otherwise invisible until a session or an identity map leaks across a request
boundary and the second visitor is served the first one's state.

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
| Database rows | SQLite | **yes** |
| Component state (`SessionState`) | `$_SESSION`, so files | **yes** |
| Secret, registry, route table | re-derived from the environment at boot | **yes** — identical in every worker |
| `PropBag` / `ActionMap` caches | reflection of class definitions | **yes** — same input, same output |
| Per-request instances | discarded each request | not needed |
| The `EntityManager` handle | per worker | the handle is not; the identity map must be |

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
| `Session` | **Shared** — see below — reset by `beginRequest()`, closed by `endRequest()`. |
| `EntityManagerInterface` | **Shared** — a connection per request defeats the point — but its identity map is cleared by `handle()`, or request two is served request one's user. |
| `Registry`, `TokenCodec`, `Csrf`, the route table | Stateless or boot-time config. Persisting is correct. |
| `Component::$nonce` | Minted per instance, so ids, state keys and tokens are unique per request with no reset. |

## What the worker count costs

FrankenPHP's default is **2 × CPU** — 32 workers on a 16-core machine. That is the
right default for throughput. It is not free, and it is worth knowing the bill:

**Boot is concurrent, and boot must not write.** Every worker thread runs
`App::boot()` at once, so anything boot does that writes is done thirty-two times
simultaneously — thirty-two threads asking "does the user exist?" all get "no"
before any of them inserts, and thirty-one die on a unique index.

This is not a race to be locked around. It is the reason migrating and seeding
are deploy steps rather than boot behaviour: `empira migrate` and `empira seed`
run once, from `bootstrap/app.php`'s console entry, and boot only wires. **Do not
add a write to boot** — if boot needs data, seed it before the workers start,
which is what the `php` compose service does.

There was once an `App::bootLock()` here, taking an advisory lock around
migrations and seeding to serialise boot into happening once. It does not exist in
any package. The locking was treating the symptom: a lock makes thirty-two threads
take turns doing something that should have been done once, before any of them
existed, and it leaves the real constraint — that a worker's boot is racy by
design — in place for the next person to add a fourth write to.

**Connections scale per worker, not per application.** Each worker holds its own
PDO handle, so 32 workers is 32 open handles on one file. SQLite has no server
connection limit to run into, which is one thing the move bought — though the
write contention it replaces is real, and SQLite serialises writers with a file
lock, so the constraint moves rather than disappears.

The paragraph used to be about PostgreSQL's default 100 connections, and the
advice it carried — remember your pool limit before running two services against
one database — applied only to that server. There is no server here to exhaust.

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

**The request must also hand the session back.** `Session::endRequest()` closes
it in the same `finally` that clears the context, so the write-back belongs to
the request that made the change. Without it the file is not updated until
whichever request this worker serves *next* calls `beginRequest()` — and at 32
workers there may not be a next one here for some time.

That gap was invisible, because FrankenPHP flushes `$_SESSION` during per-request
teardown anyway. The application worked while resting on undocumented runtime
behaviour that contradicted our own model. Closing it ourselves costs one write
and makes the guarantee ours.

Note the asymmetry, which is deliberate: `beginRequest()` is gated on the CLI and
`endRequest()` is not. Under the CLI the `$_SESSION` array has to survive between
requests, so there is nothing to close; a session that genuinely *was* started is
correct to close anywhere.

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
FRANKENPHP_WORKERS=1 vendor/bin/frankenphp-worker --app=/path/to/app
```

Sign in, then interleave requests from that cookie jar and an empty one. The
signed-in user stays signed in and the anonymous one stays anonymous. Any other
result is a leak.

This is a diagnostic, not a deployment setting. Running the stack at one worker
hides nothing but fixes nothing either; it only makes a bug that is already
there visible in one attempt instead of thirty-two.
