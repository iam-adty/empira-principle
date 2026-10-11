# JSON and API routes — planned

> ## ⚠️ NOT IMPLEMENTED
>
> Everything on this page is **design**. The framework returns HTML for pages and
> text for errors today; there is no JSON negotiation. This page is written to be
> read *before* building, and corrected against the implementation *after*.
>
> Source of truth: [../SPEC.md](../SPEC.md) §2.

The goal: a non-browser client gets a JSON body, and one error shape, without the
route having to declare that it is an API.

## Where it stands today

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
| browser: `text/html,application/xhtml+xml,*/*;q=0.8` | HTML | no `application/json` present |
| htmx: `text/html, */*;q=0.9` | **HTML** | fragment behaviour unchanged |
| curl: `*/*` | HTML | wildcard only |
| axios: `application/json, text/plain, */*` | JSON | explicit json beats wildcard on tie |
| `text/html, application/json;q=0.9` | HTML | explicit html has the higher q |
| `application/json` | JSON | — |

The fourth row is why substring matching would be wrong.

**htmx sending `Accept: text/html, */*;q=0.9` is load-bearing.** That is what
keeps every existing fragment response identical. Assert it in the smoke test
rather than assuming it.

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

- Any authentication. It plugs into two seams this design already establishes:
  the negotiated format, and the envelope (`401` in the same shape).
- CORS, rate limiting, pagination conventions.

## Tests it needs

- the full `Accept` matrix above, including `text/html, application/json;q=0.9`
- `*/*` with and without a quality value
- envelope for 403, 404, 405, 422
- **`ValidationException` returns 422 rather than rethrowing**
- HTML path byte-identical to today
- htmx still receives a fragment with no `<html>`
- `VerifyCsrfToken` returns the envelope when JSON is negotiated

---

**This page describes software that does not exist.** When it is built, move the
behaviour into [http.md](http.md), flip the marker in [../SPEC.md](../SPEC.md),
and delete this file.