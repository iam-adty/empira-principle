# HTTP

> **Status: describes shipped code.**

## Request

`Request` is **immutable** — every field is `readonly`. Middleware that wants to
change something constructs a new one rather than mutating.

```php
Request::capture();                    // from the PHP superglobals
new Request('POST', '/todos', body: ['title' => 'walk dog']);
```

### Reading input

| Method | Returns |
|---|---|
| `input($key, $default)` | body first, then query |
| `string($key, $default)` | as a string |
| `int($key, $default)` | as an int |
| `bool($key, $default)` | as a bool |
| `all()` | body and query merged, body wins |
| `get($key, $default)` | dotted read across the merged input |
| `files()` / `hasFile($key)` | uploads, `$_FILES`-shaped |

### Other

```php
$request->path();                 // '/todos'      (never empty; defaults to '/')
$request->fullUrl();              // absolute
$request->isMethod('POST');
$request->header('x-custom');     // case-insensitive
$request->csrfToken();            // the header, or the _csrf field
$request->ip();                   // who it came from, or null
```

### `ip()`, and believing whoever says so

`ip()` returns the **socket address**, and only the socket address, unless the
application has said a proxy is in front:

```php
$app->trustProxies();   // nginx, Caddy or Cloudflare is rewriting the address
```

Off by default, because the consequence of being wrong is silent.
`X-Forwarded-For` is set by whoever sent the request, so with nothing in front of
the application it is a value the **client chose** — and a log full of
client-chosen addresses is worse than no addresses, because it looks like
evidence. The reverse mistake is quieter: behind a proxy every request arrives
from the proxy, so a log with one repeated value is a lie rather than an
absence.

Three details worth knowing:

- A forwarded chain is oldest-first and the client is taken from the **head**;
  the tail is the nearest proxy every time.
- Everything is validated. A malformed header falls back to the socket address,
  and a `REMOTE_ADDR` that is a unix socket path returns `null` — both are better
  than a value that gets written into a log and believed later.
- Under the CLI there is no address at all, so `ip()` returns `null`.

**Do not build a rate limiter on this without reading the paragraph above.**

## htmx request headers

```php
$request->isHtmx();            // HX-Request
$request->isBoosted();         // HX-Boosted — a real navigation
$request->isHistoryRestore();  // HX-History-Restore-Request
$request->currentUrl();        // HX-Current-URL
$request->prompt();            // HX-Prompt — the answer to hx-prompt
$request->target();            // HX-Target — the id being swapped
$request->trigger();           // HX-Trigger — the element that fired
$request->triggerName();       // HX-Trigger-Name
```

`isHtmx() && !isBoosted()` is what distinguishes a fragment request from a real
navigation. That is the check `Layout` uses.

### Content negotiation

```php
$request->accepts('application/json', ['text/html']);   // true when JSON is preferred
```

A real `Accept` parse, not a substring test: quality values are honoured, and an
explicit mention of a type beats a wildcard. The framework uses it once per
request to decide how to render **errors** — HTML by default, or a JSON envelope
when the client explicitly asked for JSON.

| Client sends | Result |
|---|---|
| **htmx: nothing** | HTML |
| **browser XHR / curl: `*/*`** | HTML |
| browser navigation: `text/html,…` | HTML |
| `application/json` | JSON |
| axios: `application/json, text/plain, */*` | JSON |
| `text/html, application/json;q=0.9` | HTML |

**htmx never sends an `Accept` header at all** — it signals intent with
`xhr.overrideMimeType('text/html')`. A wildcard therefore carries no preference
and resolves to HTML, because that is the header every fragment arrives with.

Negotiation governs the **error** body only. A handler always chooses its own
success body, so `Response::json()` works whatever the client asked for.

## Response

`Response` is **mutable** — every setter returns `$this`.

### Constructors

```php
Response::html($html, $status = 200);
Response::json($data, $status = 200);
Response::redirectTo($url, $status = 302);   // Location header, 3xx
Response::noContent(204);
Response::stopPolling();                     // 286 — tells htmx to stop polling
```

`redirectTo()` is a real HTTP redirect. `->redirect()` is different — see below.

### htmx response headers

These are the response-side counterparts to the request attributes: they tell the
client to redirect, retarget, push history, or fire an event.

```php
return response()
    ->pushUrl('/posts/1')              // add to history
    ->replaceUrl('/?q=milk')           // replace, no new entry
    ->redirect('/login')               // HX-Redirect — client-side navigation
    ->location(['path' => '/posts/1']) // HX-Location — client-side, no reload
    ->reswap('outerHTML')
    ->retarget('#results')
    ->reselect('.content')
    ->refresh()                        // full client-side refresh
    ->triggerAfterSwap('saved', ['id' => 42]);
```

`redirect()` is the **htmx** redirect (`HX-Redirect`), not a `Location` header.
Use `Response::redirectTo()` for a real one. Confusing the two is easy; the
names are deliberately distinct.

Trigger headers accumulate and are merged into a single JSON `HX-Trigger*` header
when sent, which is what htmx expects.

### Cookies, files, caching

```php
$response->cookie('name', 'value', ['expires' => time() + 3600, 'secure' => true]);
$response->forgetCookie('name');
$response->download('/path/report.pdf');     // attachment, streamed in chunks
$response->cache(3600);                      // private by default
$response->noStore();
```

Header values are sanitised: `CR`, `LF` and `NUL` are stripped, so a value cannot
smuggle in a second header.

### Error views keep the headers

`$app->onHttpError(fn(int $status, string $message) => …)` replaces the body of
an error response, and **leaves the headers alone**. An `HttpException` carries
headers that are protocol rather than decoration — `Allow` on a 405,
`Retry-After` on a 429 — and they survive the view. The view chooses what the
response *says*; it does not get to change what the response *means*.

### Sending

`send()` writes the status and headers, streams the body, and terminates. It is
the only place the script ends.

## Middleware

```php
interface Middleware
{
    public function handle(Request $request, Closure $next): Response;
}
```

Call `$next($request)` to carry on, or return your own response to short-circuit.
Onion order — the first-registered layer is outermost:

```php
$app->use($first, $second);
// first:in → second:in → handler → second:out → first:out
```

The constructor is autowired, so a middleware asks for what it needs by type.

## Session

```php
$session = $app->session();

$session->put('user_id', 42);
$session->get('user_id');
$session->flash('success', 'Saved.');   // readable next request only
$session->flashed();                    // reads and forgets
$session->regenerate();                 // after a login
```

Everything lives under one namespace key, so application session variables and
the framework's flash messages cannot collide. Under the CLI — how tests run —
there is no session, so an in-process array stands in and requests share it for
the life of the process.

## CSRF

Component actions verify a token on every request. Because htmx does not submit
a form field for a bare `hx-post` on a `<div>`, the token travels as a header,
and `hx-headers` is inherited document-wide:

```php
<body hx-headers='{"X-CSRF-Token": "…"}'>
```

One attribute in the layout covers every descendant. A mismatch is a `403`.

For ordinary controllers, `Http\Middleware\VerifyCsrfToken` applies the same rule
and skips safe methods (`GET`, `HEAD`, `OPTIONS`, `TRACE`).

## Further reading

- [SECURITY.md](../SECURITY.md) — the full security model
- [docs/json-errors.md](json-errors.md) — the error envelope and the Accept matrix
- [../SPEC.md](../SPEC.md) — planned work