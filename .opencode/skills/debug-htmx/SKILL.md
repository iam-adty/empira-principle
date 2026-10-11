---
name: debug-htmx
description: Use when an htmx swap does not happen, does not fire, or updates the wrong element — 403 on a component action, a missing or stale CSRF header, an hx-target pointing nowhere, an hx-swap that replaces nothing, a page rendering unstyled, or a component whose markup is escaped and visible as angle brackets.
---

# Debugging an htmx swap

htmx failures are usually one of five things. Check them in this order — it is
the order of how often each is the cause.

## 1. Nothing fires at all

**Is the element wired?** Look at the rendered markup:

```bash
curl -s localhost:8000/ | grep -oE 'hx-[a-z]+="[^"]*"'
```

Every verb must point at a signed endpoint:

```
hx-post="/empira/eyJ2IjoxLCJjIjoi…"   ✓
hx-post="/some/literal/url"            ✗ — not a signed endpoint
```

If there is no `hx-*` attribute, the element was never wired. `->action('name')`
resolves through the component; `->hxPost($url)` does not.

**Check the console.** htmx logs why it did not issue a request.

## 2. 403 — the guard refused it

The three ways this happens, in order of likelihood:

### Missing CSRF token

```html
<body hx-headers='{"X-CSRF-Token": "…"}'>
```

`hx-headers` is **inherited** by descendants, so one attribute on `<body>` covers
the page. If it is missing, every action 403s.

```bash
# does the page carry one?
curl -s localhost:8000/ | grep -o 'hx-headers="[^"]*"'
```

### Stale or wrong session

The token lives in the session. Reproduce with a cookie jar:

Scratch goes in the workspace `tmp/`, not `/tmp` — it sits above both packages
and is mounted into every container, so a failed reproduction leaves its
evidence where you will look for it. Run from the **workspace root**:

```bash
mkdir -p tmp
JAR=tmp/htmx-j.txt && rm -f "$JAR"
curl -s -c "$JAR" -o tmp/page.html localhost:8000/login
CSRF=$(grep -oE 'X-CSRF-Token&quot;:&quot;[a-f0-9]+' tmp/page.html \
       | head -1 | grep -oE '[a-f0-9]{40,}')
curl -s -b "$JAR" -c "$JAR" -X POST "http://localhost:8000/<action-url>" \
  -H 'HX-Request: true' -H "X-CSRF-Token: $CSRF"
```

Without the jar each request gets a fresh session and therefore a fresh token —
this looks exactly like a broken CSRF implementation when it is only a test
harness mistake.

### Invalid or forged token

```bash
curl -s -o /dev/null -w '%{http_code}\n' -X POST \
  'http://localhost:8000/empira/AAAA.BBBB/login' -H 'HX-Request: true'
# 403 is CORRECT — the signature does not verify
```

A 403 here means the guard is working. Do not "fix" it.

## 3. Fires, but nothing changes

**Wrong target.** `hx-target` points at an id that does not exist:

```bash
curl -s localhost:8000/ | grep -oE 'hx-target="[^"]*"' | sort -u
```

Each id must appear exactly once in the document. The framework derives the
default from the component's own root element; a component that names its own
root id is followed correctly, but a mismatch is silent.

**Boosted request.** `hx-boost` sends `HX-Boosted`, and the framework correctly
treats it as a real navigation — it returns the **whole document**, not a
fragment. That looks like "the swap did nothing" when it is correct behaviour.

## 4. Wrong verb

```bash
curl -s -i -X POST localhost:8000/<delete-url> | head -1
# HTTP/1.1 405 Method Not Allowed
# Allow: DELETE
```

An action's declared verb drives both the generated attribute and the router's
check, so they cannot drift — a 405 means you reached a different action than the
one whose URL you copied.

## 5. The markup is escaped and visible

```
&lt;div class=&quot;counter&quot;&gt;
```

**The component returned a bare string.** A string appended to a parent is
*escaped*. `Component::markup()` is typed `Element|Raw` so this is a type error —
if you are seeing it, something bypassed that, usually a `render()` returning a
string that was stringified upstream.

Return an `Element`.

## Bonus: the page renders unstyled

```bash
curl -s localhost:8000/ | grep -c '<style>'
# 0 = the stylesheet is being emitted as bare text
```

`Layout::styles()` returning `Raw` is wrapped in `<style>` automatically. If you
see the CSS as naked text inside `<head>`, a browser hoists it out and discards it
and the page renders unstyled **with a 200 and nothing in the console**. This
shipped once. Check `src/View/Layout.php`.

## A fast harness

Either server works; pick by what you are chasing. From the **workspace root**:

```bash
# The worker stack — what the app actually runs on, and live: the source is
# mounted and watched, so an edit is an edit without a rebuild. 2 × CPU workers.
cd app && docker compose up -d --wait && cd ..
B=http://127.0.0.1:8080

# …or the built-in server, if you want no Docker at all. Classic mode, one
# request per process, and **not** a substitute for the worker when the bug is
# about state carrying between requests.
B=http://127.0.0.1:8000
(cd app && php -S 127.0.0.1:8000 -t public public/index.php &)
```

**If the bug is state carrying between requests, force one worker.** At 2 × CPU
a leak only shows up when two consecutive requests for one session land on the
same worker — about 1 in 32 — so it will hide from you exactly when you are
looking for it:

```bash
cd app && FRANKENPHP_WORKERS=1 docker compose up -d --force-recreate --wait && cd ..
```

One worker makes every request share a process, so the symptom cannot hide. Put
it back afterwards without the variable.

```bash
mkdir -p tmp
JAR=tmp/htmx-j.txt && rm -f "$JAR"
PAGE=tmp/htmx-page.html

# a full page, a fragment, then an action — against /login, which a guest
# can reach without a session
curl -s -c "$JAR" -o "$PAGE" "$B/login"
CSRF=$(grep -oE 'X-CSRF-Token&quot;:&quot;[a-f0-9]+' "$PAGE" | head -1 | grep -oE '[a-f0-9]{40,}')
URL=$(grep -oE 'hx-post="/empira/[^"]+/login"' "$PAGE" | head -1 | cut -d'"' -f2)

# the target is a component's own id, which the page renders for us
TARGET=$(grep -oE 'id="e-[a-f0-9]+"' "$PAGE" | head -1 | cut -d'"' -f2)
curl -s -b "$JAR" "$B/login" -H 'HX-Request: true' -H "HX-Target: $TARGET"
curl -s -b "$JAR" -c "$JAR" -X POST "$B$URL" \
  -H 'HX-Request: true' -H "X-CSRF-Token: $CSRF"
```

A fragment response must contain **no `<html`**.

## Report a guard failure as a success

`403` for a forged token, `405` for the wrong verb, and `404` for an unregistered
class are all **the framework working**. Reproduce those before debugging a real
breakage, so you know what correct looks like.

## Reference

- `SECURITY.md` — what the guards protect and why
- `docs/http.md` — htmx headers both directions
- `docs/components.md` — actions and verbs