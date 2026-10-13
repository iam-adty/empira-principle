# Security

> **Status: describes shipped code.** Verified against the current suite.

## The model in one paragraph

A component's identity and its state travel in the URL inside an HMAC-signed
token. The server does not trust the URL, and it does not trust the client. What
a client *can* do is limited to invoking actions a component declared, on
components the application registered. Two independent checks enforce that: a
registry allow-list for which classes exist, and a declaration check for which
of their methods are reachable.

## Signed tokens

`TokenCodec` produces `base64url(payload).base64url(hmac)`, where the payload
carries a version, the component's registered name, an instance nonce, and — for
stateless components — its props.

```json
{"v": 1, "c": "counter", "n": "6f80ec5c9ca0e6ac", "p": {"count": 5}}
```

**The signature covers the props.** That is the whole reason state can ride in a
URL: a client can edit the number, but the signature stops verifying and the
request is refused with a 403. State integrity comes from the HMAC, not from a
server-side session.

- The secret must be **at least 16 bytes**. `TokenCodec` refuses to construct otherwise.
- **Changing the secret invalidates every link in every user's history.** Treat it like a key: rotate deliberately, not casually.
- Versioning (`"v"`) exists so a future payload shape can be rejected rather than misread.

## The registry is the boundary

A token names a class. Without a check, a token could name any class on the
autoloader and have it instantiated.

There are two ways in, and both end in the same check.

```php
// One component, named. Short token, survives the class moving namespaces.
$app->registry()->alias('counter', Counter::class);

// Or a whole tree, named once. No per-component bookkeeping.
$app->components(__NAMESPACE__);
```

The second is why nesting is free: a component that only another one renders
still answers its own action URLs, instead of rendering perfectly and then 404-ing
when somebody presses its button.

Anything unreachable raises `UnknownComponentException` **before `new` is ever
reached** — including a class that lives under an allowed namespace but is not a
`Component`, which is the check that keeps the rule from being "anything under
this prefix".

**What the namespace rule gives up.** It says which *code* is in scope, not that
you meant each class. An internal component sitting in that namespace is
reachable if the secret leaks, where an alias list would not include it. That is
the deliberate trade for not writing down every component, and the reason
`vendor/` is covered by nothing but an explicit `allowNamespace()` — a
third-party component package opts in by name rather than arriving pre-authorised.

What it does not weaken is what actually stops a client. The HMAC means a token
can only ever be replayed, never minted; a client cannot choose the class at all.

Aliases remain worth having: an unaliased component's token carries its
fully-qualified name, which is longer in every action URL.

## Which methods are reachable

An action must be reachable only if the component declared it:

```php
public function actions(): array { return ['increment']; }   // legacy form

#[Post] public function increment(): void { /* … */ }        // attribute form
```

Both are supported and the effective set is their union. On top of that, the
router refuses, unconditionally:

- any method not declared, whatever declared it;
- non-public methods;
- methods inherited from `Component` itself — so a careless `actions()` return
  cannot expose `toHtml()`, `with()` or anything else the framework owns.

**The verb is enforced too.** An action declared `#[Delete]` rejects a POST with
`405` and an `Allow` header. The same attribute generates the `hx-*` attribute
and the router's method check, so the markup and the dispatcher cannot drift
apart.

## CSRF

Component actions verify a session-backed token on every request. htmx does not
submit a form field for a bare `hx-post` on a `<div>`, so the token is forwarded
as a header, inherited document-wide by a single attribute:

```php
<body hx-headers='{"X-CSRF-Token": "…"}'>
```

`hx-headers` is inherited by every descendant, so one declaration in the layout
covers the whole page. A missing or wrong token is a `403`.

**A stateless deployment can still protect actions**, because the token is part
of the signed URL: a request for an action URL cannot be produced without also
producing a valid signature. CSRF adds protection against replay on top.

Route-level CSRF for non-component controllers is available as
`Http\Middleware\VerifyCsrfToken`, which skips safe methods (`GET`, `HEAD`,
`OPTIONS`, `TRACE`).

## Output escaping

User input reaching markup is escaped by default:

```php
$this->hx->p()->text($title);      // escaped
$this->hx->p()->raw($html);        // NOT escaped
```

`Raw` is a deliberate escape hatch and is for values **you** control. It is not
sanitised: a `Raw` containing `</style>` closes the element around it and
everything after is parsed as markup. Wrapping a stylesheet in a `<style>`
element does not make that text safe; it only decides where the text lands.

Never pass request input to `Raw`.

## What is deliberately not here

- **No user storage, and no password policy.** `Auth` knows who is asking and
  stops there. Where users live, how passwords are hashed, when they are
  rotated and what a role means are application decisions with real
  consequences — see [auth/README.md](https://github.com/iam-adty/empira-auth/blob/main/README.md#what-is-deliberately-not-here).
- **No rate limiting, no CORS, no TLS configuration.** Those belong at the edge
  (reverse proxy, load balancer).
- **No audit log.** `Logging` exists but is not wired to authentication. A
  successful and a failed sign-in are exactly the events worth recording, and
  which of them to keep is a decision about retention, not a technical one.

## Reporting a vulnerability

Please **do not open a public issue.** Report privately to the maintainer,
including: what you found, a minimal reproduction, and the impact you believe it
has.

You can expect an acknowledgement within a few days, and an assessment once
reproduced. There is no published disclosure policy yet, so treat anything shared
here as private and do not expect coordinated timing — if a fix is warranted,
assume it ships as soon as it is ready.