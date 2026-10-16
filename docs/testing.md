# Testing

> **Status: describes shipped code.** `542 tests, 1208 assertions`.

## Running

```bash
composer test                                  # everything (~0.6s)
vendor/bin/phpunit tests/RoutingTest.php       # one file
vendor/bin/phpunit --filter=testACycle         # one behaviour
```

No database **server**, no network, no fixtures to load. The suite is fast because
it never starts one — and it used to, against PostgreSQL, for five tests out of
four hundred and eighty.

Persistence is still exercised for real: those tests open an actual SQLite file
and run Doctrine over it, because asserting that an ORM works is the whole point
of having one. The files go in the workspace `tmp/` and are removed afterwards;
`ls -A tmp` coming back empty is how you know a run finished.

## TestClient

The fastest way to drive an application:

```php
use Empira\Testing\TestClient;

$client = new TestClient($app);

$client->get('/todos')
    ->assertOk()
    ->assertSee('buy milk');

$client->asHtmx()->post('/empira/<token>/add', ['title' => 'walk dog'])
    ->assertSee('walk dog');

$client->get('/nope')->assertNotFound();
```

| Method | Purpose |
|---|---|
| `get/post/put/patch/delete($uri, $body, $headers)` | set up a request |
| `withHeader($name, $value)` | header for the next request only |
| `asHtmx()` | adds `HX-Request: true` |
| `request()` | send it, returns a `TestResponse` |

`TestResponse` asserts and returns `$this`, so they chain: `assertOk()`,
`assertNotFound()`, `assertForbidden()`, `assertStatus()`, `assertSee()`,
`assertDontSee()`, `assertHeader()`. They delegate to PHPUnit's `Assert`, so
failures are real `AssertionFailedError`s that count in the runner.

**CSRF is handled.** A POST through the client attaches a valid token unless you
supply your own — verified against `Csrf::isValid()` rather than assumed. If you
want to test rejection, pass a wrong token explicitly.

**A query string in the URI is parsed.** `get('/todos?q=milk')` puts `q` in
`Request::$query`, matching what `Request::capture()` does from a real request.

## Driving it by hand

When you want the raw `Response`:

```php
$app->handle(new Request('GET', '/'));

$app->handle(new Request('POST', $actionUrl, body: ['title' => 'x'], headers: [
    'HX-Request' => 'true',
    'X-CSRF-Token' => $csrf->token(),
]));
```

**Prefer a real round trip.** The pattern this codebase uses most is: render a
page, extract the signed URL from its own markup, post to that, assert on the
returned fragment.

```php
$html = (string) $app->render(Counter::class)->render();

preg_match('/hx-post="([^"]*increment)"/', $html, $m);
$url = html_entity_decode($m[1], ENT_QUOTES);

$fragment = $app->handle(new Request('POST', $url))->body();

$this->assertStringContainsString('<strong>5</strong>', $fragment);
```

That exercises the signing, the routing and the rendering together — if wiring
breaks, the test fails without anyone asserting on markup by hand.

## Fixtures

Shared fixtures live in `tests/Fixtures/Components.php` and are loaded via
`classmap` in `autoload-dev`.

| Fixture | Exercises |
|---|---|
| `CounterFixture` | a stateless component; props travel in the token |
| `NamedRootFixture` | a component that names its own root element |
| `StatefulFixture` | props in a `StateStore` |
| `BadActionFixture` | a private method declared as an action — must be refused |
| `UnregisteredFixture` | proves nothing is constructed for an unknown class |
| `ChattyFixture` | a component that sets response headers |
| `TypedPropsFixture` | `#[Prop]` typed state and coercion |
| `VerbsFixture` | one action per HTTP verb |
| `MixedActionsFixture` | attribute and `actions()` declarations together |
| `MemoryStore` | an in-memory `StateStore`, so stateful tests need no session |

## Conventions

- `final class XTest extends TestCase`, in `namespace Empira\Tests;`
- Method names read as behaviour: `testATypedPropertyMutatesAcrossRequests`
- `assertSame` over `assertEquals`
- Fixtures are defined at the **bottom of the test file**, or in `Fixtures/` when shared.

## Write a test that can fail

**A test that cannot fail is worse than no test**, because it is trusted.

This one passed for months while asserting nothing:

```php
// WRONG — "htmx" also matches class="htmx-indicator" in the layout
$this->assertStringContainsString('htmx', $response->body());
```

It would have passed whether the search returned one hit or none. The correct
version asserts the thing that can only appear if the behaviour worked:

```php
$this->assertStringContainsString('<li>multitail</li>', $response->body());
$this->assertStringContainsString('<ul id="results"></ul>', $noMatch->body());
```

**Before committing, ask: what would the opposite behaviour produce?** If the
answer is "the same string", the assertion is worthless.

Prove it: revert your fix and confirm the test goes red.

```bash
# the strongest check a regression test can pass
git stash && vendor/bin/phpunit tests/LayoutTest.php   # must fail
git stash pop && vendor/bin/phpunit tests/LayoutTest.php   # must pass
```

## Hooks without tests are bugs waiting

`Layout::styles()` appended its return value straight into `<head>`. Returning
raw CSS emitted naked text that browsers hoist away and discard — the app
rendered unstyled, returned `200`, and nothing caught it for the life of the
feature. `src/View/` had no test file at all.

**Add the test with the feature.**

## Temporary directories

Scratch files go in the **workspace** `tmp/` — the folder above `principle/` and
`app/` — and not in `sys_get_temp_dir()`. `Tests\Temp` creates it and hands out
a unique name each time:

```php
$path = Temp::file('empira-env');      // a unique file in tmp/
$directory = Temp::dir('empira-log-'); // a unique directory, created
```

`Temp::root()` is `dirname(__DIR__, 2) . '/tmp'` — two levels up from `tests/`,
because the folder sits above the package rather than inside it. Both
containers mount the workspace at `/workspace`, so the container's layout
matches the host's exactly and the same relative path resolves in all three
places. No environment variable, and no mount kept in step with it by hand.

That location buys three things over `/tmp`:

- A failing run leaves its fixtures in the workspace, where you would look.
- Both suites share one folder, so a stray file is traceable to whichever ran.
- It lives outside both Git repositories, so a fixture **cannot** be committed.
  The old per-package `tmp/.gitignore` made the same promise by declaration;
  this one holds by construction.

`finally` still matters, or a failing assertion leaves the file behind:

```php
try {
    // …
} finally {
    Temp::remove($path);
}
```

`Temp::remove()` is recursive, because a fixture is often a tree — a logger
creating its own parent directories, say. It is also how the suite keeps
`tmp/` empty: if something is left there after a run, a test failed partway and
that is the clue.

One file legitimately survives — `.boot.lock`, the lock the demo application
takes around migrations and seeding, sitting beside the database it guards. It is
not a leftover.

## Without a session

There is no session under the CLI, so `Csrf` and `SessionState` fall back to an
in-process `$_SESSION` array that persists for the life of the process. Reset it
between tests that should not see each other's state:

```php
protected function setUp(): void
{
    $_SESSION = [];
}
```

## Further reading

- [../CONTRIBUTING.md](../CONTRIBUTING.md) — the full pre-commit checklist
- [AGENTS.md](https://github.com/iam-adty/empira-dev/blob/main/agents/principle.md) — known traps