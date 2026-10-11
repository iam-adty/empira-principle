# JSON errors

> **Status: describes shipped code.** Negotiated errors, one envelope, and the
> `ValidationException` fix. Source of truth: [../SPEC.md](../SPEC.md) §2.

The goal: a non-browser client gets a JSON body, and one error shape, without the
route having to declare that it is an API.

## What it replaced

```php
public function renderException(\Throwable $e, Request $request, Response $response): Response
{
    …
    return $response->setStatus($status)->setBody($e->getMessage());   // plain text
}
```

`Request` never looks at `Accept`. `VerifyCsrfToken` hardcodes
`Response::html(...)`. An API client receives HTML, or a bare sentence.

## Negotiate, do not declare

The response format comes from the `Accept` header, so one endpoint can serve a
page and an API client without a flag.

```php
Request::accepts('application/json');
```

This is **not** `str_contains`. Real clients send quality values, and a naive
substring match gets `application/x-javascript` wrong. The rule follows the HTTP
precedence that **an explicit match always beats a wildcard**:

| Client sends | Result | Why |
|---|---|---|
| browser: `text/html,application/xhtml+xml,*/*;q=0.8` | HTML | explicit `text/html` |
| **htmx: nothing** → `*/*` | **HTML** | see below |
| curl: `*/*` | HTML | wildcard only |
| axios: `application/json, text/plain, */*` | JSON | explicit json |
| `text/html, application/json;q=0.9` | HTML | explicit html has the higher q |
| `application/json` | JSON | — |

**htmx never sends an `Accept` header at all.** This was verified against
`htmx.org@2.0.4`: the word does not appear in its request-building code. htmx
signals "I want HTML" with

```js
xhr.overrideMimeType('text/html')
```

so what the server receives is the browser's XHR default, `*/*`.

Two rules follow, and both are safety-critical:

1. **`*/*` means HTML.** It is the header every htmx request arrives with. If a
   wildcard resolved to JSON, every fragment swap in every application would
   break. The rule is deliberately the conservative one: unchanged behaviour for
   everyone who has not explicitly asked for JSON.
2. **htmx traffic is permanently HTML.** `overrideMimeType('text/html')` means a
   JSON body is *misparsed* by the client, not merely unexpected. No endpoint may
   serve JSON to an htmx caller.

The second point is why this is worth stating in a design document: it is not
obvious, and it would otherwise be discovered by shipping something broken.

Accept inspection is **not** a `str_contains` away — real clients send quality
values, and a substring match gets `application/x-javascript` wrong.

## Where it is computed

`Request` is immutable, so the negotiated format is computed **once** in
`Application::handle()` and passed to error rendering. It is never mutated onto
the request.

```
handle()
  ├─ negotiate(request)  →  Format::Json | Format::Html
  ├─ Pipeline::send(…)
  └─ renderException(…, $format)   ← uses it
```

## One error envelope

Every failure — framework-originated or application-originated — returns the same
shape:

```json
{
  "error": {
    "status": 422,
    "message": "The given data was invalid.",
    "errors": { "title": ["The title field is required."] }
  }
}
```

`errors` appears only for validation. One shape means a client parses one thing,
whether the 403 came from the token guard, the 404 from the registry, the 405
from a method mismatch, or a 422 from the validator.

When HTML is negotiated, behaviour is **byte-identical to today**.

## The bug this fixes

`ValidationException` is not mapped in `renderException`. It falls through to
`default => 500`, and because it is not an `HttpException` it is **rethrown**:

```
Fatal error: Uncaught IamAdty\Empira\Validation\ValidationException
```

A controller that throws it today produces a fatal rather than a 422 — despite
its own docblock documenting `Response::json($e->errors(), 422)` as the usage.

**This is worth fixing on its own merits**, independent of the API work. It is a
framework bug reachable from any route that validates.

## Handlers

A handler may return JSON explicitly, and that always works regardless of
negotiation:

```php
#[Route('/api/todos', methods: ['GET'], name: 'todos.index')]
public function index(): Response
{
    return Response::json(['items' => $this->todos->all()]);
}
```

Negotiation governs the **error** format. A handler stays in charge of its own
success body.

## CSRF

`VerifyCsrfToken` becomes format-aware — a 419 becomes the envelope, not an HTML
page. Exemption for token-authenticated routes is **deferred** along with
authentication.

## Deferred

- Any authentication. It plugs into two seams this establishes: the negotiated
  format, and the envelope (`401` in the same shape).
- CSRF exemption for token-authenticated routes — `VerifyCsrfToken` is
  format-aware but still applies to every unsafe method.
- CORS, rate limiting, pagination conventions.

## Tests

- the full `Accept` matrix above, including `text/html, application/json;q=0.9`
- `*/*` with and without a quality value
- envelope for 403, 404, 405, 422
- **`ValidationException` returns 422 rather than rethrowing**
- HTML path byte-identical to today
- htmx still receives a fragment with no `<html>`
- `VerifyCsrfToken` returns the envelope when JSON is negotiated

---

**See also** [http.md](http.md) for the request/response surface, and
[../SPEC.md](../SPEC.md) §2 for the reasoning.