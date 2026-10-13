# Contributing

> **Status: describes shipped code.** Conventions below are what the code
> actually does; new code is expected to match.

Thanks for looking. This is a young framework, and the test suite is still the
gate — there is no CI, so `composer test` before claiming something works. It
runs on the host PHP against SQLite, so there is nothing to start and nothing to
install but `pdo_sqlite`; `composer test` refuses to run without it rather than
reporting a pass with the persistence tests never having run.

## Setup

```bash
git clone <repo> && cd principle
composer install
composer test
```

Requires PHP 8.4 or newer. There are **no runtime dependencies**; phpunit is the
only dev dependency.

## The loop

```bash
composer test                                  # whole suite (~0.6s)
vendor/bin/phpunit tests/RoutingTest.php       # one file
vendor/bin/phpunit --filter=testACycle         # one behaviour
find src tests -name '*.php' -print0 | xargs -0 -n1 php -l
```

The suite is fast enough that there is no excuse for not running it.

## Style

The codebase is consistent about a few things. Matching them is easier than
reviewing against them.

- `declare(strict_types=1);` first, always.
- `final class` or `enum`. An interface only where there is genuinely more than
  one implementation (`StateStore`, `Logger`, `Middleware`).
- Constructor property promotion; `private readonly` when the value never changes.
- **Docblocks carry the explanation; comments do not.** Write *what and why* in
  the docblock. Add an inline comment only where a single line is genuinely
  opaque.
- Typed PHPDoc on anything array- or generic-shaped: `@param array<string, mixed>`.
- Class docblocks in the author's voice: plain, opinionated, one short example,
  no marketing.
- No emojis.

```php
/**
 * Signs component identity into an opaque, tamper-proof token.
 *
 * The token is `base64url(payload).base64url(hmac)` where the payload carries
 * the component class, the instance nonce and — unless the component keeps its
 * state on the server — its props. Because the signature covers the props,
 * a client cannot promote itself by editing values in the URL.
 */
final class TokenCodec
```

## Tests

Every change ships with a test that **fails without the change**. Write it first
if that is easier; the point is that you have proved it can fail.

```php
final class RouteTest extends TestCase
{
    public function testTheFirstMatchingRouteWins(): void
    {
        // ...
        $this->assertSame('new', $response->body());
    }
}
```

- Names read as behaviour: `testATypedPropertyMutatesAcrossRequests`.
- `assertSame` over `assertEquals`.
- Prefer a real round trip — render markup, extract the signed URL from it, post
  to that, assert on the returned fragment — over asserting on markup by hand.

**Beware the vacuous assertion.** A test in this repo once asserted
`assertStringContainsString('htmx', $body)`, which passed by matching
`class="htmx-indicator"` and would have passed whatever the code under test did.
When you write an assertion, ask what the *opposite* behaviour would produce. If
the answer is "the same string", the assertion is worthless.

## Adding a component

```php
namespace App\Components;

use Empira\Attributes\Post;
use Empira\Attributes\Prop;
use Empira\Component;
use Empira\Element;

final class Counter extends Component
{
    #[Prop] public int $count = 0;

    #[Post]
    public function increment(): void
    {
        $this->count += 5;
    }

    protected function render(): Element
    {
        return $this->hx->div('#counter')
            ->append($this->hx->button('+5')->action('increment'))
            ->append($this->hx->strong()->text((string) $this->count));
    }
}
```

Then **make it reachable**. A token cannot resolve a class the registry does
not know — and if the application called `$app->components(__NAMESPACE__)`,
which most do, the class is already in scope and there is nothing to do. An
`alias()` is for a token that should be short or rename-safe:

```php
$app->registry()->alias('counter', Counter::class);
```

`php bin/empira make:component Counter` writes the skeleton and prints that line.

## Commits

- One logical change per commit. A refactor and a behaviour change do not travel together.
- Explain *why*, not *what* — the diff already says what.
- If a change fixes a bug, say what the bug was and how you proved it.

```
Fix group middleware being attached but never executed

Group-level middleware was collected onto the Route but the dispatcher
never wrapped the handler in it, so an auth rule on /admin silently did
nothing. Caught by asserting /closed returns 403.
```

## Documentation

Documentation must never describe software that does not exist.

- `docs/` and [ARCHITECTURE.md](ARCHITECTURE.md) describe **shipped** behaviour only.
- Intended work goes in [SPEC.md](https://github.com/iam-adty/empira-dev/blob/main/planning/SPEC.md), marked `[planned]`.
- When something ships: flip its marker, move its behaviour into the reference docs, add it to [CHANGELOG.md](CHANGELOG.md), remove it from [SPEC.md](https://github.com/iam-adty/empira-dev/blob/main/planning/SPEC.md).

The current test count appears in several documents. **If you add tests, update
them.**

## Pull requests

1. `composer test` passes.
2. A test exists that fails without your change.
3. `php -l` is clean on everything you touched.
4. Public API changes have a docblock saying why.
5. Documentation updated if behaviour changed.

## Security

Do not open a public issue for a vulnerability. See [SECURITY.md](SECURITY.md).