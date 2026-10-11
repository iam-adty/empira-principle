# Auth

> **Status: describes shipped code.** Verified against `746 tests, 1376
> assertions`.

The framework knows **who is asking**. It does not know what a user is, where
users are stored, or what a password is — and it deliberately does not care.

Three interfaces, kept apart on purpose:

| | Answers |
|---|---|
| `Auth\Guard` | who is making *this* request? |
| `Auth\UserProvider` | which user is that id, and is this password theirs? |
| `Auth\Auth` | the guards, asked once per request |

## Wiring it

```php
$app->auth(
    new SessionGuard($session, $users),
    new BearerGuard(fn(string $token) => $users->byApiToken($token)),
);
```

Guards are consulted in order and the first user wins. Nothing happens until
`auth()` is called, so an application with no users pays nothing.

## Asking

```php
public function __construct(private readonly Auth $auth) {}

$this->auth->user();    // User|null
$this->auth->check();   // bool
$this->auth->id();      // int|string|null
$this->auth->guest();   // bool
```

`Auth` is bound **per request**, not once at boot. That is the reason it needs
no `reset()`: a fresh instance cannot remember the request before it. A
singleton would need resetting before every dispatch, and a reset someone
forgets is a request that runs as the user before it — the same failure this
codebase has already produced twice, for route parameters and for flash
messages.

## Requiring a user

```php
$app->use(RequireAuth::class);                                     // everywhere
$routes->group('/admin', $routes, middleware: [RequireAuth::class]); // a group
#[Route('/admin', middleware: [RequireAuth::class])]                // one route
```

It **throws** `HttpException::unauthorized()` rather than returning a 401.
Middleware runs inside `Application::handle()`'s try block, so a throw is
rendered by the same code as every other failure:

```
GET /admin                 → 401, the onHttpError view
GET /admin  Accept: json   → 401, {"error":{"status":401,"message":"…"}}
```

Returning a `Response` would mean copying that envelope a third time, and the
first copy that drifts is the one nobody tests.

With no `Auth` bound — a middleware built by hand, say — it refuses. Auth fails
closed.

## The guards

### SessionGuard

The session cookie is an **ambient** credential: the browser attaches it to
every request to this site without being asked.

```php
$auth->login($user);   // rotates the session id, then stores the id
$auth->logout();
```

The rotation is not decoration. Without it, an id an attacker planted before
the victim signed in is still valid afterwards — session fixation.
`Session::regenerate()` is a no-op under the CLI, so a test watching the id
change would pass whether or not anything happened; `SessionGuard` therefore
takes a `SessionId` and the test asserts the rotation was *requested*.

### BearerGuard

An `Authorization: Bearer …` header. What the token means is the
application's business, so the check is a closure:

```php
new BearerGuard(fn(string $token): ?User => $users->byApiToken($token));
```

The token is never logged and never quoted in an exception — a message
containing the credential puts it in a log file.

## Users

```php
$users = new DatabaseUserProvider($db);            // a users table

$user = $users->byId(7);
$user = $users->byCredential('ada@example.com');
$users->verifyPassword($user, 'correct horse');
$users->hash('correct horse');                     // to store one
```

`password_hash` and `password_verify` are PHP's own, so this stays
zero-dependency. A user with no stored hash fails to authenticate — a missing
column must not read as "any password works".

The hash never leaves the provider: `User` is built with `password_hash`
removed, so a controller cannot log it by accident.

**`UserProvider` is the ORM seam.** A `SessionGuard` sits on a database
provider today and would sit on an Eloquent one tomorrow without auth noticing,
because both answer the same two methods and nothing else knows how a user is
found.

## CSRF and what is exempt

Read [docs/http.md](http.md#csrf) for the mechanism. What matters here is
*which* requests are exempt, and the answer is not "requests with a user".

CSRF defends **ambient** credentials — cookies the browser attaches by itself.
Two kinds of request are therefore not a CSRF problem:

1. **A valid bearer token.** An `Authorization` header is not ambient: a
   cross-site page cannot set one without a CORS preflight this server has to
   approve. There is nothing to forge.
2. **A route that says so** — `#[Route('/webhook', csrf: false)]`, or
   `$routes->post('/webhook', $handler, csrf: false)`.

A request with a session cookie, a user, and no token has a user and **is still
exactly the forgery CSRF exists for**. So `Auth` reports *which* guard answered,
not merely that one did, and there is a test that fails if anyone widens the
check to "any authenticated user".

The route flag is read by the CSRF middleware itself. It cannot be read by a
route middleware, because that runs *inside* the check — by the time it could
report anything, the request has already been refused.

## What is deliberately not here

- **Password hashing policy, registration, reset, email verification.** Those
  are application decisions with real consequences, and a framework that
  guesses them is a framework that gets them wrong.
- **Rate limiting on `login()`.** Belongs at the edge; see [SECURITY.md](../SECURITY.md).
- **Roles and permissions.** `User::$attributes` is yours; the framework does
  not know what an administrator is.

## Further reading

- [SECURITY.md](../SECURITY.md) — the trust boundaries
- [docs/data.md](data.md) — where the users come from
- [docs/routing.md](routing.md) — attaching middleware to a route
