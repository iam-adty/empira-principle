# Long-running workers

One `Application`, booted once, serving many requests.

```bash
php -S localhost:8000 -t public            # classic: one request, one process
FRANKENPHP_WORKERS=1 docker compose up -d  # one worker: every request shares a process
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
is unambiguous:

```bash
FRANKENPHP_WORKERS=1 docker compose up -d --force-recreate app
```

Sign in, then interleave requests from that cookie jar and an empty one. The
signed-in user stays signed in and the anonymous one stays anonymous. Any other
result is a leak.
