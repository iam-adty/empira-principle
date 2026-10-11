# Layouts and regions

> **Status: describes shipped code.** Verified against `tests/LayoutTest.php`.

A layout is the page shell — `<html>`, `<head>`, the htmx script, the CSRF header.
Inside it are **regions**: the areas htmx swaps.

## One route, two responses

The same route serves a browser and htmx, and the handler does not know which:

```php
$app->routes()->get('/', fn(Application $app) => $app->view(Layout::class), 'home');
```

- A normal visit gets the **whole document**.
- A request carrying `HX-Request` gets **one region** — the one named by
  `HX-Target`, or the first if none is named.
- A **boosted** request (`hx-boost`) gets the whole document, because it is a
  real navigation.

```php
public function isFragment(): bool
{
    return $this->request->isHtmx() && !$this->request->isBoosted();
}
```

## A layout

```php
namespace App;

use App\Components\Counter;
use IamAdty\Empira\Element;
use IamAdty\Empira\Raw;
use IamAdty\Empira\View\Layout as BaseLayout;
use IamAdty\Empira\View\Region;

final class Layout extends BaseLayout
{
    protected function title(): string
    {
        return 'Empira demo';
    }

    protected function header(): Element
    {
        return $this->hx->main()->append(
            $this->hx->h1('Empira'),
            $this->hx->p()->class('lede')->text('htmx lives in PHP classes.'),
        );
    }

    protected function regions(): array
    {
        return [
            'counter' => fn(Region $region): mixed => $region
                ->element('section')
                ->class('card')
                ->append($region->hx()->h2('Counter'), $region->component(Counter::class)),

            'todos' => fn(Region $region): mixed => $region
                ->element('section')
                ->class('card')
                ->append($region->hx()->h2('Todos'), $region->component(TodoList::class)),
        ];
    }

    protected function styles(): Element|Raw
    {
        return Raw::of('body { font: 16px system-ui; }');
    }
}
```

### Hooks

| Hook | Purpose |
|---|---|
| `regions()` | **required** — `name => callable(Region): (Element\|Raw\|null)` |
| `header()` | markup above the regions; `null` for none |
| `styles()` | the stylesheet; see below |
| `title()`, `lang()`, `htmxUrl()` | document metadata |
| `head()` | override to take over `<head>` entirely |

### `styles()` — the `<style>` contract

Return the stylesheet as `Raw` and the framework **wraps it in `<style>`** for
you:

```php
protected function styles(): Element|Raw { return Raw::of($css); }   // → <style>…</style>
```

Return an `Element` and it is used **as-is**, which is how you emit a
`<link rel="stylesheet">` or two separate `<style>` blocks. Returning an empty
string emits no element.

Getting this wrong is invisible until the page renders unstyled: appending raw
CSS straight into `<head>` produces naked text that a browser hoists away and
discards, with a `200` and nothing in the console.

> `Raw` bypasses escaping entirely. A stylesheet containing `</style>` closes the
> element around it. Wrapping decides where the text lands, not whether it is
> safe — see [SECURITY.md](../SECURITY.md).

## Regions

`Region` is the swappable unit. It gives its element a stable id so generated
`hx-*` attributes have a target, and offers the shorthands a page body needs.

```php
$region->element('section')                  // root element, id="region-<name>"
$region->component(Counter::class, ['x' => 1])
$region->list($todos, fn($t) => $t['title'])
$region->oob($badge)                         // out-of-band swap
$region->lazy('/slow/panel')                 // fetch after the page settles
$region->hx()                                // the element factory
$region->data('q', '')                       // data passed to view()
```

### Out-of-band swaps

`oob()` marks part of the response to be applied **outside** the swap target —
how one action updates a badge and a list at once.

```php
'counter' => fn(Region $r) => $r
    ->element('section')
    ->append($r->component(Counter::class))
    ->append($r->oob($this->hx->span()->id('total')->text('3'))),
```

### Lazy regions

```php
'analytics' => fn(Region $r) => $r->lazy('/api/analytics'),
```

Emits an element that fetches itself on `load`, so slow content does not block
the page around it.

## Passing data

```php
$app->view(Layout::class, ['q' => 'milk']);

// inside the layout
protected function title(): string
{
    return $this->data('title', 'Empira');   // or $this->data['title']
}
```

## CSRF belongs in the layout

One attribute on `<body>` is inherited by everything below it, which is how the
token reaches every component action without repeating itself:

```php
// the framework does this for you when a Csrf guard is present
<body hx-headers='{"X-CSRF-Token": "…"}'>
```

## Unknown region targets

htmx asking for a region that no longer exists is **not** an error worth a 500 —
the framework falls back to the first region rather than returning a stack trace.

## Further reading

- [docs/components.md](components.md) — what lives inside a region
- [../ARCHITECTURE.md](../ARCHITECTURE.md) — where views sit
- [../tests/LayoutTest.php](../tests/LayoutTest.php) — worked examples