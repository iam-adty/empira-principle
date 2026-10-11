---
name: verify
description: Use before claiming a change works, before committing, or when asked to verify, check, confirm or test the framework. Runs lint, the PHPUnit suite, and a live HTTP smoke test of the demo app including CSRF, token forgery, and fragment rendering.
---

# Verifying the framework

There is **no CI**. The test suite is the only gate, so run it before saying
anything works.

## The three checks

### 1. Lint

```bash
cd principle
find src tests -name '*.php' -print0 | xargs -0 -n1 php -l
```

Every line must read `No syntax errors detected`. Include `bin/empira` — it is
PHP without the extension and has been missed by `find -name '*.php'` before.

### 2. Suite

```bash
composer test
```

`616 tests, 1117 assertions` is the current baseline. **If your change adds or
removes tests, update that number in every document that quotes it** —
`AGENTS.md`, `CHECKLIST.md`, `docs/testing.md`.

Faster loops:

```bash
vendor/bin/phpunit tests/RoutingTest.php
vendor/bin/phpunit --filter=testACycle
```

### 3. Live smoke

A passing suite does not prove the browser path works.

```bash
cd app
php -S 127.0.0.1:8801 -t public public/index.php &
sleep 2

JAR=/tmp/verify.txt && rm -f $JAR

# the page
curl -s -c $JAR -o /tmp/v0.html -w 'GET /          %{http_code}\n' http://127.0.0.1:8801/
CSRF=$(grep -oE 'X-CSRF-Token&quot;:&quot;[a-f0-9]+' /tmp/v0.html | head -1 | grep -oE '[a-f0-9]{40,}')
ACTION=$(grep -oE 'hx-post="[^"]*/increment"' /tmp/v0.html | head -1 \
         | sed 's/hx-post="//; s/"$//' | sed 's/&amp;/\&/g')

# an action round trip
curl -s -b $JAR -c $JAR -o /tmp/v1.html -w 'POST action   %{http_code}\n' \
  -X POST "http://127.0.0.1:8801$ACTION" -H 'HX-Request: true' -H "X-CSRF-Token: $CSRF"

# a fragment must not contain the document
curl -s -b $JAR -o /tmp/v2.html http://127.0.0.1:8801/ \
  -H 'HX-Request: true' -H 'HX-Target: region-counter'
grep -c '<html' /tmp/v2.html        # must be 0

# the guards — these SHOULD fail
curl -s -o /dev/null -w 'forged token %{http_code}\n' \
  -X POST 'http://127.0.0.1:8801/empira/AAAA.BBBB/increment' -H 'HX-Request: true'
curl -s -o /dev/null -w 'no csrf     %{http_code}\n' \
  -X POST "http://127.0.0.1:8801$ACTION" -H 'HX-Request: true'
curl -s -o /dev/null -w 'unknown url %{http_code}\n' http://127.0.0.1:8801/nope
```

Expected: `200`, `200`, fragment `0`, forged `403`, no-csrf `403`, unknown `404`.

**Keep the cookie jar.** Without it each request gets a fresh session and a fresh
CSRF token, which looks exactly like a broken guard.

Stop the server when done:

```bash
pkill -f 'php -S 127.0.0.1:8801'
```

## Prove the test can fail

The strongest check available, and it takes ten seconds:

```bash
git stash
vendor/bin/phpunit tests/YourTest.php     # must FAIL
git stash pop
vendor/bin/phpunit tests/YourTest.php     # must PASS
```

A test that has never been seen red might not be testing anything.

## Watch for vacuous assertions

```php
// WRONG — passes on any page containing "htmx"
$this->assertStringContainsString('htmx', $body);

// RIGHT — can only pass if the behaviour worked
$this->assertStringContainsString('<li>multitail</li>', $body);
```

This exact mistake shipped: it matched `class="htmx-indicator"` and passed
regardless of what the search returned. **Ask what the opposite behaviour would
produce.**

## For the application repo

```bash
cd app && composer test          # 12 tests, 33 assertions
cd app && composer validate      # must be clean
```

## Before committing

```bash
composer test
composer validate
find src tests -name '*.php' -print0 | xargs -0 -n1 php -l
git status                       # nothing unexpected, no vendor
```

If you touched `app/`, run its suite too — it is the integration test for the
framework.

## Reference

- `docs/testing.md` — conventions and fixtures
- `CONTRIBUTING.md` — the full pre-commit checklist
- `AGENTS.md` — known traps