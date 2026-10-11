---
name: verify
description: Use before claiming a change works, before committing, or when asked to verify, check, confirm or test the framework. Runs lint, the PHPUnit suite, and a live HTTP smoke test of the demo app against the FrankenPHP worker stack, including CSRF, token forgery, session isolation and fragment rendering.
---

# Verifying the framework

There is **no CI**. The test suite is the only gate, so run it before saying
anything works.

## House rules

**Edit source with the Edit tool.** Not `sed -i`, not a `python3 -c`, not a
heredoc that rewrites a file. Those have no review step, they fail silently on
a pattern that does not match, and they leave a half-applied file when they do.
Shell is for *reading*: `grep`, `find`, `git diff`, `php -l`.

**Put scratch in the workspace `tmp/`.** `tmp/` sits above both packages and is
mounted into every container, so a fixture written there is visible from the host
and from inside the runner. `/tmp` is out — it is outside the working directory,
which means an `external_directory` prompt on every use, and a failed run leaves
its evidence somewhere you will not think to look.

**Never rebuild to test a code change.** The framework's source is bind-mounted
into both the test runner and the app stack, and the worker watches it. Build
once; after that an edit is an edit in the running system. The only things that
need `docker compose build` are listed under [Rebuilding](#rebuilding).

## The four checks

### 1. Lint

```bash
cd principle
find src tests -name '*.php' -print0 | xargs -0 -n1 php -l
```

Every line must read `No syntax errors detected`. Include `bin/empira` — it is
PHP without the extension and has been missed by `find -name '*.php'` before.

### 2. Suite

```bash
cd principle && composer test
```

`790 tests, 1451 assertions` is the current baseline. **If your change adds or
removes tests, update that number in every document that quotes it** —
`AGENTS.md`, `CHECKLIST.md`, `ARCHITECTURE.md`, `docs/testing.md`.

Faster loops — no rebuild, the source is mounted:

```bash
cd principle
vendor/bin/phpunit tests/RoutingTest.php
vendor/bin/phpunit --filter=testACycle
composer test -- --filter=testACycle
```

### 3. The app suite

```bash
cd app && composer test          # 24 tests, 76 assertions
cd app && composer validate      # must be clean
```

### 4. Live smoke, against the worker

A passing suite does not prove the browser path works, and — this is the part
that keeps biting — **it does not prove worker mode works either.** The session
fix passed all 744 tests and was completely broken under a worker: nobody stayed
signed in, because the guard was reading a session nothing wrote to.

**Run from the workspace root**, so `tmp/` is inside the working directory and
the relative paths below mean what they say.

```bash
mkdir -p tmp
JAR=tmp/verify-jar.txt && rm -f "$JAR"
OUT=tmp/verify

cd app
docker compose up -d --wait          # no worker override: this runs at 2 x CPU
cd ..

# the sign-in page, and the signed action its form carries
curl -s -c "$JAR" -o "$OUT.0.html" -w 'GET  /login   %{http_code}\n' http://127.0.0.1:8080/login
CSRF=$(grep -oE 'X-CSRF-Token&quot;:&quot;[a-f0-9]+' "$OUT.0.html" | head -1 | grep -oE '[a-f0-9]{40,}')
ACTION=$(grep -oE 'hx-post="/empira/[^"]+/login"' "$OUT.0.html" | head -1 | cut -d'"' -f2)

# an action round trip — a wrong password must re-render the form
curl -s -b "$JAR" -c "$JAR" -o "$OUT.1.html" -w 'POST action   %{http_code}\n' \
  -X POST "http://127.0.0.1:8080$ACTION" -H 'HX-Request: true' -H "X-CSRF-Token: $CSRF" \
  --data-urlencode 'email=nobody@example.com' --data-urlencode 'password=wrong'
grep -c 'did not match' "$OUT.1.html"    # must be 1

# a fragment must not contain the document
TARGET=$(grep -oE 'id="region-[a-z]+"' "$OUT.0.html" | head -1 | cut -d'"' -f2)
curl -s -b "$JAR" -o "$OUT.2.html" http://127.0.0.1:8080/login \
  -H 'HX-Request: true' -H "HX-Target: $TARGET"
grep -c '<html' "$OUT.2.html"        # must be 0

# the gates — these SHOULD fail
curl -s -o /dev/null -w 'forged token %{http_code}\n' \
  -X POST 'http://127.0.0.1:8080/empira/AAAA.BBBB/login' -H 'HX-Request: true'
curl -s -o /dev/null -w 'no csrf     %{http_code}\n' \
  -X POST "http://127.0.0.1:8080$ACTION" -H 'HX-Request: true'
curl -s -o /dev/null -w 'guest dash  %{http_code}\n' http://127.0.0.1:8080/dashboard
curl -s -o /dev/null -w 'unknown url %{http_code}\n' http://127.0.0.1:8080/nope

# nothing outside public/ is a document
curl -s -o /dev/null -w 'src reachable %{http_code}\n' http://127.0.0.1:8080/src/App.php
```

Expected: `200`, `200`, error `1`, fragment `0`, forged `403`, no-csrf `403`,
guest dashboard `302`, unknown `404`, `src` `404`.

`/login` is used because a guest can reach it, so no session is needed — and
because it exercises the same thing the old `Counter` check did: a
CSRF-protected component action, round-tripped through a real server.

The two `403`s come from different places and it is worth knowing which is
which. A **forged token** fails the HMAC in `TokenCodec`. A **missing token**
fails `Csrf::verify()` inside `Component::beforeAction()`, which throws
`ForbiddenActionException`. The `419` belongs to `VerifyCsrfToken`, the
middleware for ordinary route posts — and the demo app does not register it
globally, so you will not see a `419` here. If you ever do, something *is*
registered globally.

**Keep the cookie jar.** Without it each request gets a fresh session and a fresh
CSRF token, which looks exactly like a broken guard.

### 5. The session-isolation check

The one that catches the bug class that matters. It runs at the **real default**,
2 × CPU workers, because that is what ships — but read this before trusting a
green result.

**A green run is not proof of isolation.** A per-worker state leak only shows
itself when two consecutive requests for one session land on the *same* worker,
which at 32 workers is about 1 chance in 32. Ten rounds catch roughly a quarter
of such a bug; they do not eliminate it. Anything that would fail here
intermittently in production will usually pass here.

When a state bug is suspected, make this check deterministic:

```bash
cd app && FRANKENPHP_WORKERS=1 docker compose up -d --force-recreate --wait && cd ..
```

One worker makes every request share a process, so a leak cannot hide.

```bash
JAR=tmp/verify-session.txt && rm -f "$JAR"
B=http://127.0.0.1:8080

PAGE=$(curl -s -c "$JAR" "$B/login")
TOKEN=$(printf '%s' "$PAGE" | grep -oE 'X-CSRF-Token&quot;:&quot;[a-f0-9]+' | head -1 | grep -oE '[a-f0-9]{40,}')
URL=$(printf '%s' "$PAGE" | grep -oE 'hx-post="/empira/[^"]+/login"' | head -1 | cut -d'"' -f2)

curl -s -o /dev/null -b "$JAR" -c "$JAR" -X POST "$B$URL" \
  -H 'HX-Request: true' -H "X-CSRF-Token: $TOKEN" \
  --data-urlencode 'email=ada@example.com' \
  --data-urlencode 'password=correct horse battery staple'

for i in $(seq 1 10); do
  a=$(curl -s -b "$JAR" -o /dev/null -w '%{http_code}' "$B/dashboard")
  b=$(curl -s -o /dev/null -w '%{http_code}' "$B/dashboard")
  echo "round $i   signed-in:$a   anonymous:$b"
done
```

Expected: `signed-in:200   anonymous:302`, ten times. Anything else — and in
particular a `200` for the anonymous request — is one visitor being served
another's session, which is the failure this whole gate exists to catch.

Tear down:

```bash
cd app && docker compose down && cd ..
rm -f tmp/verify-jar.txt tmp/verify-session.txt tmp/verify.*.html
```

Leave `tmp/.boot.lock` alone — it is the SQLite boot lock sitting beside the
database it guards, not a leftover.

## Rebuilding

Build once. After that these are the **only** changes that need it:

- a `composer.json` in either package — dependencies or an autoload path
- a change to the Dockerfile or `docker/entrypoint.sh`
- the first run on a fresh clone

Everything else — any PHP file under either package — is mounted and watched. If
you find yourself running `docker compose up --build` to check a code change,
something is wrong with the mounts, not with your change.

## Prove the test can fail

The strongest check available, and it takes ten seconds:

```bash
cd principle
git stash
vendor/bin/phpunit tests/YourTest.php     # must FAIL
git stash pop
vendor/bin/phpunit tests/YourTest.php     # must PASS
```

`git stash` asks for permission, deliberately: it discards work, and a stray one
should never be a keystroke away. `git stash pop` has failed before.

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

## Before committing

```bash
cd principle && composer test
cd app && composer test && composer validate
find src tests -name '*.php' -print0 | xargs -0 -n1 php -l
ls -A ../tmp          # nothing but .boot.lock
git status            # nothing unexpected, no vendor
```

If you touched `app/`, run its suite too — it is the integration test for the
framework.

## Reference

- `docs/testing.md` — conventions and fixtures
- `docs/workers.md` — one boot, many requests, and what is reset between them
- `CONTRIBUTING.md` — the full pre-commit checklist
- `AGENTS.md` — known traps
